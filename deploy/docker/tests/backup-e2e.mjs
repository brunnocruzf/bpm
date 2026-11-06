#!/usr/bin/env node
// Teste ponta a ponta do backup (ADR-0021) em uma stack DESCARTÁVEL, com PostgreSQL, Flowable e Azurite de verdade.
// Nunca aponte para homologação ou produção: ele restaura bancos e apaga volumes.
//
//   node deploy/docker/tests/backup-e2e.mjs            sobe bpm-drtest, roda tudo e apaga a stack
//   node deploy/docker/tests/backup-e2e.mjs --keep     mantém a stack no fim, para investigar
//   node deploy/docker/tests/backup-e2e.mjs --reuse    reaproveita a stack e as imagens já criadas
import { execFileSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const PROJECT = 'bpm-drtest'
const PORT = 18081
const AZURITE_PORT = 18100
// chave pública documentada do Azurite
const AZURITE_KEY = 'Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw=='
const deploy = join(dirname(fileURLToPath(import.meta.url)), '..')
const API = `http://127.0.0.1:${PORT}/api/v1`
const keep = process.argv.includes('--keep')
const reuse = process.argv.includes('--reuse')
const work = reuse && process.env.BPM_E2E_DIR ? process.env.BPM_E2E_DIR : process.env.BPM_E2E_DIR ?? mkdtempSync(join(tmpdir(), 'bpm-drtest-'))
const envFile = join(work, 'env')

let checks = 0
function ok(condition, what) {
  checks++
  if (!condition) throw new Error(`Falhou: ${what}`)
  console.log(`  ok  ${what}`)
}
const step = (title) => console.log(`\n== ${title}`)
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
const hex = () => randomBytes(18).toString('hex')

function compose(...args) {
  return execFileSync('docker', ['compose', '-p', PROJECT, '--env-file', envFile, '-f', join(deploy, 'docker-compose.yml'), '-f', join(work, 'e2e.override.yml'), ...args],
    { cwd: deploy, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], env: { ...process.env, MSYS_NO_PATHCONV: '1' } })
}
const dockerExec = (service, ...args) => execFileSync('docker', ['exec', `${PROJECT}-${service}-1`, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })
const artisan = (...args) => dockerExec('api', 'php', 'artisan', ...args)
const sql = (db, query) => dockerExec('postgres', 'psql', '-U', 'postgres', '-d', db, '-tAc', query).trim()

// Tags próprias: o teste nunca troca as imagens bpm-tmsa/*:dev usadas pela stack de desenvolvimento.
const IMAGES = { api: 'bpm-drtest/api:e2e', agent: 'bpm-drtest/backup-agent:e2e', web: 'bpm-drtest/web:e2e' }

function prepare() {
  mkdirSync(work, { recursive: true })
  writeFileSync(join(work, 'e2e.override.yml'), [
    'services:',
    ...['api', 'queue-worker', 'scheduler'].map((s) => `  ${s}:\n    image: ${IMAGES.api}`),
    `  backup-agent:\n    image: ${IMAGES.agent}`,
    `  web:\n    image: ${IMAGES.web}`,
    `  azurite:\n    ports:\n      - "127.0.0.1:${AZURITE_PORT}:10000"\n    networks: [backend, azuritehost]`,
    'networks:\n  azuritehost: {}', '',
  ].join('\n'))
  if (existsSync(envFile)) return
  writeFileSync(envFile, [
    `COMPOSE_PROJECT_NAME=${PROJECT}`, `APP_KEY=base64:${randomBytes(32).toString('base64')}`, 'APP_ENV=local', `PUBLIC_URL=http://localhost:${PORT}`,
    'BPM_HTTP_BIND=127.0.0.1', `BPM_HTTP_PORT=${PORT}`, `POSTGRES_SUPERUSER_PASSWORD=${hex()}`, `BPM_APP_DB_PASSWORD=${hex()}`, `FLOWABLE_DB_PASSWORD=${hex()}`,
    'FLOWABLE_REST_USER=bpm-engine-client', `FLOWABLE_REST_PASSWORD=${hex()}`, 'STORAGE_ACCESS_KEY=bpmstorage', `STORAGE_SECRET_KEY=${hex()}`,
    'BPM_AUTH_DRIVER=dev', 'BPM_SUPERADMIN_LOGIN_ENABLED=false', 'WEB_BUILD_MODE=docker-dev', 'SEED_ON_START=true', 'COMPOSE_PROFILES=dev', 'MAILPIT_UI_PORT=18026',
    'BPM_AZURITE_BLOB_ENDPOINT=http://azurite:10000/devstoreaccount1', `BPM_SECRETS_KEY_PATH=${join(work, 'bpm_secrets_key').replaceAll('\\', '/')}`,
    `BPM_ENV_FILE=${envFile.replaceAll('\\', '/')}`, 'BPM_BACKUP_PATH=', '',
  ].join('\n'))
  writeFileSync(join(work, 'bpm_secrets_key'), `${randomBytes(32).toString('base64')}\n`)
}

