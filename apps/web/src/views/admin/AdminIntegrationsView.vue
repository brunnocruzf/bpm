<script setup lang="ts">
/**
 * Integrações e entregas (ADR-0016): catálogo de integrações REST no estilo das "fontes de dados" do
 * SoftExpert — conexões, importação do Swagger, endpoints, mapeamentos e execuções — além do outbox
 * do engine e das entregas de e-mail. Toda chamada externa acontece no servidor.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api/http'
import { useSession } from '@/stores/session'
import { useNotify } from '@/composables/notify'
import { fmtDateTime } from '@/composables/format'
import NotificationsPanel from '@/components/runtime/NotificationsPanel.vue'
import ConnectionsPanel from '@/components/integrations/ConnectionsPanel.vue'
import EndpointsPanel from '@/components/integrations/EndpointsPanel.vue'
import BindingsPanel from '@/components/integrations/BindingsPanel.vue'
import ExecutionsPanel from '@/components/integrations/ExecutionsPanel.vue'
import { ENV_LABEL, RESULT_LABEL, RESULT_TONE, type Connection } from '@/components/integrations/types'

interface Health {
  engine: { name: string; healthy: boolean }
  /** custom: mapeamento personalizado (transport INATIVO quando sem vínculo ativo) */
  ax: { operation: string; transport: string; configured: string | null; label: string | null; custom: boolean }[]
  connections: { id: string; key: string; name: string; system: string; enabled: boolean; environment: string; endpoints: number; enabledEndpoints: number; lastTestOk: boolean | null; lastTestedAt: string | null; openapiImportedAt: string | null; changedEndpoints: number }[]
  executions24h: Record<string, number>
  outbox: Record<string, number>
  notifications: Record<string, number>
  recentExecutions: { id: string; system: string; operation: string; transport: string; endpoint_key: string | null; http_status: number | null; result: string; attempt: number; duration_ms: number; created_at: string }[]
}
interface Command { id: string; command: string; state: string; attempts: number; process_instance_id: string | null; last_error: string | null; correlation_id: string | null; created_at: string; confirmed_at: string | null }

const session = useSession()
const notify = useNotify()
const route = useRoute()
const router = useRouter()
const health = ref<Health | null>(null)
const commands = ref<Command[]>([])
const connections = ref<Connection[]>([])
const filter = ref('PENDING,SENDING,RECONCILIATION_REQUIRED,FAILED')

const canConfig = computed(() => session.can('settingsManage'))
const canOps = computed(() => session.can('processesManage') || session.can('auditRead') || canConfig.value)
const TABS = computed(() => [
  { key: 'overview', label: 'Visão geral', show: true },
  { key: 'connections', label: 'Conexões', show: canConfig.value },
  { key: 'endpoints', label: 'Endpoints (fontes de dados)', show: canConfig.value },
  { key: 'bindings', label: 'Mapeamentos', show: canConfig.value },
  { key: 'executions', label: 'Execuções', show: canOps.value },
  { key: 'engine', label: 'Engine e entregas', show: true },
].filter((t) => t.show))
const tab = ref(String(route.query.tab ?? 'overview'))
watch(tab, (t) => router.replace({ query: { ...route.query, tab: t } }))

async function load() {
  try {
    const [h, c] = await Promise.all([api<Health>('/admin/integrations'), api<{ data: Command[] }>('/admin/engine-commands', { query: { state: filter.value } })])
    health.value = h
    commands.value = c.data
    if (canConfig.value) connections.value = (await api<{ data: Connection[] }>('/admin/integrations/connections')).data
  } catch (e) {
    notify.error(e)
  }
}

async function retry(c: Command) {
  try {
    await api(`/admin/engine-commands/${c.id}/retry`, { method: 'POST' })
    notify.success('Comando reenviado para reconciliação (o estado do engine é consultado antes de repetir).')
    load()
  } catch (e) {
    notify.error(e)
  }
}

