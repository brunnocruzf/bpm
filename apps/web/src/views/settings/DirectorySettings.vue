<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import MultiSelect from 'primevue/multiselect'
import { api, ApiError } from '@/api/http'
import type { SyncRun } from '@/api/types'
import { fmtDateTime } from '@/composables/format'
import { useNotify } from '@/composables/notify'
import { useSetting } from '@/composables/useSetting'
import SettingHeader from '@/components/settings/SettingHeader.vue'
import IntegrationLog from '@/components/settings/IntegrationLog.vue'

const emit = defineEmits<{ changed: [] }>()
const { setting, saving, err, load, save, action } = useSetting<any>('entra/directory-sync')
const notify = useNotify()

const form = reactive({
  enabled: false, transport: 'graph', tenantId: '', clientId: '', authorityHost: 'login.microsoftonline.com', credentialType: 'certificate',
  scope: 'groups' as 'groups' | 'all', groups: [] as { id: string; name: string }[], includeGuests: false, includeDisabled: false, scheduleMinutes: 0, staleAfterHours: 48, photos: { enabled: true },
  checklist: { separateApp: false, applicationPermissions: false, adminConsent: false, noWritePermissions: false } as Record<string, boolean>,
})
const secret = reactive({ certificatePem: '', clientSecret: '', clientSecretApproval: '', credentialFileRef: '' })
const newGroup = reactive({ id: '', name: '' })
// ampliar para todo o diretório exige confirmação explícita (validada também no servidor)
const confirmAll = ref(false)
const needsAllConfirmation = computed(() => form.scope === 'all' && setting.value?.value?.scope !== 'all')

interface Overview { cursor: { present: boolean; savedAt: string | null; fullSyncRequired: boolean; resumable: boolean }; lastSuccessAt: string | null; nextRunAt: string | null; alerts: { code: string; message: string }[]; counts: Record<string, number> }
const runs = ref<SyncRun[]>([])
const overview = ref<Overview | null>(null)
const detail = ref<SyncRun | null>(null)
let poll: number | undefined
// recarrega o log depois de salvar, testar, sincronizar ou mudar mapeamentos
const logKey = ref(0)
let wasActive = false

interface Mapping { id: string; entraGroupId: string; entraGroupName: string | null; tmsaGroupKey: string | null; tmsaRoles: string[]; privileged: boolean; enabled: boolean; justification: string | null; createdBy: string | null }
const mappings = ref<Mapping[]>([])
const roles = ref<string[]>([])
const privilegedRoles = ref<string[]>([])
const tmsaGroups = ref<{ key: string; name: string }[]>([])
const importedGroups = ref<{ objectId: string; name: string | null; members: number; selected: boolean; removed: boolean }[]>([])
const mapForm = reactive({ entraGroupId: '', tmsaGroupKey: '', tmsaRoles: [] as string[], privileged: false, justification: '' })
const mapErrors = ref<Record<string, string[]>>({})

const CHECKLIST = [
  { key: 'separateApp', label: 'App Registration SEPARADO do login, exclusivo para a sincronização (sem plataforma SPA).' },
  { key: 'applicationPermissions', label: 'Microsoft Graph → Application permissions: somente User.Read.All e Group.Read.All (avaliar GroupMember.Read.All).' },
  { key: 'adminConsent', label: '"Grant admin consent" executado por administrador do tenant (o produto não solicita consentimento).' },
  { key: 'noWritePermissions', label: 'Nenhuma permissão de escrita no diretório e nada de Directory.Read.All sem aprovação de segurança.' },
]
const needsPrivilegedFlag = computed(() => mapForm.tmsaRoles.some((r) => privilegedRoles.value.includes(r)))
const activeRun = computed(() => runs.value.find((r) => r.status === 'queued' || r.status === 'running'))

function fill() {
  const v = setting.value?.value
  if (!v) return
  Object.assign(form, {
    enabled: v.enabled, transport: v.transport, tenantId: v.tenantId, clientId: v.clientId, authorityHost: v.authorityHost, credentialType: v.credentialType,
    scope: v.scope ?? 'groups', groups: (v.groups ?? []).map((g: any) => ({ ...g })), includeGuests: v.includeGuests, includeDisabled: v.includeDisabled, photos: { enabled: v.photos?.enabled ?? true },
    scheduleMinutes: v.scheduleMinutes, staleAfterHours: v.staleAfterHours, checklist: { ...form.checklist, ...(v.checklist ?? {}) },
  })
  secret.clientSecretApproval = v.clientSecretApproval ?? ''
  confirmAll.value = false
}
watch(setting, fill)

