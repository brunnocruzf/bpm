<script setup lang="ts">
/**
 * Log de uma integração em Configurações (e-mail e notificações · Entra ID): testes, configuração,
 * entregas por tentativa, execuções da importação por etapa e acessos — com código, explicação
 * ("o que significa / o que fazer"), duração e correlation ID. Somente leitura; dados já redigidos.
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { api, errorMessage } from '@/api/http'
import { fmtDateTime } from '@/composables/format'

interface Explain { title: string; cause: string; action: string }
interface Step { name: string; ok: boolean; level: string; code?: string | null; message?: string | null; detail?: string | null; durationMs?: number | null; at?: string | null; explain?: Explain | null }
interface Fact { label: string; value: string; type?: string; instanceId?: string }
export interface LogEntry {
  id: string; at: string; kind: string; subsystem: string; level: 'success' | 'info' | 'warning' | 'error'
  title: string; summary: string | null; actor: string | null; code: string | null; explain: Explain | null
  durationMs: number | null; correlationId: string | null; steps: Step[]; facts: Fact[]
  diff?: { field: string; from: string | null; to: string | null }[]; records?: { type: string; id: string | null; code: string; message: string }[]
}
interface Stats { since: string; testsOk: number; testsFailed: number; deliveries?: Record<string, number>; runs?: Record<string, number>; logins?: number; rejectedTokens?: number }

const props = withDefaults(defineProps<{
  area: 'email' | 'entra' | 'storage'
  title: string
  caption?: string
  kindLabels: Record<string, string>
  /** origem inicial no log do Entra (login | directory); null = Entra ID como um todo */
  subsystem?: 'login' | 'directory' | null
  showSubsystem?: boolean
  /** incrementado pelo pai após salvar/testar/sincronizar para recarregar */
  refreshKey?: number
}>(), { caption: '', subsystem: null, showSubsystem: false, refreshKey: 0 })

const filters = reactive({ kinds: Object.keys(props.kindLabels), subsystem: props.subsystem as string | null, since: '7d', failures: false, q: '' })
const entries = ref<LogEntry[]>([])
const stats = ref<Stats | null>(null)
const next = ref<string | null>(null)
const loading = ref(false)
const error = ref('')
const open = ref<Set<string>>(new Set())
const copied = ref<string | null>(null)
let debounce: number | undefined
let poll: number | undefined

const LEVEL_ICON: Record<string, string> = { success: 'pi-check-circle', info: 'pi-info-circle', warning: 'pi-exclamation-triangle', error: 'pi-times-circle' }
const LEVEL_LABEL: Record<string, string> = { success: 'Sucesso', info: 'Informação', warning: 'Alerta', error: 'Falha' }
const KIND_ICON: Record<string, string> = { test: 'pi-bolt', delivery: 'pi-envelope', config: 'pi-cog', sync: 'pi-sync', access: 'pi-sign-in', mapping: 'pi-sitemap', user: 'pi-user' }

function query(cursor?: string | null) {
  return {
    kind: filters.kinds.join(','), subsystem: filters.subsystem || undefined, since: filters.since || undefined,
    failures: filters.failures ? 1 : undefined, q: filters.q.trim() || undefined, limit: 30, cursor: cursor || undefined,
  }
}