async function api(path, { method = 'GET', body, as = 'admin', expect } = {}) {
  const res = await fetch(API + path, { method, headers: { Accept: 'application/json', Authorization: `Bearer dev:${as}`, ...(body ? { 'Content-Type': 'application/json' } : {}) }, body: body ? JSON.stringify(body) : undefined })
  // os limites por usuário somam as rotas: o teste é mais rápido do que uma pessoa
  if (res.status === 429) {
    await sleep((Number(res.headers.get('retry-after')) || 5) * 1000 + 500)
    return api(path, { method, body, as, expect })
  }
  const text = await res.text()
  const json = text ? JSON.parse(text) : null
  if (expect ? res.status !== expect : !res.ok) throw new Error(`${method} ${path} → ${res.status}: ${text.slice(0, 400)}`)
  return json
}

async function until(what, fn, seconds = 180) {
  const deadline = Date.now() + seconds * 1000
  for (;;) {
    try {
      const value = await fn()
      if (value) return value
    } catch (e) {
      if (Date.now() > deadline) throw e
    }
    if (Date.now() > deadline) throw new Error(`Tempo esgotado: ${what}`)
    await sleep(1500)
  }
}

async function waitForJob(id, seconds = 300) {
  const job = await until(`pedido ${id}`, async () => {
    const j = await api(`/admin/backups/jobs/${id}`)
    return j.status === 'succeeded' || j.status === 'failed' ? j : null
  }, seconds)
  if (job.status !== 'succeeded') throw new Error(`Pedido ${job.kind} falhou: ${job.errorCode}: ${job.errorDetail}`)
  return job
}

const mode = async () => (await fetch(`${API}/maintenance`).then((r) => r.json())).mode

/** Abre uma solicitação do PO 159 e devolve o id; o outbox a leva ao engine em segundos. */
async function openInstance(tag) {
  const res = await fetch(`${API}/process-instances`, {
    method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: 'Bearer dev:cq', 'Idempotency-Key': `e2e-${tag}-${hex()}` },
    body: JSON.stringify({ flowKey: 'item_reprovado_recebimento', data: { nCan: `CAN-${tag}`, nf: 4521, tpr: 'TPR-778', codigoItem: 'ITEM-1001', ordemCompra: 'OC-55' } }),
  })
  const text = await res.text()
  if (res.status !== 202) throw new Error(`Abertura falhou: ${res.status} ${text.slice(0, 300)}`)
  const id = JSON.parse(text).instance.id
  await until('instância no engine', () => sql('bpm_app', `select engine_process_instance_id from process_instance where id = '${id}'`), 60)
  return id
}

const instances = () => Number(sql('bpm_app', 'select count(*) from process_instance'))
const engineInstances = () => Number(sql('flowable', 'select count(*) from act_ru_execution where parent_id_ is null'))