async function loadRuns() {
  const res = await api<{ data: SyncRun[]; overview: Overview }>('/admin/entra/sync/runs')
  runs.value = res.data
  overview.value = res.overview
  window.clearTimeout(poll)
  if (activeRun.value) poll = window.setTimeout(loadRuns, 2500)
  // execução terminou: atualiza o log com as etapas finais
  if (wasActive && !activeRun.value) logKey.value++
  wasActive = !!activeRun.value
}
async function loadMappings() {
  const res = await api<{ data: Mapping[]; roles: string[]; privilegedRoles: string[]; groups: { key: string; name: string }[] }>('/admin/entra/mappings')
  mappings.value = res.data
  roles.value = res.roles
  privilegedRoles.value = res.privilegedRoles
  tmsaGroups.value = res.groups
  importedGroups.value = (await api<{ data: any[] }>('/admin/entra/groups')).data
}
onMounted(async () => {
  await load()
  await Promise.all([loadRuns(), loadMappings()])
})
onBeforeUnmount(() => window.clearTimeout(poll))

/** Grupos do diretório fictício (transporte mock, somente dev/homologação). */
function useMockGroups() {
  form.groups = [
    { id: '6f1e0c1a-0000-4000-8000-0000000000c1', name: 'TMSA-BPM-CQ (fictício)' },
    { id: '6f1e0c1a-0000-4000-8000-0000000000c2', name: 'TMSA-BPM-GEF (fictício)' },
    { id: '6f1e0c1a-0000-4000-8000-0000000000c3', name: 'TMSA-BPM-Administradores (fictício)' },
  ]
}

/** IDs dos grupos de demonstração: não existem no Entra real. */
const isFictitious = (id: string) => id.toLowerCase().startsWith('6f1e0c1a-0000-4000-8000-')
const fictitiousInGraph = computed(() => form.scope === 'groups' && form.transport === 'graph' && form.groups.some((g) => isFictitious(g.id)))
function removeFictitious() {
  form.groups = form.groups.filter((g) => !isFictitious(g.id))
}

function addGroup() {
  const id = newGroup.id.trim().toLowerCase()
  if (!/^[0-9a-f-]{36}$/.test(id) || form.groups.some((g) => g.id === id)) return
  form.groups.push({ id, name: newGroup.name.trim() })
  newGroup.id = ''
  newGroup.name = ''
}

async function submit() {
  const body: Record<string, unknown> = { ...form }
  if (form.credentialType === 'certificate' && secret.certificatePem.trim()) body.certificatePem = secret.certificatePem
  if (form.credentialType === 'client_secret' && secret.clientSecret.trim()) {
    body.clientSecret = secret.clientSecret
    body.clientSecretApproval = secret.clientSecretApproval
  }
  if (secret.credentialFileRef.trim()) body.credentialFileRef = secret.credentialFileRef.trim()
  if (needsAllConfirmation.value) body.confirmAllDirectory = confirmAll.value
  if (await save(body)) {
    secret.certificatePem = ''
    secret.clientSecret = ''
    secret.credentialFileRef = ''
    emit('changed')
  }
  logKey.value++
}

async function test() {
  if (await action('entra/directory-sync/test')) emit('changed')
  logKey.value++
}

async function run(kind: 'preview' | 'delta' | 'full') {
  try {
    const r = kind === 'preview' ? await api<SyncRun>('/admin/entra/sync/preview', { method: 'POST' }) : await api<SyncRun>('/admin/entra/sync/run', { method: 'POST', body: { mode: kind } })
    notify.info(kind === 'preview' ? 'Prévia em execução no servidor (nenhuma alteração será gravada).' : 'Sincronização solicitada; executa no worker.')
    await loadRuns()
    logKey.value++
    if (r.status !== 'queued' && r.status !== 'running') openRun(r.id)
  } catch (e) {
    notify.error(e)
  }
}
async function openRun(id: string) {
  detail.value = await api<SyncRun>(`/admin/entra/sync/runs/${id}`)
}

