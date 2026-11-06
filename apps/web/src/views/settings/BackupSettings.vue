<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useConfirm } from 'primevue/useconfirm'
import { api } from '@/api/http'
import type { BackupEntry, BackupJobView, BackupScheduleConfig, BackupScope, BackupSettingsValue, BackupStatus } from '@/api/types'
import { daysSince, describeSchedule, JOB_LABELS, ORIGIN_LABELS, SCOPE_LABELS, STAGE_LABELS } from '@/composables/backup'
import { fmtDateTime, fmtSize } from '@/composables/format'
import { useNotify } from '@/composables/notify'
import { useSetting } from '@/composables/useSetting'
import SettingHeader from '@/components/settings/SettingHeader.vue'
import IntegrationLog from '@/components/settings/IntegrationLog.vue'
import NewBackupDialog from '@/components/settings/backup/NewBackupDialog.vue'
import RecoveryKeyDialog from '@/components/settings/backup/RecoveryKeyDialog.vue'
import RestoreDialog from '@/components/settings/backup/RestoreDialog.vue'
import RestoreTestDialog from '@/components/settings/backup/RestoreTestDialog.vue'
import ScheduleDialog from '@/components/settings/backup/ScheduleDialog.vue'

const emit = defineEmits<{ changed: [] }>()
const router = useRouter()
const confirm = useConfirm()
const notify = useNotify()
const { setting, loading, saving, err, load, save, action } = useSetting<BackupSettingsValue>('backups')

const tab = ref<'backups' | 'schedules' | 'log'>('backups')
const status = ref<BackupStatus | null>(null)
const entries = ref<BackupEntry[]>([])
const jobs = ref<BackupJobView[]>([])
const scopeFilter = ref<BackupScope | ''>('')
const logKey = ref(0)
const form = reactive({ folder: 'backups', azureEnabled: false, localCopies: 2, timezone: 'America/Sao_Paulo' })

const showNew = ref(false)
const showKey = ref(false)
const showSchedule = ref(false)
const editing = ref<BackupScheduleConfig | null>(null)
const restoring = ref<BackupEntry | null>(null)
const testing = ref<BackupEntry | null>(null)

const v = computed(() => setting.value?.value)
const target = computed(() => v.value?.azureTarget ?? null)
const azureOn = computed(() => !!v.value?.azure.enabled && !!target.value)
const destination = computed(() => {
  const t = target.value
  if (!t || !form.azureEnabled) return null
  return [t.accountName, t.container, t.pathPrefix, form.folder].filter(Boolean).join('/')
})
const filtered = computed(() => entries.value.filter((e) => !scopeFilter.value || e.scope === scopeFilter.value))
const busy = computed(() => !!status.value?.activeJob)
const ready = computed(() => !!status.value?.agent.online && !!status.value?.recoveryKey && !busy.value)
const testAge = computed(() => daysSince(status.value?.lastRestoreTest?.at))
const latestId = computed(() => entries.value[0]?.id)

function fill() {
  const s = v.value
  if (!s) return
  Object.assign(form, { folder: s.azure.folder, azureEnabled: s.azure.enabled, localCopies: s.localCopies, timezone: s.timezone })
}
watch(setting, fill)

async function refresh() {
  try {
    const [st, list, history] = await Promise.all([
      api<BackupStatus>('/admin/backups/status'),
      api<{ data: BackupEntry[] }>('/admin/backups'),
      api<{ data: BackupJobView[] }>('/admin/backups/jobs'),
    ])
    status.value = st
    entries.value = list.data
    jobs.value = history.data
    if (st.mode === 'safe') router.push({ name: 'settings-backups-review' })
  } catch {
    /* a próxima volta tenta de novo; em manutenção o interceptador leva à página própria */
  }
}

let timer: number | undefined
onMounted(async () => {
  await Promise.all([load(), refresh()])
  fill()
  timer = window.setInterval(refresh, 5000)
})
onUnmounted(() => window.clearInterval(timer))