async function main() {
  prepare()
  step(`Stack ${PROJECT} (${work})`)
  if (!reuse) compose('build', 'api', 'backup-agent', 'web')
  compose('up', '-d', '--no-build')
  await until('API pronta', async () => (await fetch(`${API}/ready`)).ok, 240)
  await until('agente online', async () => (await api('/admin/backups/status')).agent.online, 60)
  ok(true, 'stack no ar e agente online')

  step('Azurite como destino ativo de anexos')
  await api('/admin/settings/attachments-storage', { method: 'PUT', body: { provider: 'azure_blob', azure: { endpointMode: 'emulator', container: 'anexos', pathPrefix: 'bpm', accountKey: AZURITE_KEY, approvalReference: 'E2E' } } })
  artisan('bpm:storage-azure-init')
  const tested = await api('/admin/settings/attachments-storage/test', { method: 'POST', body: {} })
  ok(tested.lastValidation.code === 'storage_ok', 'destino de anexos testado')
  await api('/admin/settings/attachments-storage/activate', { method: 'POST', body: {} })

  step('Chave de recuperação')
  const key = await api('/admin/backups/recovery-key', { method: 'POST', body: {} })
  ok(key.privateKey.startsWith('AGE-SECRET-KEY-1'), 'chave gerada pelo age-keygen real')
  await api('/admin/backups/recovery-key/confirm', { method: 'POST', body: { recoveryKey: 'AGE-SECRET-KEY-1INVALIDA' }, expect: 422 })
  await api('/admin/backups/recovery-key/confirm', { method: 'POST', body: { recoveryKey: key.privateKey } })
  writeFileSync(join(work, 'recovery.key'), `${key.privateKey}\n`)
  const stored = await api('/admin/settings/backups')
  ok(JSON.stringify(stored).indexOf('AGE-SECRET-KEY') < 0, 'só a parte pública fica guardada')

  step('Destino no Azure')
  await api('/admin/settings/backups', { method: 'PUT', body: { azure: { enabled: true, folder: 'backups' } } })
  const probe = await api('/admin/settings/backups/test', { method: 'POST', body: {} })
  ok(probe.lastValidation.code === 'backup_destination_ok', `destino testado em blocos: ${probe.lastValidation.message}`)

  step('Backup de nível Dados, com carga de teste')
  const before = await openInstance('ANTES')
  const beforeCount = instances()
  const beforeEngine = engineInstances()
  const backupJob = await api('/admin/backups', { method: 'POST', body: { scope: 'data', azure: true, verify: true } })
  const done = await waitForJob(backupJob.id)
  ok(!done.errorCode, 'backup concluído, com cópia no Azure')
  const [entry] = (await api('/admin/backups')).data
  ok(entry.inLocal && entry.inAzure, 'backup no servidor e no Azure')
  ok(entry.verification?.ok && entry.verification.app.tables > 40, `carga de teste: ${entry.verification?.app.tables} tabelas`)
  ok(await mode() === 'normal', 'pausa do outbox solta')

  step('Recusas antes de restaurar')
  await api(`/admin/backups/${entry.id}/restore`, { method: 'POST', body: { confirmation: 'errada', justification: 'Teste de ponta a ponta', recoveryKey: key.privateKey }, expect: 422 })
  const other = dockerExec('api', 'age-keygen').split('\n').find((l) => l.startsWith('AGE-SECRET-KEY-'))
  const refused = await api(`/admin/backups/${entry.id}/restore`, { method: 'POST', body: { confirmation: 'TMSA', justification: 'Teste de ponta a ponta', recoveryKey: other }, expect: 422 })
  ok(refused.errors.recoveryKey, 'chave de outro backup recusada')

  step('Teste de restauração, sem tocar nos dados em uso')
  const testJob = await api(`/admin/backups/${entry.id}/restore-test`, { method: 'POST', body: { recoveryKey: key.privateKey } })
  await waitForJob(testJob.id)
  const tested2 = (await api('/admin/backups')).data.find((e) => e.id === entry.id)
  ok(tested2.verification?.kind === 'restore_test' && tested2.verification.ok, 'backup abriu e carregou em bancos temporários')
  ok(!sql('postgres', "select string_agg(datname, ',') from pg_database where datname like 'bpm_verify%'"), 'bancos temporários apagados')

  step('Dados novos depois do backup')
  const after = await openInstance('DEPOIS')
  ok(instances() === beforeCount + 1, 'instância criada depois do backup')

  step('Restauração')
  const restoreJob = await api(`/admin/backups/${entry.id}/restore`, { method: 'POST', body: { confirmation: 'tmsa', justification: 'Teste de ponta a ponta', recoveryKey: key.privateKey } })
  const seen = new Set()
  await until('modo seguro', async () => {
    const m = await fetch(`${API}/maintenance`).then((r) => r.json())
    seen.add(m.mode)
    return m.mode === 'safe'
  }, 300)
  ok(seen.has('maintenance') || seen.has('safe'), `modos vistos: ${[...seen].join(', ')}`)
  ok(instances() === beforeCount, 'dados voltaram para o backup')
  ok(sql('bpm_app', `select count(*) from process_instance where id = '${after}'`) === '0', 'instância posterior saiu')
  ok(sql('bpm_app', `select count(*) from process_instance where id = '${before}'`) === '1', 'instância anterior ficou')
  ok(engineInstances() === beforeEngine, `engine voltou junto (${engineInstances()} instâncias)`)
  ok(sql('postgres', "select count(*) from pg_database where datname like '%_pre_%'") === '2', 'bancos antigos guardados para desfazer')
  const restored = await waitForJob(restoreJob.id, 30)
  ok(restored.kind === 'restore', 'pedido de restauração segue no histórico')

  step('Modo seguro')
  await api('/me', { as: 'cq', expect: 503 })
  const me = await api('/me')
  ok(me.safeMode === true, 'administrador entra, e os demais recebem 503')
  const review = await api('/admin/backups/safe-mode')
  ok(review.active && review.auditEvents > 0, `revisão: ${review.auditEvents} eventos de auditoria guardados`)
  ok(sql('bpm_app', `select count(*) from audit_event where action = 'backup.restored' and data->>'job' = '${restoreJob.id}'`) === '1', 'backup.restored registrado no banco restaurado')
  const catalog = (await api('/admin/backups')).data
  ok(catalog.some((e) => e.origin === 'pre_restore'), 'backup de segurança refeito no catálogo')

  step('Desfazer')
  const undo = await api('/admin/backups/safe-mode/undo', { method: 'POST', body: {} })
  await until('modo normal', async () => await mode() === 'normal', 240)
  ok(instances() === beforeCount + 1, 'dados voltaram a como estavam antes da restauração')
  ok(sql('bpm_app', `select count(*) from process_instance where id = '${after}'`) === '1', 'instância posterior voltou')
  ok(engineInstances() === beforeEngine + 1, 'engine voltou junto')
  ok(sql('postgres', "select count(*) from pg_database where datname like '%_pre_%' or datname like '%_discard_%'") === '0', 'nenhum banco sobrando')
  ok((await api(`/admin/backups/jobs/${undo.id}`)).status === 'succeeded', 'pedido de desfazer concluído')

  step('Restaurar de novo e liberar, descartando e-mails')
  const again = await api(`/admin/backups/${entry.id}/restore`, { method: 'POST', body: { confirmation: 'TMSA', justification: 'Teste de ponta a ponta', recoveryKey: key.privateKey } })
  await until('modo seguro', async () => await mode() === 'safe', 300)
  await waitForJob(again.id, 30)
  const release = await api('/admin/backups/safe-mode/release', { method: 'POST', body: { notifications: 'discard' } })
  await until('modo normal', async () => await mode() === 'normal', 60)
  await waitForJob(release.id, 30)
  ok(sql('postgres', "select count(*) from pg_database where datname like '%_pre_%'") === '0', 'bancos antigos apagados na liberação')
  await api('/me', { as: 'cq' })
  ok(true, 'usuários voltam a entrar')

  step('Processo segue depois da restauração')
  const fresh = await openInstance('RESTAURADO')
  ok(instances() === beforeCount + 1, 'nova instância chega ao engine')
  ok(sql('bpm_app', "select count(*) from engine_command_outbox where state = 'FAILED'") === '0', 'nenhum comando falhou')
  const duplicates = sql('flowable', 'select coalesce(max(n), 0) from (select count(*) n from act_ru_execution where parent_id_ is null group by business_key_) s')
  ok(duplicates === '1', 'nenhum processo duplicado no engine')
  ok(typeof fresh === 'string', 'fluxo normal restabelecido')

  await disasterRecovery(key.privateKey)

  console.log(`\nPassou: ${checks} verificações.`)
}