async function addMapping() {
  mapErrors.value = {}
  try {
    const name = importedGroups.value.find((g) => g.objectId === mapForm.entraGroupId)?.name ?? form.groups.find((g) => g.id === mapForm.entraGroupId)?.name ?? null
    await api('/admin/entra/mappings', { method: 'POST', body: { ...mapForm, entraGroupName: name, tmsaGroupKey: mapForm.tmsaGroupKey || null } })
    Object.assign(mapForm, { entraGroupId: '', tmsaGroupKey: '', tmsaRoles: [], privileged: false, justification: '' })
    notify.success('Mapeamento criado; papéis recalculados.')
    await loadMappings()
    logKey.value++
  } catch (e) {
    if (e instanceof ApiError) mapErrors.value = e.errors
    notify.error(e)
  }
}
async function toggleMapping(m: Mapping) {
  try {
    await api(`/admin/entra/mappings/${m.id}`, { method: 'PATCH', body: { enabled: !m.enabled } })
    await loadMappings()
    logKey.value++
  } catch (e) {
    notify.error(e)
  }
}
async function removeMapping(m: Mapping) {
  try {
    await api(`/admin/entra/mappings/${m.id}`, { method: 'DELETE' })
    await loadMappings()
    logKey.value++
  } catch (e) {
    notify.error(e)
  }
}

const RUN_TONE: Record<string, string> = { queued: 'neutral', running: 'blue', completed: 'green', partial: 'amber', failed: 'red', blocked: 'red' }
const RUN_LABEL: Record<string, string> = { queued: 'Na fila', running: 'Em andamento', completed: 'Concluída', partial: 'Parcial', failed: 'Falha', blocked: 'Bloqueada (trava)' }
const MODE_LABEL: Record<string, string> = { preview: 'Prévia', delta: 'Incremental', full: 'Completa' }
const TOTAL_LABEL: Record<string, string> = {
  created: 'Usuários criados', updated: 'Usuários atualizados', disabled: 'Usuários desativados', reenabled: 'Usuários reativados', unchanged: 'Usuários inalterados',
  skipped: 'Ignorados', excluded: 'Ignorados por política (convidado/desabilitado)', groups: 'Grupos lidos', groups_removed: 'Grupos removidos',
  memberships_added: 'Vínculos adicionados', memberships_removed: 'Vínculos removidos', roles_changed: 'Acessos efetivos alterados', pages: 'Páginas lidas do Graph',
  fetched: 'Leituras pontuais', errors: 'Erros por registro', full: 'Carga completa (1 = sim)', resumed: 'Retomada (1 = sim)', delta_reset: 'Cursor expirado → recarga (1 = sim)',
}
const allGroupOptions = computed(() => {
  const map = new Map<string, string>()
  form.groups.forEach((g) => map.set(g.id, g.name || g.id))
  importedGroups.value.forEach((g) => map.set(g.objectId, g.name || g.objectId))
  return [...map.entries()].map(([id, name]) => ({ id, name }))
})
</script>