async function load(more = false) {
  if (!filters.kinds.length) {
    entries.value = []
    next.value = null
    return
  }
  loading.value = true
  error.value = ''
  try {
    const res = await api<{ data: LogEntry[]; next: string | null; stats: Stats }>(`/admin/settings/logs/${props.area}`, { query: query(more ? next.value : null) })
    entries.value = more ? [...entries.value, ...res.data] : res.data
    next.value = res.next
    if (!more) stats.value = res.stats
    // execução/entrega ainda em andamento: atualiza sozinho
    window.clearTimeout(poll)
    if (!more && res.data.some((e) => (e.kind === 'sync' || e.kind === 'delivery') && e.level === 'info')) poll = window.setTimeout(() => load(), 4000)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}

function toggleKind(k: string) {
  filters.kinds = filters.kinds.includes(k) ? filters.kinds.filter((x) => x !== k) : [...filters.kinds, k]
}
function toggle(id: string) {
  const s = new Set(open.value)
  if (s.has(id)) s.delete(id)
  else s.add(id)
  open.value = s
}
async function copy(text: string) {
  try {
    await navigator.clipboard.writeText(text)
    copied.value = text
    window.setTimeout(() => (copied.value = null), 1500)
  } catch {
    /* sem permissão de clipboard */
  }
}
const ms = (v: number | null | undefined) => (v == null ? null : v < 1000 ? `${v} ms` : `${(v / 1000).toFixed(1)} s`)

const statChips = computed(() => {
  const s = stats.value
  if (!s) return []
  const chips: { label: string; tone: string }[] = [{ label: `Testes: ${s.testsOk} ok · ${s.testsFailed} com falha`, tone: s.testsFailed ? 'amber' : 'neutral' }]
  if (s.deliveries) {
    const d = s.deliveries
    chips.push({ label: `Entregas: ${d.sent ?? 0} enviada(s) · ${d.failed ?? 0} falha(s) · ${(d.retrying ?? 0) + (d.queued ?? 0) + (d.sending ?? 0)} pendente(s) · ${d.skipped ?? 0} descartada(s)`, tone: d.failed ? 'red' : d.retrying ? 'amber' : 'neutral' })
  }
  if (s.runs) {
    const r = s.runs
    chips.push({ label: `Importações: ${(r.completed ?? 0)} concluída(s) · ${(r.partial ?? 0)} parcial(is) · ${(r.failed ?? 0) + (r.blocked ?? 0)} com falha/bloqueio`, tone: (r.failed ?? 0) + (r.blocked ?? 0) ? 'red' : (r.partial ? 'amber' : 'neutral') })
    chips.push({ label: `Logins Microsoft: ${s.logins ?? 0} · tokens recusados: ${s.rejectedTokens ?? 0}`, tone: s.rejectedTokens ? 'amber' : 'neutral' })
  }
  return chips
})
const sinceLabel: Record<string, string> = { '24h': 'últimas 24 h', '7d': 'últimos 7 dias', '30d': 'últimos 30 dias', '90d': 'últimos 90 dias', '': 'todo o período' }

watch(() => [filters.kinds.join(), filters.subsystem, filters.since, filters.failures], () => load())
watch(() => filters.q, () => {
  window.clearTimeout(debounce)
  debounce = window.setTimeout(() => load(), 350)
})
watch(() => props.refreshKey, () => load())
onMounted(() => load())
onBeforeUnmount(() => {
  window.clearTimeout(debounce)
  window.clearTimeout(poll)
})
</script>

<template>
  <section class="tm-panel ilog" :aria-label="title">
    <div class="tm-panel-head">
      <div><div class="tm-panel-title">{{ title }}</div><div v-if="caption" class="tm-panel-caption">{{ caption }}</div></div>
      <button class="tm-btn small ghost" :disabled="loading" @click="load()"><i class="pi pi-refresh" :class="{ 'pi-spin': loading }" aria-hidden="true" /> Atualizar</button>
    </div>

    <div class="tm-panel-body">
      <div v-if="statChips.length" class="stats" aria-label="Resumo do período">
        <span class="tm-muted">Resumo ({{ sinceLabel[filters.since] ?? 'últimos 30 dias' }}):</span>
        <span v-for="c in statChips" :key="c.label" class="tm-pill" :class="c.tone">{{ c.label }}</span>
      </div>

      <div class="toolbar" role="group" aria-label="Filtros do log">
        <div class="kinds">
          <button v-for="(label, k) in kindLabels" :key="k" type="button" class="chip" :class="{ on: filters.kinds.includes(k) }" :aria-pressed="filters.kinds.includes(k)" @click="toggleKind(k)">
            <i class="pi" :class="KIND_ICON[k] ?? 'pi-circle'" aria-hidden="true" /> {{ label }}
          </button>
        </div>
        <select v-if="showSubsystem" v-model="filters.subsystem" class="tm-select" aria-label="Origem">
          <option :value="null">Entra ID — tudo</option>
          <option value="login">Somente login da aplicação</option>
          <option value="directory">Somente importação de usuários e grupos</option>
        </select>
        <select v-model="filters.since" class="tm-select" aria-label="Período">
          <option value="24h">Últimas 24 h</option><option value="7d">Últimos 7 dias</option><option value="30d">Últimos 30 dias</option><option value="90d">Últimos 90 dias</option><option value="">Todo o período</option>
        </select>
        <label class="only"><input v-model="filters.failures" type="checkbox" /> Somente falhas e alertas</label>
        <input v-model="filters.q" class="tm-input search" type="search" placeholder="Buscar código, mensagem, usuário, assunto..." aria-label="Buscar no log" />
      </div>

      <p v-if="error" class="tm-note err" role="alert"><i class="pi pi-times-circle" aria-hidden="true" /><span>{{ error }}</span></p>
      <p v-else-if="!loading && !entries.length" class="tm-empty">Nenhum registro para os filtros escolhidos.</p>

      <ol class="entries">
        <li v-for="e in entries" :key="e.id" class="entry" :class="e.level">
          <button type="button" class="row" :aria-expanded="open.has(e.id)" @click="toggle(e.id)">
            <i class="pi lvl" :class="LEVEL_ICON[e.level]" aria-hidden="true" /><span class="tm-sr-only">{{ LEVEL_LABEL[e.level] }}:</span>
            <span class="when">{{ fmtDateTime(e.at) }}</span>
            <span class="main">
              <strong><i class="pi kind" :class="KIND_ICON[e.kind] ?? 'pi-circle'" aria-hidden="true" /> {{ e.title }}</strong>
              <span v-if="e.summary" class="summary">{{ e.summary }}</span>
            </span>
            <span class="badges">
              <code v-if="e.code">{{ e.code }}</code>
              <span v-if="ms(e.durationMs)" class="dur">{{ ms(e.durationMs) }}</span>
              <span v-if="e.actor" class="actor"><i class="pi pi-user" aria-hidden="true" /> {{ e.actor }}</span>
              <span v-if="e.steps.length" class="nsteps">{{ e.steps.length }} etapa(s)</span>
            </span>
            <i class="pi chev" :class="open.has(e.id) ? 'pi-chevron-up' : 'pi-chevron-down'" aria-hidden="true" />
          </button>

          <div v-if="open.has(e.id)" class="detail">
            <div v-if="e.explain" class="explain" :class="e.level">
              <strong>{{ e.explain.title }}</strong>
              <p><span class="k">O que significa:</span> {{ e.explain.cause }}</p>
              <p><span class="k">O que fazer:</span> {{ e.explain.action }}</p>
            </div>

            <template v-if="e.steps.length">
              <h3>{{ e.kind === 'delivery' ? 'Tentativas de entrega' : e.kind === 'sync' ? 'Etapas da execução' : 'Etapas verificadas' }}</h3>
              <ol class="steps">
                <li v-for="(s, i) in e.steps" :key="i" :class="s.level">
                  <i class="pi" :class="s.level === 'success' ? 'pi-check' : s.level === 'error' ? 'pi-times' : s.level === 'warning' ? 'pi-exclamation-triangle' : 'pi-angle-right'" aria-hidden="true" />
                  <div>
                    <div class="sname">{{ s.name }}<span v-if="s.at && e.kind === 'delivery'" class="tm-muted"> · {{ fmtDateTime(s.at) }}</span></div>
                    <div v-if="s.message" class="smsg">{{ s.message }}</div>
                    <div class="smeta">
                      <code v-if="s.code">{{ s.code }}</code>
                      <span v-if="s.detail">{{ s.detail }}</span>
                      <span v-if="ms(s.durationMs)">{{ ms(s.durationMs) }}</span>
                    </div>
                    <div v-if="s.explain && s.explain.title !== e.explain?.title" class="sexplain"><strong>{{ s.explain.title }}:</strong> {{ s.explain.action }}</div>
                  </div>
                </li>
              </ol>
            </template>

            <template v-if="e.facts.length">
              <h3>Detalhes</h3>
              <dl class="tm-kv">
                <template v-for="f in e.facts" :key="f.label">
                  <dt>{{ f.label }}</dt>
                  <dd>
                    <RouterLink v-if="f.instanceId" :to="{ name: 'instance', params: { id: f.instanceId } }">{{ f.value }}</RouterLink>
                    <template v-else>{{ f.type === 'datetime' ? fmtDateTime(f.value) : f.value }}</template>
                  </dd>
                </template>
              </dl>
            </template>

            <template v-if="e.diff?.length">
              <h3>Campos alterados</h3>
              <table class="tbl"><thead><tr><th>Campo</th><th>Antes</th><th>Depois</th></tr></thead><tbody>
                <tr v-for="d in e.diff" :key="d.field"><td><code>{{ d.field }}</code></td><td>{{ d.from ?? '—' }}</td><td>{{ d.to ?? '—' }}</td></tr>
              </tbody></table>
            </template>

            <template v-if="e.records?.length">
              <h3>Erros por registro ({{ e.records.length }})</h3>
              <table class="tbl"><tbody><tr v-for="(r, i) in e.records" :key="i"><td>{{ r.type }}</td><td><code>{{ r.id }}</code></td><td><code>{{ r.code }}</code></td><td>{{ r.message }}</td></tr></tbody></table>
            </template>

            <div class="meta">
              <span v-if="e.correlationId">Correlation ID <code>{{ e.correlationId }}</code>
                <button type="button" class="tm-link-btn" @click="copy(e.correlationId)">{{ copied === e.correlationId ? 'copiado' : 'copiar' }}</button>
              </span>
              <span class="tm-muted">Registro {{ e.id }}</span>
            </div>
          </div>
        </li>
      </ol>

      <div v-if="next" class="more"><button class="tm-btn small" :disabled="loading" @click="load(true)">{{ loading ? 'Carregando...' : 'Carregar mais' }}</button></div>
    </div>
  </section>
</template>

<style scoped>
.ilog { margin-top: 16px; }
.stats { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 10px; font-size: 11px; }
.toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 10px; }
.kinds { display: flex; flex-wrap: wrap; gap: 6px; }
.chip { display: inline-flex; gap: 6px; align-items: center; height: 30px; padding: 0 10px; border: 1px solid var(--line-strong); border-radius: 999px; background: #fff; color: #5d6f80; font-family: inherit; font-size: 12px; font-weight: 600; cursor: pointer; }
.chip.on { border-color: color-mix(in srgb, var(--brand) 45%, #fff); background: var(--brand-soft); color: var(--brand-text); }
.only { display: inline-flex; gap: 6px; align-items: center; font-size: 12px; color: #43586a; }
.search { flex: 1; min-width: 220px; }
.entries { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 6px; }
.entry { border: 1px solid var(--line); border-left: 3px solid #c9d3dc; border-radius: 8px; background: #fff; }
.entry.success { border-left-color: var(--green); }
.entry.warning { border-left-color: var(--amber); }
.entry.error { border-left-color: var(--red); }
.entry.info { border-left-color: #6a8bb0; }
.row { display: grid; grid-template-columns: 18px 112px minmax(0, 1fr) auto 14px; gap: 10px; align-items: start; width: 100%; padding: 9px 12px; border: 0; background: none; color: inherit; text-align: left; font: inherit; cursor: pointer; }
.row:hover { background: #fafbfc; }
.lvl { margin-top: 2px; }
.success .lvl { color: var(--green); }
.warning .lvl { color: var(--amber); }
.error .lvl { color: var(--red); }
.info .lvl { color: #4f76a3; }
.when { color: #6c7d8e; font-size: 11px; margin-top: 2px; white-space: nowrap; }
.main { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.main strong { font-size: 12px; color: #26394b; }
.kind { font-size: 11px; color: #8796a5; margin-right: 2px; }
.summary { font-size: 12px; color: #55697b; overflow-wrap: anywhere; }
.badges { display: flex; flex-wrap: wrap; gap: 6px; justify-content: flex-end; align-items: center; font-size: 11px; color: #6c7d8e; max-width: 360px; }
.chev { margin-top: 3px; color: #9aa6b2; font-size: 11px; }
.detail { padding: 4px 14px 12px 40px; border-top: 1px solid #eef2f5; font-size: 12px; }
.detail h3 { margin: 12px 0 6px; font-size: 11px; color: #788696; text-transform: uppercase; letter-spacing: 0.06em; }
.explain { margin-top: 10px; padding: 10px 12px; border-radius: 8px; background: var(--blue-soft); color: #2d4a68; }
.explain.error { background: var(--red-soft); color: #7a3a34; }
.explain.warning { background: var(--amber-soft); color: #6b4512; }
.explain p { margin: 4px 0 0; line-height: 1.5; }
.explain .k { font-weight: 700; }
.steps { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 6px; }
.steps li { display: grid; grid-template-columns: 16px 1fr; gap: 8px; align-items: start; }
.steps li .pi { margin-top: 2px; font-size: 12px; color: #8a98a6; }
.steps li.success .pi { color: var(--green); }
.steps li.error .pi { color: var(--red); }
.steps li.warning .pi { color: var(--amber); }
.sname { color: #2d4153; }
.smsg { color: #7a3a34; }
.smeta { display: flex; flex-wrap: wrap; gap: 8px; color: #7a8a99; font-size: 11px; }
.sexplain { margin-top: 2px; color: #6b4512; font-size: 11px; }
.tbl { width: 100%; border-collapse: collapse; font-size: 12px; }
.tbl th { text-align: left; font-size: 10px; color: #788696; text-transform: uppercase; padding: 5px; }
.tbl td { border-top: 1px solid #edf1f4; padding: 5px; vertical-align: top; overflow-wrap: anywhere; }
.meta { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 12px; color: #6c7d8e; font-size: 11px; }
.more { display: flex; justify-content: center; margin-top: 10px; }
code { font-size: 10.5px; background: #eef2f5; padding: 1px 4px; border-radius: 4px; overflow-wrap: anywhere; }
@media (max-width: 900px) {
  .row { grid-template-columns: 18px minmax(0, 1fr) 14px; }
  .when { grid-column: 2; grid-row: 1; }
  .main { grid-column: 2; }
  .badges { grid-column: 2; justify-content: flex-start; max-width: none; }
  .chev { grid-column: 3; grid-row: 1; }
  .detail { padding-left: 14px; }
}
</style>