/** Ensaio: apaga a stack, os volumes e o disco de backups, e sobe tudo de novo só com a chave e o Azure. */
async function disasterRecovery(privateKey) {
  step('Backup de Aplicação inteira')
  const job = await api('/admin/backups', { method: 'POST', body: { scope: 'full', azure: true } })
  // a primeira cópia exporta todas as imagens (alguns GB)
  await waitForJob(job.id, 900)
  const [full] = (await api('/admin/backups')).data
  ok(full.scope === 'full' && full.inAzure, 'backup completo no Azure')
  const repeat = await api('/admin/backups', { method: 'POST', body: { scope: 'full', azure: true } })
  await waitForJob(repeat.id, 300)
  const images = Number(dockerExec('backup-agent', 'sh', '-c', 'ls /backups/tmsa/images | wc -l').trim())
  const services = (await api('/admin/backups')).data.length
  ok(images >= 5 && images < 15 && services > 1, `${images} imagens, guardadas uma vez por digest`)
  const marker = await openInstance('ANTES-DO-DESASTRE')
  const count = instances() - 1
  const gone = marker

  step('Desastre: stack, volumes e disco de backups apagados')
  // o docker stop precisa encerrar o agente; antes do STOPSIGNAL ele só morria pelo SIGKILL, no fim do prazo
  const stopStarted = Date.now()
  execFileSync('docker', ['stop', '-t', '60', `${PROJECT}-backup-agent-1`], { stdio: 'pipe' })
  const stopSeconds = Math.round((Date.now() - stopStarted) / 1000)
  const stopExit = execFileSync('docker', ['inspect', '-f', '{{.State.ExitCode}}', `${PROJECT}-backup-agent-1`], { encoding: 'utf8' }).trim()
  ok(stopSeconds < 15 && stopExit === '0', `agente encerra no docker stop (${stopSeconds}s, código ${stopExit})`)
  // --all: o agente já está parado e ainda segura o volume de estado
  const toRemove = compose('ps', '--all', '--services').split('\n').filter((s) => s && s !== 'azurite')
  compose('rm', '-sf', ...toRemove)
  for (const volume of ['pgdata', 'storagedata', 'backupstate', 'backupdata']) {
    execFileSync('docker', ['volume', 'rm', '-f', `${PROJECT}_${volume}`], { stdio: 'pipe' })
  }
  ok(execFileSync('docker', ['volume', 'ls', '-q', '--filter', `name=${PROJECT}_pgdata`], { encoding: 'utf8' }).trim() === '', 'banco apagado')

  step('Recuperação com dr-restore.sh, em uma pasta nova')
  const dir = join(work, 'servidor-novo').replaceAll('\\', '/')
  const keyFile = join(work, 'recovery.key').replaceAll('\\', '/')
  writeFileSync(keyFile, `${privateKey}\n`)
  execFileSync('sh', [join(deploy, 'dr-restore.sh').replaceAll('\\', '/'), '--org', 'tmsa', '--project', PROJECT, '--account', 'devstoreaccount1', '--container', 'anexos', '--path', 'bpm/backups',
    '--key-file', keyFile, '--dir', dir, '--endpoint', `http://host.docker.internal:${AZURITE_PORT}/devstoreaccount1`],
    { stdio: 'inherit', env: { ...process.env, AZURE_ACCOUNT_KEY: AZURITE_KEY, MSYS_NO_PATHCONV: '1' } })
  ok(existsSync(join(dir, 'deploy', '.env')), 'configuração recriada')

  await until('API de volta', async () => (await fetch(`${API}/maintenance`)).ok, 240)
  await until('modo seguro', async () => await mode() === 'safe', 120)
  ok(true, 'aplicação voltou em modo seguro')
  await until('engine', async () => (await fetch(`${API}/ready`)).ok, 180)

  const restored = (args) => execFileSync('docker', ['exec', `${PROJECT}-postgres-1`, 'psql', '-U', 'postgres', '-d', 'bpm_app', '-tAc', args], { encoding: 'utf8' }).trim()
  ok(Number(restored('select count(*) from process_instance')) === count, 'dados do backup de volta')
  ok(restored(`select count(*) from process_instance where id = '${gone}'`) === '0', 'instância posterior ao backup não existe')
  await api('/me', { as: 'cq', expect: 503 })
  const catalog = (await api('/admin/backups')).data
  ok(catalog.length > 0, `catálogo refeito do Azure: ${catalog.length} backups`)
  const status = await until('agente online', async () => {
    const s = await api('/admin/backups/status')
    return s.agent.online ? s : null
  }, 120)
  ok(status.mode === 'safe', 'agente de volta e aguardando revisão')

  step('Liberação depois da recuperação')
  const release = await api('/admin/backups/safe-mode/release', { method: 'POST', body: { notifications: 'discard' } })
  await until('modo normal', async () => await mode() === 'normal', 120)
  await waitForJob(release.id, 30)
  await api('/me', { as: 'cq' })
  ok(true, 'sistema liberado')
  const attachmentTest = await api('/admin/settings/attachments-storage/test', { method: 'POST', body: {} })
  ok(attachmentTest.lastValidation.code === 'storage_ok', 'cofre legível: a chave do Azure abriu depois da recuperação')
}

async function cleanup() {
  if (keep || reuse) return
  compose('down', '-v', '--remove-orphans')
  execFileSync('docker', ['rmi', ...Object.values(IMAGES)], { stdio: 'ignore' })
  rmSync(work, { recursive: true, force: true })
}

const started = Date.now()
main().then(cleanup).then(() => console.log(`Tempo total: ${Math.round((Date.now() - started) / 1000)}s`)).catch((e) => {
  console.error(`\n${e.message}`)
  console.error(`Stack mantida para investigar: docker compose -p ${PROJECT} --env-file ${envFile} logs backup-agent`)
  process.exit(1)
})