async function saveDestination() {
  if (await save({ timezone: form.timezone, localCopies: form.localCopies, azure: { enabled: form.azureEnabled, folder: form.folder } })) emit('changed')
  logKey.value++
}
async function testDestination() {
  if (await action('backups/test')) emit('changed')
  logKey.value++
}
async function persistSchedules(list: BackupScheduleConfig[]) {
  if (await save({ schedules: list }, 'Agendamentos salvos.')) {
    emit('changed')
    await refresh()
  }
}
function openSchedule(s: BackupScheduleConfig | null) {
  editing.value = s
  showSchedule.value = true
}
function saveSchedule(s: BackupScheduleConfig) {
  const list = [...(v.value?.schedules ?? [])]
  const i = s.id ? list.findIndex((x) => x.id === s.id) : -1
  if (i >= 0) list[i] = s
  else list.push(s)
  persistSchedules(list)
}
function removeSchedule(s: BackupScheduleConfig) {
  confirm.require({
    header: 'Remover agendamento', message: `Remover "${describeSchedule(s)}"? Os backups já feitos continuam guardados.`,
    acceptLabel: 'Remover', rejectLabel: 'Cancelar', acceptClass: 'p-button-danger',
    accept: () => persistSchedules((v.value?.schedules ?? []).filter((x) => x.id !== s.id)),
  })
}

async function post(path: string, message: string) {
  try {
    await api(path, { method: 'POST', body: {} })
    notify.success(message)
    await refresh()
  } catch (e) {
    notify.error(e)
  }
}
function removeBackup(e: BackupEntry) {
  confirm.require({
    header: 'Apagar backup', message: `Apagar o backup de ${fmtDateTime(e.backupAt)} do servidor e do Azure? Esta ação não pode ser desfeita.`,
    acceptLabel: 'Apagar', rejectLabel: 'Cancelar', acceptClass: 'p-button-danger',
    accept: async () => {
      try {
        await api(`/admin/backups/${e.id}`, { method: 'DELETE' })
        await refresh()
      } catch (error) {
        notify.error(error)
      }
    },
  })
}
function where(e: BackupEntry) {
  return e.inLocal && e.inAzure ? 'Servidor e Azure' : e.inAzure ? 'Azure' : 'Servidor'
}
function keyDone() {
  load()
  refresh()
  emit('changed')
}
</script>