const execTotal = computed(() => Object.values(health.value?.executions24h ?? {}).reduce((a, b) => a + Number(b), 0))
const execFailures = computed(() => Object.entries(health.value?.executions24h ?? {}).filter(([r]) => !['success', 'not_found'].includes(r)).reduce((a, [, n]) => a + Number(n), 0))
const mockOps = computed(() => (health.value?.ax ?? []).filter((a) => a.transport === 'MOCK'))
const inactiveCustom = computed(() => (health.value?.ax ?? []).filter((a) => a.custom && a.transport === 'INATIVO'))
const alerts = computed(() => {
  const out: { tone: string; text: string; tab?: string }[] = []
  for (const c of health.value?.connections ?? []) {
    if (c.lastTestOk === false) out.push({ tone: 'err', text: `Conexão ${c.name}: o último teste falhou.`, tab: 'connections' })
    if (c.changedEndpoints) out.push({ tone: 'warn', text: `Conexão ${c.name}: ${c.changedEndpoints} endpoint(s) com contrato alterado no Swagger — reimporte e revise.`, tab: 'endpoints' })
  }
  if (execFailures.value) out.push({ tone: 'warn', text: `${execFailures.value} chamada(s) com falha nas últimas 24 h.`, tab: 'executions' })
  if ((health.value?.executions24h?.unknown ?? 0) > 0) out.push({ tone: 'err', text: 'Há escrita(s) com resultado incerto aguardando reconciliação.', tab: 'executions' })
  if (mockOps.value.length) out.push({ tone: 'info', text: `${mockOps.value.length} operação(ões) do BPM ainda em MOCK (dados simulados): ${mockOps.value.map((m) => m.operation).join(', ')}.`, tab: 'bindings' })
  if (inactiveCustom.value.length) out.push({ tone: 'warn', text: `${inactiveCustom.value.length} mapeamento(s) personalizado(s) inativo(s) — formulários e etapas que os usam vão falhar: ${inactiveCustom.value.map((m) => m.label ?? m.operation).join(', ')}.`, tab: 'bindings' })
  return out
})
const tone = (s: string) => (s === 'CONFIRMED' || s === 'SENT' ? 'green' : s === 'FAILED' ? 'red' : s === 'SKIPPED' ? 'neutral' : 'amber')
onMounted(load)
</script>