<template>
  <SettingHeader :setting="setting" title="Microsoft Entra ID — importação de usuários e grupos" subtitle="Serviço backend com credencial de aplicação (client credentials) e permissões somente de leitura no Microsoft Graph. Não autentica pessoas, não depende da sessão de um administrador e nunca roda no navegador." />

  <div v-if="overview" class="ov">
    <div class="tm-metric"><div class="tm-metric-label">Usuários do diretório (ativos)</div><div class="tm-metric-value">{{ overview.counts.activeUsers }} <small class="tm-muted">/ {{ overview.counts.users }}</small></div></div>
    <div class="tm-metric"><div class="tm-metric-label">Grupos · vínculos</div><div class="tm-metric-value">{{ overview.counts.groups }} <small class="tm-muted">· {{ overview.counts.memberships }}</small></div></div>
    <div class="tm-metric"><div class="tm-metric-label">Última sincronização concluída</div><div class="tm-metric-value" style="font-size:14px">{{ fmtDateTime(overview.lastSuccessAt) }}</div></div>
    <div class="tm-metric"><div class="tm-metric-label">Cursor delta · próxima execução</div><div class="tm-metric-value" style="font-size:14px">{{ overview.cursor.present ? 'presente' : 'ausente' }}<template v-if="overview.cursor.resumable"> · retomável</template> · {{ overview.nextRunAt ? fmtDateTime(overview.nextRunAt) : 'manual' }}</div></div>
  </div>
  <div v-for="a in overview?.alerts ?? []" :key="a.code" class="tm-note warn" role="alert"><i class="pi pi-exclamation-triangle" aria-hidden="true" /><span><strong>{{ a.code }}</strong> — {{ a.message }}</span></div>

  <div class="grid">
    <section class="tm-panel">
      <div class="tm-panel-head"><div class="tm-panel-title">Configuração do app de sincronização</div>
        <label class="check" style="margin:0"><input v-model="form.enabled" type="checkbox" /> <span>Sincronização habilitada</span></label>
      </div>
      <div class="tm-panel-body">
        <div class="three">
          <div class="tm-field"><label for="d-tr">Transporte</label><select id="d-tr" v-model="form.transport"><option value="graph">Microsoft Graph (tenant real)</option><option value="mock">Diretório fictício (somente dev/homologação)</option></select><div v-if="err('transport')" class="tm-hint err">{{ err('transport') }}</div></div>
          <div class="tm-field"><label for="d-t">Tenant ID</label><input id="d-t" v-model="form.tenantId" :disabled="form.transport === 'mock'" /><div v-if="err('tenantId')" class="tm-hint err">{{ err('tenantId') }}</div></div>
          <div class="tm-field"><label for="d-c">Client ID do app de sincronização</label><input id="d-c" v-model="form.clientId" :disabled="form.transport === 'mock'" /><div v-if="err('clientId')" class="tm-hint err">{{ err('clientId') }}</div></div>
        </div>
        <template v-if="form.transport === 'graph'">
          <div class="tm-field"><label for="d-ct">Credencial</label>
            <select id="d-ct" v-model="form.credentialType">
              <option value="certificate">Certificado (recomendado em produção)</option>
              <option value="federated">Workload identity federation (arquivo de token montado)</option>
              <option value="client_secret">Client secret (somente com aprovação e cofre)</option>
            </select>
            <div class="tm-hint">Identidade gerenciada não está disponível neste ambiente on-premises. Credencial atual: <strong>{{ setting?.value?.credential?.configured ? `definida (${setting?.value?.credential?.source})` : 'não definida' }}</strong><template v-if="setting?.value?.credential?.updatedAt"> em {{ fmtDateTime(setting?.value?.credential?.updatedAt) }}</template>.</div>
          </div>
          <div v-if="form.credentialType === 'certificate'" class="tm-field">
            <label for="d-pem">Certificado + chave privada (PEM) — somente escrita</label>
            <textarea id="d-pem" v-model="secret.certificatePem" autocomplete="off" spellcheck="false" placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----BEGIN PRIVATE KEY-----" />
            <div class="tm-hint" v-if="setting?.value?.certificate">Atual: thumbprint {{ setting.value.certificate.thumbprint }} · expira {{ fmtDateTime(setting.value.certificate.notAfter) }}</div>
            <div v-if="err('certificatePem')" class="tm-hint err">{{ err('certificatePem') }}</div>
          </div>
          <div v-if="form.credentialType === 'client_secret'" class="two">
            <div class="tm-field"><label for="d-cs">Client secret — somente escrita</label><input id="d-cs" v-model="secret.clientSecret" type="password" autocomplete="new-password" /></div>
            <div class="tm-field"><label for="d-ap">Referência da aprovação</label><input id="d-ap" v-model="secret.clientSecretApproval" placeholder="Chamado/ADR de segurança" /><div v-if="err('clientSecretApproval')" class="tm-hint err">{{ err('clientSecretApproval') }}</div></div>
          </div>
          <div class="tm-field"><label for="d-fr">Ou referência a segredo montado (Docker secret / secret manager)</label><input id="d-fr" v-model="secret.credentialFileRef" placeholder="file:entra_sync_certificate.pem" /><div v-if="err('credentialFileRef')" class="tm-hint err">{{ err('credentialFileRef') }}</div></div>
        </template>

        <h2 style="margin:12px 0 6px">Escopo da importação</h2>
        <div class="tm-field"><label for="d-sc">O que importar</label>
          <select id="d-sc" v-model="form.scope">
            <option value="groups">Membros diretos dos grupos selecionados (recomendado)</option>
            <option value="all">Todo o diretório — todos os grupos e usuários do tenant</option>
          </select>
          <div v-if="err('scope')" class="tm-hint err">{{ err('scope') }}</div>
        </div>
        <template v-if="form.scope === 'all'">
          <div class="tm-note warn" role="note" style="margin-bottom:6px"><i class="pi pi-exclamation-triangle" aria-hidden="true" /><span>Importa <strong>todos os usuários</strong> (nome, e-mail, UPN, departamento) e <strong>todos os grupos</strong> do tenant — inclusive grupos do Teams/M365 e listas de distribuição. Convidados e contas desabilitadas seguem os filtros abaixo. Nenhum grupo concede acesso sem mapeamento. Grupos que deixarem de existir são marcados como removidos (com trava de segurança).</span></div>
          <label v-if="needsAllConfirmation" class="check"><input v-model="confirmAll" type="checkbox" /> <span>Estou ciente e assumo a importação de todo o diretório (registrado na auditoria).</span></label>
        </template>
        <template v-else>
        <table class="tbl" v-if="form.groups.length"><tbody>
          <tr v-for="(g, i) in form.groups" :key="g.id"><td><code>{{ g.id }}</code></td><td>{{ g.name }}<span v-if="form.transport === 'graph' && isFictitious(g.id)" class="tm-hint err"> · fictício — não existe no Entra</span></td><td><button class="tm-link-btn" @click="form.groups.splice(i, 1)">remover</button></td></tr>
        </tbody></table>
        <div v-if="fictitiousInGraph" class="tm-note warn" role="alert" style="margin-bottom:6px"><i class="pi pi-exclamation-triangle" aria-hidden="true" /><span>Há grupos do diretório fictício com o transporte <strong>Microsoft Graph</strong>. Informe os Object IDs dos grupos reais (Entra → Grupos → Visão geral → Object ID). <button class="tm-link-btn" @click="removeFictitious">Remover grupos fictícios</button></span></div>
        <button v-if="form.transport === 'mock'" class="tm-btn small ghost" style="margin-bottom:6px" @click="useMockGroups">Usar grupos do diretório fictício</button>
        <div class="add"><input v-model="newGroup.id" placeholder="Object ID do grupo" aria-label="Object ID do grupo" /><input v-model="newGroup.name" placeholder="Nome (referência)" aria-label="Nome do grupo" /><button class="tm-btn small" @click="addGroup">Adicionar</button></div>
        <div v-if="err('groups')" class="tm-hint err">{{ err('groups') }}</div>
        </template>

        <div class="three" style="margin-top:10px">
          <label class="check"><input v-model="form.includeGuests" type="checkbox" /> <span>Importar convidados (guests)</span></label>
          <label class="check"><input v-model="form.includeDisabled" type="checkbox" /> <span>Importar contas desabilitadas (inativas)</span></label>
          <label class="check"><input v-model="form.photos.enabled" type="checkbox" /> <span>Importar a foto do perfil de quem usa o BPM (miniatura; usa User.Read.All, sem permissão nova). Ao desligar, as fotos armazenadas são apagadas.</span></label>
          <div />
          <div class="tm-field"><label for="d-s">Periodicidade</label><select id="d-s" v-model.number="form.scheduleMinutes"><option :value="0">Manual</option><option :value="60">A cada hora</option><option :value="240">A cada 4 horas</option><option :value="720">A cada 12 horas</option><option :value="1440">Diária</option></select></div>
          <div class="tm-field"><label for="d-st">Alertar sem sincronização após (horas)</label><input id="d-st" v-model.number="form.staleAfterHours" type="number" min="1" max="720" /></div>
        </div>
        <div class="tm-actions"><button class="tm-btn primary" :disabled="saving" @click="submit">Salvar configuração</button></div>
      </div>
    </section>

    <section class="tm-panel">
      <div class="tm-panel-head"><div class="tm-panel-title">Permissões e checklist</div></div>
      <div class="tm-panel-body">
        <table class="tbl"><thead><tr><th>Permissão</th><th>Tipo</th><th>Motivo</th></tr></thead><tbody>
          <tr v-for="p in setting?.value?.permissions ?? []" :key="p.permission"><td><code>{{ p.permission }}</code></td><td><strong>{{ p.type }}</strong></td><td>{{ p.why }}</td></tr>
        </tbody></table>
        <div class="tm-hint" style="margin:4px 0 8px">No portal: App Registration → API permissions → Add a permission → Microsoft Graph → <strong>Application permissions</strong> (não "Delegated") → marque as permissões acima → <strong>Grant admin consent</strong>. Permissões Delegated não entram no token da importação, que roda sem usuário logado.</div>
        <label v-for="c in CHECKLIST" :key="c.key" class="check"><input v-model="form.checklist[c.key]" type="checkbox" /> <span>{{ c.label }}</span></label>
      </div>
      <div class="tm-panel-head" style="border-top:1px solid #edf1f4"><div class="tm-panel-title">Ações</div></div>
      <div class="tm-panel-body steps">
        <button class="tm-btn" :disabled="saving" @click="test"><i class="pi pi-bolt" aria-hidden="true" /> Testar conexão e permissões</button>
        <button class="tm-btn" :disabled="!!activeRun" @click="run('preview')"><i class="pi pi-eye" aria-hidden="true" /> Prévia (dry run)</button>
        <button class="tm-btn primary" :disabled="!!activeRun || !form.enabled" @click="run('delta')"><i class="pi pi-sync" aria-hidden="true" /> Sincronizar agora</button>
        <button class="tm-btn ghost" :disabled="!!activeRun || !form.enabled" @click="run('full')">Recarga completa (controlada)</button>
        <p class="tm-hint">Falha parcial nunca avança o checkpoint nem desativa usuários em massa (trava de segurança). Exclusões no diretório desativam o acesso, sem apagar histórico.</p>
      </div>
    </section>
  </div>

  <section class="tm-panel" style="margin-top:16px">
    <div class="tm-panel-head"><div class="tm-panel-title">Execuções</div><button class="tm-btn small ghost" @click="loadRuns"><i class="pi pi-refresh" aria-hidden="true" /> Atualizar</button></div>
    <div style="overflow-x:auto"><table class="tm-table">
      <thead><tr><th>Início</th><th>Tipo</th><th>Estado</th><th>Duração</th><th>Criados</th><th>Atualizados</th><th>Desativados</th><th>Inalterados</th><th>Erros</th><th>Cursor</th><th /></tr></thead>
      <tbody>
        <tr v-if="!runs.length"><td colspan="11" class="tm-empty">Nenhuma execução.</td></tr>
        <tr v-for="r in runs" :key="r.id">
          <td>{{ fmtDateTime(r.startedAt ?? r.createdAt) }}<div class="tm-hint">{{ r.requestedBy }} · {{ r.trigger }}</div></td>
          <td>{{ MODE_LABEL[r.mode] }}<span v-if="r.transport === 'mock'" class="tm-pill neutral" style="margin-left:4px">mock</span></td>
          <td><span class="tm-pill" :class="RUN_TONE[r.status]">{{ RUN_LABEL[r.status] }}</span><div v-if="r.errorCode" class="tm-hint"><code>{{ r.errorCode }}</code></div><div v-if="r.errorMessage" class="tm-hint err-msg">{{ r.errorMessage }}</div></td>
          <td>{{ r.durationMs != null ? `${(r.durationMs / 1000).toFixed(1)} s` : '—' }}</td>
          <td>{{ r.totals.created ?? 0 }}</td><td>{{ r.totals.updated ?? 0 }}</td><td>{{ r.totals.disabled ?? 0 }}</td><td>{{ r.totals.unchanged ?? 0 }}</td><td>{{ r.errorsCount }}</td>
          <td>{{ r.cursorAdvanced ? 'avançou' : 'mantido' }}</td>
          <td><button class="tm-link-btn" @click="openRun(r.id)">Detalhes</button></td>
        </tr>
      </tbody>
    </table></div>
  </section>

  <section class="tm-panel" style="margin-top:16px">
    <div class="tm-panel-head"><div><div class="tm-panel-title">Mapeamentos grupo Entra → TMSA</div><div class="tm-panel-caption">Negar por padrão: grupo importado sem regra não concede papel nem raia. Papéis privilegiados exigem confirmação e justificativa.</div></div></div>
    <div style="overflow-x:auto"><table class="tm-table">
      <thead><tr><th>Grupo Entra</th><th>Raia TMSA</th><th>Papéis</th><th>Privilegiado</th><th>Situação</th><th>Criado por</th><th /></tr></thead>
      <tbody>
        <tr v-if="!mappings.length"><td colspan="7" class="tm-empty">Nenhum mapeamento: usuários importados não recebem acesso.</td></tr>
        <tr v-for="m in mappings" :key="m.id">
          <td>{{ m.entraGroupName || '—' }}<div class="tm-hint"><code>{{ m.entraGroupId }}</code></div></td>
          <td>{{ tmsaGroups.find((g) => g.key === m.tmsaGroupKey)?.name ?? '—' }}</td>
          <td><span v-for="r in m.tmsaRoles" :key="r" class="tm-pill" :class="privilegedRoles.includes(r) ? 'red' : 'neutral'" style="margin:1px">{{ r }}</span></td>
          <td>{{ m.privileged ? 'Sim' : 'Não' }}<div v-if="m.justification" class="tm-hint">{{ m.justification }}</div></td>
          <td>{{ m.enabled ? 'Ativo' : 'Inativo' }}</td><td>{{ m.createdBy }}</td>
          <td class="tm-actions"><button class="tm-link-btn" @click="toggleMapping(m)">{{ m.enabled ? 'Desativar' : 'Ativar' }}</button><button class="tm-link-btn" style="color:var(--red)" @click="removeMapping(m)">Remover</button></td>
        </tr>
      </tbody>
    </table></div>
    <div class="tm-panel-body map-form">
      <div class="tm-field"><label for="m-g">Grupo Entra</label><select id="m-g" v-model="mapForm.entraGroupId"><option value="">Selecione...</option><option v-for="g in allGroupOptions" :key="g.id" :value="g.id">{{ g.name }}</option></select></div>
      <div class="tm-field"><label for="m-t">Raia/grupo TMSA</label><select id="m-t" v-model="mapForm.tmsaGroupKey"><option value="">Nenhuma</option><option v-for="g in tmsaGroups" :key="g.key" :value="g.key">{{ g.name }}</option></select></div>
      <div class="tm-field"><label>Papéis</label><MultiSelect v-model="mapForm.tmsaRoles" :options="roles" placeholder="Nenhum" display="chip" style="width:100%" aria-label="Papéis" /></div>
      <label v-if="needsPrivilegedFlag" class="check"><input v-model="mapForm.privileged" type="checkbox" /> <span>Confirmo que este grupo deve receber papel privilegiado</span></label>
      <div v-if="needsPrivilegedFlag" class="tm-field"><label for="m-j">Justificativa (aprovação)</label><input id="m-j" v-model="mapForm.justification" /></div>
      <div v-if="Object.keys(mapErrors).length" class="tm-hint err">{{ Object.values(mapErrors)[0]?.[0] }}</div>
      <button class="tm-btn primary small" :disabled="!mapForm.entraGroupId" @click="addMapping">Adicionar mapeamento</button>
    </div>
  </section>

  <IntegrationLog
    area="entra"
    title="Log do Entra ID"
    caption="Importações (com as etapas de cada execução), testes de conexão e permissões, mapeamentos, usuários importados/desativados e alterações da configuração. Em “Entra ID — tudo” entram também os logins Microsoft e os tokens recusados pela API. Clique em um registro para ver o que significa o erro e o que fazer."
    :kind-labels="{ sync: 'Importações', test: 'Testes', mapping: 'Mapeamentos', user: 'Usuários', config: 'Configuração', access: 'Logins' }"
    subsystem="directory"
    show-subsystem
    :refresh-key="logKey"
  />

  <Dialog :visible="!!detail" modal :header="detail ? `Execução — ${MODE_LABEL[detail.mode]} (${RUN_LABEL[detail.status]})` : ''" :style="{ width: 'min(820px, 95vw)' }" @update:visible="(v) => { if (!v) detail = null }">
    <template v-if="detail">
      <p v-if="detail.errorMessage" class="tm-note err"><i class="pi pi-times-circle" aria-hidden="true" /><span><strong v-if="detail.errorCode"><code>{{ detail.errorCode }}</code> — </strong>{{ detail.errorMessage }}</span></p>
      <div v-if="detail.explain" class="tm-note warn"><i class="pi pi-question-circle" aria-hidden="true" /><span><strong>{{ detail.explain.title }}.</strong> {{ detail.explain.cause }}<br /><strong>O que fazer:</strong> {{ detail.explain.action }}</span></div>
      <template v-if="detail.log?.length">
        <h2 style="margin:14px 0 6px">Log da execução ({{ detail.log.length }} etapa(s))</h2>
        <ol class="runlog">
          <li v-for="(l, i) in detail.log" :key="i" :class="l.level">
            <span class="t">+{{ (l.ms / 1000).toFixed(1) }} s</span>
            <i class="pi" :class="l.level === 'success' ? 'pi-check' : l.level === 'error' ? 'pi-times' : l.level === 'warning' ? 'pi-exclamation-triangle' : 'pi-angle-right'" aria-hidden="true" />
            <span>{{ l.message }}<code v-if="l.data?.code" style="margin-left:6px">{{ l.data.code }}</code></span>
          </li>
        </ol>
      </template>
      <p v-else class="tm-hint">Execução anterior ao log por etapa: somente os totais abaixo.</p>
      <h2 style="margin:14px 0 6px">Totais</h2>
      <dl class="tm-kv"><template v-for="(v, k) in detail.totals" :key="k"><dt>{{ TOTAL_LABEL[k] ?? k }}</dt><dd>{{ v }}</dd></template><dt>Correlation ID</dt><dd><code>{{ detail.correlationId }}</code></dd></dl>
      <template v-if="detail.plan?.changes?.length">
        <h2 style="margin:14px 0 6px">Mudanças planejadas (prévia)</h2>
        <p class="tm-hint">{{ detail.plan.note }}</p>
        <table class="tbl"><tbody><tr v-for="(c, i) in detail.plan.changes" :key="i"><td>{{ c.action }}</td><td>{{ c.type }}</td><td>{{ c.label }}</td><td class="tm-muted">{{ c.hint }}</td><td>{{ c.access ? [...c.access.roles, ...c.access.groups].join(', ') : '' }}</td></tr></tbody></table>
      </template>
      <template v-if="detail.errors?.length">
        <h2 style="margin:14px 0 6px">Erros por registro</h2>
        <table class="tbl"><tbody><tr v-for="(e, i) in detail.errors" :key="i"><td>{{ e.type }}</td><td><code>{{ e.id }}</code></td><td>{{ e.code }}</td><td>{{ e.message }}</td></tr></tbody></table>
      </template>
    </template>
  </Dialog>