<template>
  <SettingHeader :setting="setting" title="Backup e recuperação" subtitle="Cópias de segurança dos dados e da aplicação, com agendamento, restauração e envio para o Azure." />

  <div v-if="!loading && !setting" class="tm-note err" role="alert" style="margin-top:0"><i class="pi pi-times-circle" aria-hidden="true" /><span>Não foi possível carregar esta configuração. Confirme que a API foi atualizada (reconstrua e reinicie o serviço <code>api</code>) e tente de novo.</span></div>

  <template v-if="setting">
    <div class="tm-tabbar" role="tablist">
      <button class="tm-tab" :class="{ active: tab === 'backups' }" role="tab" @click="tab = 'backups'">Backups</button>
      <button class="tm-tab" :class="{ active: tab === 'schedules' }" role="tab" @click="tab = 'schedules'">Agendamentos e destino</button>
      <button class="tm-tab" :class="{ active: tab === 'log' }" role="tab" @click="tab = 'log'">Log</button>
    </div>

    <template v-if="tab === 'backups'">
      <div class="tm-metrics">
        <div class="tm-metric"><div class="tm-metric-label">Último backup</div><div class="tm-metric-value small">{{ status?.lastBackup ? fmtDateTime(status.lastBackup.backupAt) : 'Nenhum' }}</div><div v-if="status?.lastBackup" class="tm-hint">{{ SCOPE_LABELS[status.lastBackup.scope] }}, {{ where(status.lastBackup).toLowerCase() }}</div></div>
        <div class="tm-metric"><div class="tm-metric-label">Próximo</div><div class="tm-metric-value small">{{ status?.nextRunAt ? fmtDateTime(status.nextRunAt) : 'Sem agendamento' }}</div></div>
        <div class="tm-metric"><div class="tm-metric-label">Agente de backup</div><div class="tm-metric-value small" :class="status?.agent.online ? 'ok' : 'bad'">{{ status?.agent.online ? 'Online' : 'Fora do ar' }}</div><div v-if="status && !status.agent.online" class="tm-hint">Confira o serviço backup-agent</div></div>
        <div class="tm-metric"><div class="tm-metric-label">Último teste de restauração</div><div class="tm-metric-value small">{{ testAge === null ? 'Nunca' : testAge === 0 ? 'Hoje' : `${testAge} dias` }}</div></div>
      </div>

      <div v-if="status && !status.recoveryKey" class="tm-note warn" style="margin:0 0 16px"><i class="pi pi-key" aria-hidden="true" /><span><strong>Falta a chave de recuperação.</strong> Sem ela não há backup. Gere em Agendamentos e destino.</span></div>
      <div v-if="status?.warnings.includes('no_recent_full_backup')" class="tm-note warn" style="margin:0 0 16px"><i class="pi pi-exclamation-triangle" aria-hidden="true" /><span>Não há backup de <strong>Aplicação inteira</strong> recente: sem ele não dá para recuperar em um servidor novo.</span></div>
      <div v-if="status?.warnings.includes('no_recent_restore_test')" class="tm-note warn" style="margin:0 0 16px"><i class="pi pi-exclamation-triangle" aria-hidden="true" /><span>Nenhum teste de restauração recente. Teste um backup para provar que ele restaura e que a chave guardada é a certa.</span></div>

      <div v-if="status?.activeJob" class="tm-note" role="status" style="margin:0 0 16px"><span class="tm-spinner" aria-hidden="true" /><span><strong>{{ JOB_LABELS[status.activeJob.kind] ?? status.activeJob.kind }} em andamento.</strong> {{ STAGE_LABELS[status.activeJob.stage ?? ''] ?? 'Aguardando o agente' }}<template v-if="status.activeJob.percent"> ({{ status.activeJob.percent }}%)</template></span></div>

      <div class="tm-toolbar">
        <div class="tm-toolbar-left">
          <select v-model="scopeFilter" class="tm-select" aria-label="Filtrar por nível"><option value="">Todos os níveis</option><option v-for="(label, key) in SCOPE_LABELS" :key="key" :value="key">{{ label }}</option></select>
          <button class="tm-btn small ghost" :disabled="!ready" @click="post('/admin/backups/catalog/sync', 'Atualizando o catálogo.')"><i class="pi pi-refresh" aria-hidden="true" /> Atualizar catálogo</button>
        </div>
        <button class="tm-btn primary" :disabled="!ready" @click="showNew = true"><i class="pi pi-play" aria-hidden="true" /> Fazer backup agora</button>
      </div>
      <div class="tm-table-wrap">
        <table class="tm-table">
          <thead><tr><th>Data</th><th>Nível</th><th>Tamanho</th><th>Onde está</th><th>Situação</th><th /></tr></thead>
          <tbody>
            <tr v-if="!filtered.length"><td colspan="6"><div class="tm-empty"><strong>Nenhum backup ainda</strong>Faça o primeiro backup ou crie um agendamento.</div></td></tr>
            <tr v-for="e in filtered" :key="e.id">
              <td><div class="tm-strong">{{ fmtDateTime(e.backupAt) }}</div><div class="tm-flow-sub">{{ ORIGIN_LABELS[e.origin] }}</div></td>
              <td>{{ SCOPE_LABELS[e.scope] }}</td>
              <td>{{ fmtSize(e.bytes) }}</td>
              <td>{{ where(e) }}</td>
              <td><span v-if="e.verification?.ok" class="tm-pill green">Testado</span><span v-else-if="azureOn && !e.inAzure" class="tm-pill amber">Sem cópia no Azure</span><span v-else class="tm-pill neutral">Íntegro</span></td>
              <td>
                <div class="tm-actions">
                  <button class="tm-btn small" :disabled="!ready" @click="restoring = e">Restaurar</button>
                  <button class="tm-btn small ghost" :disabled="!ready" @click="testing = e">Testar</button>
                  <button v-if="azureOn && !e.inAzure && e.inLocal" class="tm-btn small ghost" :disabled="!ready" @click="post(`/admin/backups/${e.id}/copy-to-azure`, 'Envio ao Azure iniciado.')">Enviar ao Azure</button>
                  <button v-if="e.id !== latestId" class="tm-btn small ghost danger" :disabled="!ready" aria-label="Apagar backup" @click="removeBackup(e)"><i class="pi pi-trash" aria-hidden="true" /></button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <template v-else-if="tab === 'schedules'">
      <section class="tm-panel" style="margin-bottom:16px">
        <div class="tm-panel-head"><div><div class="tm-panel-title">Agendamentos</div><div class="tm-panel-caption">Horários em {{ form.timezone }}</div></div><button class="tm-btn small" @click="openSchedule(null)"><i class="pi pi-plus" aria-hidden="true" /> Novo agendamento</button></div>
        <table v-if="v?.schedules.length" class="tm-table">
          <thead><tr><th>Frequência</th><th>O que copia</th><th>Azure</th><th>Guardar</th><th>Testa ao criar</th><th /></tr></thead>
          <tbody>
            <tr v-for="s in v.schedules" :key="s.id">
              <td class="tm-strong">{{ describeSchedule(s) }}</td><td>{{ SCOPE_LABELS[s.scope] }}</td><td>{{ s.azure ? 'Sim' : 'Não' }}</td><td>{{ s.keep }} cópias</td><td>{{ s.verify ? 'Sim' : 'Não' }}</td>
              <td><div class="tm-actions"><span class="tm-pill" :class="s.enabled ? 'green' : 'neutral'">{{ s.enabled ? 'Ativo' : 'Pausado' }}</span><button class="tm-btn small ghost" @click="openSchedule(s)">Alterar</button><button class="tm-btn small ghost danger" aria-label="Remover agendamento" @click="removeSchedule(s)"><i class="pi pi-trash" aria-hidden="true" /></button></div></td>
            </tr>
          </tbody>
        </table>
        <div v-else class="tm-empty"><strong>Nenhum agendamento</strong>Sugestão: Banco todo dia, Dados toda semana e Aplicação inteira todo mês.</div>
      </section>

      <div class="grid">
        <section class="tm-panel">
          <div class="tm-panel-head"><div class="tm-panel-title">Destino</div><button class="tm-btn small" :disabled="saving || !target || !azureOn" @click="testDestination"><i class="pi pi-bolt" aria-hidden="true" /> Testar destino</button></div>
          <div class="tm-panel-body">
            <label class="check"><input v-model="form.azureEnabled" type="checkbox" :disabled="!target" /> <span>Enviar cópias ao Azure Blob Storage</span></label>
            <div v-if="err('azure.enabled')" class="tm-hint err">{{ err('azure.enabled') }}</div>
            <div v-if="target" class="tm-field"><label for="d-acc">Conta e contêiner (Armazenamento de anexos)</label><input id="d-acc" :value="`${target.accountName ?? 'emulador'} / ${target.container}`" disabled /></div>
            <div v-else class="tm-hint" style="margin-bottom:12px">Ative o Azure Blob Storage em <RouterLink :to="{ name: 'settings-attachments-storage' }">Armazenamento de anexos</RouterLink> para enviar cópias para fora do servidor.</div>
            <div class="two">
              <div class="tm-field"><label for="d-folder">Pasta no contêiner</label><input id="d-folder" v-model="form.folder" autocomplete="off" /><div v-if="err('azure.folder')" class="tm-hint err">{{ err('azure.folder') }}</div></div>
              <div class="tm-field"><label for="d-local">Cópias no servidor</label><input id="d-local" v-model.number="form.localCopies" type="number" min="1" max="30" /><div v-if="err('localCopies')" class="tm-hint err">{{ err('localCopies') }}</div></div>
            </div>
            <div v-if="target" class="tm-note warn" style="margin:0 0 12px"><i class="pi pi-exclamation-triangle" aria-hidden="true" /><span>A pasta fica no mesmo contêiner dos anexos: quem tiver a chave da conta consegue apagar os dois. Mantenha a exclusão reversível (soft delete) ligada no Azure.</span></div>
            <button class="tm-btn primary" :disabled="saving" @click="saveDestination">Salvar</button>
          </div>
        </section>
        <section class="tm-panel">
          <div class="tm-panel-head"><div class="tm-panel-title">Chave de recuperação</div><span class="tm-pill" :class="v?.recoveryKey ? 'green' : 'amber'">{{ v?.recoveryKey ? 'Confirmada' : 'Pendente' }}</span></div>
          <div class="tm-panel-body">
            <dl v-if="v?.recoveryKey" class="tm-kv" style="margin:0 0 12px"><dt>Impressão digital</dt><dd><code>{{ v.recoveryKey.fingerprint }}</code></dd><dt>Confirmada em</dt><dd>{{ fmtDateTime(v.recoveryKey.confirmedAt) }} por {{ v.recoveryKey.confirmedBy }}</dd></dl>
            <div class="tm-note" style="margin:0 0 12px"><i class="pi pi-key" aria-hidden="true" /><span>A chave não fica guardada no sistema. Sem ela, nenhum backup pode ser restaurado, nem em um servidor novo.</span></div>
            <button class="tm-btn small" @click="showKey = true">{{ v?.recoveryKey ? 'Gerar nova chave' : 'Gerar chave' }}</button>
          </div>
        </section>
      </div>
    </template>

    <template v-else>
      <section class="tm-panel" style="margin-bottom:16px">
        <div class="tm-panel-head"><div class="tm-panel-title">Operações recentes</div></div>
        <table v-if="jobs.length" class="tm-table">
          <thead><tr><th>Quando</th><th>Operação</th><th>Por</th><th>Resultado</th></tr></thead>
          <tbody>
            <tr v-for="j in jobs" :key="j.id">
              <td>{{ fmtDateTime(j.createdAt) }}</td><td>{{ JOB_LABELS[j.kind] ?? j.kind }}</td><td>{{ j.requestedBy ?? 'Agendamento' }}</td>
              <td><span class="tm-pill" :class="j.status === 'succeeded' ? (j.errorCode ? 'amber' : 'green') : j.status === 'failed' ? 'red' : 'blue'">{{ j.status === 'succeeded' ? 'Concluída' : j.status === 'failed' ? 'Falhou' : 'Em andamento' }}</span><div v-if="j.errorDetail" class="tm-hint">{{ j.errorDetail }}</div></td>
            </tr>
          </tbody>
        </table>
        <div v-else class="tm-empty"><strong>Nenhuma operação</strong></div>
      </section>
      <IntegrationLog area="backup" title="Log do destino e da configuração" caption="Testes do destino no Azure e alterações da configuração. Sem chaves nem URLs assinadas." :kind-labels="{ test: 'Testes', config: 'Configuração' }" :refresh-key="logKey" />
    </template>
  </template>

  <NewBackupDialog v-model:visible="showNew" :azure-available="azureOn" @done="refresh" />
  <RecoveryKeyDialog v-model:visible="showKey" :organization="status?.organization ?? ''" :destination="destination" @done="keyDone" />
  <ScheduleDialog v-model:visible="showSchedule" :schedule="editing" :azure-available="azureOn" @save="saveSchedule" />
  <RestoreDialog :visible="!!restoring" :entry="restoring" :organization="status?.organization ?? ''" @update:visible="restoring = null" @done="refresh" />
  <RestoreTestDialog :visible="!!testing" :entry="testing" @update:visible="testing = null" @done="refresh" />
</template>

<style scoped>
.grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px; align-items: start; }
@media (max-width: 1100px) { .grid { grid-template-columns: 1fr; } }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.check { display: flex; gap: 8px; align-items: center; font-size: 12px; margin-bottom: 10px; color: #43586a; }
.small { font-size: 16px; }
.ok { color: var(--green); }
.bad { color: var(--red); }
.err { color: var(--red); }
.tm-note.err { color: #87413a; }
code { font-size: 11px; background: rgba(0, 0, 0, 0.05); padding: 1px 4px; border-radius: 4px; }
</style>