<template>
  <div class="tm-crumbs"><span>Administração</span><span class="sep">›</span><span>Integrações e entregas</span></div>
  <div class="tm-heading">
    <div><h1>Integrações e entregas</h1><p class="tm-subtitle">Catálogo de integrações REST (conexões, endpoints importados do Swagger, mapeamentos e execuções), outbox do engine e entregas de e-mail. Chamadas externas só pelo servidor; nenhum segredo é exibido.</p></div>
    <button class="tm-btn" @click="load"><i class="pi pi-refresh" /> Atualizar</button>
  </div>

  <nav class="tm-tabbar" aria-label="Seções">
    <button v-for="t in TABS" :key="t.key" class="tm-tab" :class="{ active: tab === t.key }" :aria-current="tab === t.key ? 'page' : undefined" @click="tab = t.key">{{ t.label }}</button>
  </nav>

  <!-- Visão geral -->
  <template v-if="tab === 'overview'">
    <template v-if="health">
      <div class="tm-metrics">
        <div class="tm-metric"><div class="tm-metric-label">Flowable REST</div><div class="tm-metric-value" :style="{ color: health.engine.healthy ? 'var(--green)' : 'var(--red)' }">{{ health.engine.healthy ? 'Disponível' : 'Indisponível' }}</div></div>
        <div class="tm-metric"><div class="tm-metric-label">Conexões habilitadas</div><div class="tm-metric-value">{{ health.connections.filter((c) => c.enabled).length }} <small class="tm-muted">/ {{ health.connections.length }}</small></div></div>
        <div class="tm-metric"><div class="tm-metric-label">Chamadas (24 h)</div><div class="tm-metric-value">{{ execTotal }} <small :style="{ color: execFailures ? 'var(--red)' : 'var(--muted)' }">· {{ execFailures }} falha(s)</small></div></div>
        <div class="tm-metric"><div class="tm-metric-label">Engine · e-mails</div><div class="tm-metric-value" style="font-size:15px">{{ (health.outbox.RECONCILIATION_REQUIRED ?? 0) + (health.outbox.FAILED ?? 0) }} cmd. pendente(s) · {{ health.notifications.failed ?? 0 }} e-mail(s) com falha</div></div>
      </div>

      <div v-for="a in alerts" :key="a.text" class="tm-note" :class="a.tone === 'err' ? 'err' : a.tone === 'warn' ? 'warn' : ''" style="margin-top:6px">
        <i class="pi" :class="a.tone === 'info' ? 'pi-info-circle' : 'pi-exclamation-triangle'" aria-hidden="true" />
        <span>{{ a.text }} <button v-if="a.tab && TABS.some((t) => t.key === a.tab)" class="tm-link-btn" @click="tab = a.tab">ver</button></span>
      </div>

      <div class="ov-grid">
        <section class="tm-panel">
          <div class="tm-panel-head"><div class="tm-panel-title">Como integrar (passo a passo)</div></div>
          <ol class="howto">
            <li><strong>Conexão</strong> — URL base, ambiente e usuário de serviço (senha no cofre). Para o AX: <code>https://apidax.tmsa.ind.br</code>. <button v-if="canConfig" class="tm-link-btn" @click="tab = 'connections'">abrir</button></li>
            <li><strong>Importar do Swagger</strong> — prévia das operações por grupo; escolha o que importar (entram desabilitadas).</li>
            <li><strong>Parâmetros de saída</strong> — o Swagger do AX não descreve as respostas: cole um exemplo ou teste em homologação para definir os campos. <button v-if="canConfig" class="tm-link-btn" @click="tab = 'endpoints'">endpoints</button></li>
            <li><strong>Usar no BPM</strong> — publique consultas para os formulários ou mapeie as operações do PO 159 (consultas e transferência/bloqueio). <button v-if="canConfig" class="tm-link-btn" @click="tab = 'bindings'">mapeamentos</button></li>
            <li><strong>Habilitar e acompanhar</strong> — habilite endpoint e conexão; acompanhe em Execuções (com explicação de cada erro).</li>
          </ol>
        </section>
        <section class="tm-panel">
          <div class="tm-panel-head"><div class="tm-panel-title">Conexões</div></div>
          <table class="tm-table" style="min-width:0">
            <tbody>
              <tr v-for="c in health.connections" :key="c.id">
                <td><strong>{{ c.name }}</strong><div class="tm-hint">{{ c.system }} · {{ ENV_LABEL[c.environment] }}<template v-if="c.openapiImportedAt"> · Swagger importado {{ fmtDateTime(c.openapiImportedAt) }}</template></div></td>
                <td>{{ c.enabledEndpoints }}/{{ c.endpoints }} endpoint(s)</td>
                <td><span class="tm-pill" :class="!c.enabled ? 'neutral' : c.lastTestOk === false ? 'red' : c.lastTestOk ? 'green' : 'amber'">{{ !c.enabled ? 'desabilitada' : c.lastTestOk === false ? 'teste falhou' : c.lastTestOk ? 'ok' : 'não testada' }}</span></td>
              </tr>
              <tr v-if="!health.connections.length"><td class="tm-empty">Nenhuma conexão cadastrada.</td></tr>
            </tbody>
          </table>
        </section>
        <section class="tm-panel">
          <div class="tm-panel-head"><div class="tm-panel-title">Operações do BPM → transporte efetivo</div></div>
          <table class="tm-table" style="min-width:0">
            <tbody><tr v-for="a in health.ax" :key="a.operation"><td><code>{{ a.operation }}</code><div v-if="a.label" class="tm-hint">{{ a.label }}</div></td><td><span class="tm-pill" :class="a.transport === 'MOCK' ? 'amber' : a.transport.startsWith('REST') ? 'green' : a.transport === 'INATIVO' ? 'red' : 'blue'">{{ a.transport }}</span></td></tr></tbody>
          </table>
          <div class="tm-hint" style="padding:0 14px 12px">MOCK = dados simulados. Ao ativar um mapeamento, a operação passa a chamar o endpoint real. Escritas nunca usam SQL.</div>
        </section>
        <section class="tm-panel">
          <div class="tm-panel-head"><div class="tm-panel-title">Chamadas recentes</div><button v-if="canOps" class="tm-link-btn" @click="tab = 'executions'">todas</button></div>
          <ul class="tm-file-list" style="padding:0 14px 10px">
            <li v-for="e in health.recentExecutions.slice(0, 12)" :key="e.id">
              <span class="tm-pill" :class="RESULT_TONE(e.result)">{{ RESULT_LABEL[e.result] ?? e.result }}</span>
              <span>{{ e.operation }}<span class="tm-muted"> · {{ e.endpoint_key ?? e.transport }}{{ e.http_status ? ` · HTTP ${e.http_status}` : '' }} · {{ e.duration_ms }} ms</span></span>
            </li>
            <li v-if="!health.recentExecutions.length" class="tm-muted">Sem execuções.</li>
          </ul>
        </section>
      </div>
    </template>
    <div v-else class="tm-panel tm-empty">Carregando...</div>
  </template>

  <ConnectionsPanel v-else-if="tab === 'connections'" @changed="load" />
  <EndpointsPanel v-else-if="tab === 'endpoints'" :connections="connections" @changed="load" />
  <BindingsPanel v-else-if="tab === 'bindings'" @changed="load" />
  <ExecutionsPanel v-else-if="tab === 'executions'" />

  <!-- Engine e entregas -->
  <template v-else-if="tab === 'engine'">
    <section class="tm-panel">
      <div class="tm-panel-head">
        <div><div class="tm-panel-title">Outbox do engine</div><div class="tm-panel-caption">PENDING → SENDING → CONFIRMED · timeout é ambíguo e exige reconciliação</div></div>
        <select v-model="filter" class="tm-select" @change="load">
          <option value="PENDING,SENDING,RECONCILIATION_REQUIRED,FAILED">Pendentes e falhas</option>
          <option value="CONFIRMED">Confirmados</option>
          <option value="">Todos</option>
        </select>
      </div>
      <table class="tm-table" style="min-width:0">
        <thead><tr><th>Comando</th><th>Estado</th><th>Tentativas</th><th>Criado</th><th>Erro (mascarado)</th><th /></tr></thead>
        <tbody>
          <tr v-if="!commands.length"><td colspan="6" class="tm-empty">Nenhum comando neste filtro.</td></tr>
          <tr v-for="c in commands" :key="c.id">
            <td class="tm-strong">{{ c.command }}<div class="tm-flow-sub"><RouterLink v-if="c.process_instance_id" :to="{ name: 'instance', params: { id: c.process_instance_id } }">instância</RouterLink> · corr. {{ c.correlation_id?.slice(0, 8) }}</div></td>
            <td><span class="tm-pill" :class="tone(c.state)">{{ c.state }}</span></td>
            <td>{{ c.attempts }}</td>
            <td>{{ fmtDateTime(c.created_at) }}</td>
            <td style="max-width:280px;font-size:11px;word-break:break-word">{{ c.last_error ?? '—' }}</td>
            <td><button v-if="session.can('processesManage') && ['FAILED', 'RECONCILIATION_REQUIRED'].includes(c.state)" class="tm-btn small" @click="retry(c)">Reconciliar</button></td>
          </tr>
        </tbody>
      </table>
    </section>
    <NotificationsPanel v-if="session.can('processesManage')" />
  </template>
</template>

<style scoped>
.ov-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-top: 14px; }
@media (max-width: 1100px) { .ov-grid { grid-template-columns: 1fr; } }
.howto { margin: 0; padding: 4px 18px 14px 34px; font-size: 12px; line-height: 1.6; color: #43586a; }
.howto li { margin-bottom: 6px; }
code { font-size: 11px; background: #eef2f5; padding: 1px 4px; border-radius: 4px; }
</style>