</template>

<style scoped>
.ov { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 10px; }
@media (max-width: 900px) { .ov { grid-template-columns: repeat(2, 1fr); } }
.grid { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 16px; align-items: start; margin-top: 12px; }
@media (max-width: 1300px) { .grid { grid-template-columns: 1fr; } }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.three { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; align-items: end; }
.check { display: flex; gap: 8px; align-items: flex-start; font-size: 12px; margin-bottom: 9px; color: #43586a; }
.steps { display: flex; flex-direction: column; gap: 8px; }
.tbl { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 8px; }
.tbl th { text-align: left; font-size: 10px; color: #788696; text-transform: uppercase; padding: 5px; }
.tbl td { border-top: 1px solid #edf1f4; padding: 5px; vertical-align: top; }
.add { display: flex; gap: 6px; }
.add input { flex: 1; height: 32px; border: 1px solid var(--line-strong); border-radius: 7px; padding: 0 8px; font-size: 12px; }
.map-form { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; align-items: end; border-top: 1px solid #edf1f4; }
.err { color: var(--red); }
.err-msg { color: #87413a; max-width: 260px; }
.runlog { margin: 0; list-style: none; display: flex; flex-direction: column; gap: 4px; font-size: 12px; max-height: 360px; overflow: auto; border: 1px solid #edf1f4; border-radius: 8px; padding: 8px; background: #fbfcfd; }
.runlog li { display: grid; grid-template-columns: 56px 14px 1fr; gap: 6px; align-items: start; }
.runlog .t { color: #8a98a6; font-variant-numeric: tabular-nums; }
.runlog .pi { margin-top: 2px; font-size: 11px; color: #8a98a6; }
.runlog li.success .pi { color: var(--green); }
.runlog li.warning .pi { color: var(--amber); }
.runlog li.error .pi { color: var(--red); }
code { font-size: 10px; background: #eef2f5; padding: 1px 4px; border-radius: 4px; }
</style>
