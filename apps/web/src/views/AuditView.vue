<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import Dialog from 'primevue/dialog'
import { api, apiBlob, saveBlob } from '@/api/http'
import type { AuditEventRow } from '@/api/types'
import { fmtDateTime } from '@/composables/format'
import { useNotify } from '@/composables/notify'
import { useSession } from '@/stores/session'

const session = useSession()
const notify = useNotify()
const filters = reactive({ from: '', to: '', actor: '', category: '', action: '', number: '', stageKey: '', outcome: '', correlationId: '', flowKey: '' })
const rows = ref<AuditEventRow[]>([])
const meta = ref({ total: 0, page: 1, perPage: 50, lastPage: 1 })
const loading = ref(false)
const detail = ref<AuditEventRow | null>(null)
const catalog = ref<{ categories: { key: string; label: string }[]; events: { key: string; label: string; category: string }[] }>({ categories: [], events: [] })
const sort = ref<'desc' | 'asc'>('desc')
const exporting = ref(false)

const events = computed(() => catalog.value.events.filter((e) => !filters.category || e.category === filters.category))
const categoryLabel = (k: string) => catalog.value.categories.find((c) => c.key === k)?.label ?? k
const OUTCOME: Record<string, { label: string; tone: string }> = { success: { label: 'Sucesso', tone: 'green' }, failure: { label: 'Falha', tone: 'red' }, blocked: { label: 'Bloqueado', tone: 'amber' } }

async function search(page = 1) {
  loading.value = true
  try {
    const res = await api<{ data: AuditEventRow[]; meta: typeof meta.value }>('/admin/audit-events', { query: { ...filters, page, perPage: meta.value.perPage, sort: sort.value } })
    rows.value = res.data
    meta.value = res.meta
  } catch (e) {
    notify.error(e)
  } finally {
    loading.value = false
  }
}
function clear() {
  Object.keys(filters).forEach((k) => ((filters as any)[k] = ''))
  search()
}
async function open(row: AuditEventRow) {
  detail.value = await api<AuditEventRow>(`/admin/audit-events/${row.id}`)
}
async function exportAs(format: 'csv' | 'json') {
  exporting.value = true
  try {
    const { blob, fileName } = await apiBlob('/admin/audit-events/export', { ...filters, format })
    saveBlob(blob, fileName ?? `auditoria.${format}`)
    notify.success('Exportação gerada (a própria exportação foi registrada na auditoria).')
  } catch (e) {
    notify.error(e)
  } finally {
    exporting.value = false
  }
}
function filterBy(field: keyof typeof filters, value: string | null) {
  if (!value) return
  filters[field] = value
  detail.value = null
  search()
}
const show = (v: unknown) => (v === null || v === undefined || v === '' ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v))

onMounted(async () => {
  catalog.value = await api('/admin/audit-events/catalog')
  await search()
})
</script>

<template>
  <div class="tm-crumbs"><RouterLink to="/">Início</RouterLink><span class="sep">/</span><span>Auditoria</span></div>
  <div class="tm-heading">
    <div>
      <h1>Trilha de auditoria</h1>
      <p class="tm-subtitle">Eventos funcionais append-only (separados dos logs técnicos): quem fez o quê, quando, em qual processo/etapa e com qual resultado. Valores sensíveis são redigidos na gravação.</p>
    </div>
    <div v-if="session.can('auditExport')" class="tm-actions">
      <button class="tm-btn" :disabled="exporting" @click="exportAs('csv')"><i class="pi pi-download" aria-hidden="true" /> Exportar CSV</button>
      <button class="tm-btn" :disabled="exporting" @click="exportAs('json')"><i class="pi pi-download" aria-hidden="true" /> Exportar JSON</button>
    </div>
  </div>

  <form class="tm-panel filters" @submit.prevent="search()">
    <div class="tm-field"><label for="f-from">De</label><input id="f-from" v-model="filters.from" type="date" /></div>
    <div class="tm-field"><label for="f-to">Até</label><input id="f-to" v-model="filters.to" type="date" /></div>
    <div class="tm-field"><label for="f-actor">Ator (nome, UPN ou ID)</label><input id="f-actor" v-model="filters.actor" /></div>
    <div class="tm-field"><label for="f-cat">Categoria</label><select id="f-cat" v-model="filters.category"><option value="">Todas</option><option v-for="c in catalog.categories" :key="c.key" :value="c.key">{{ c.label }}</option></select></div>
    <div class="tm-field"><label for="f-act">Evento</label><select id="f-act" v-model="filters.action"><option value="">Todos</option><option v-for="e in events" :key="e.key" :value="e.key">{{ e.label }}</option></select></div>
    <div class="tm-field"><label for="f-out">Resultado</label><select id="f-out" v-model="filters.outcome"><option value="">Todos</option><option value="success">Sucesso</option><option value="failure">Falha</option><option value="blocked">Bloqueado</option></select></div>
    <div class="tm-field"><label for="f-num">Protocolo</label><input id="f-num" v-model="filters.number" placeholder="IRR-000001/26" /></div>
    <div class="tm-field"><label for="f-flow">Fluxo (chave)</label><input id="f-flow" v-model="filters.flowKey" /></div>
    <div class="tm-field"><label for="f-stage">Etapa (chave)</label><input id="f-stage" v-model="filters.stageKey" /></div>
    <div class="tm-field"><label for="f-corr">Correlation ID</label><input id="f-corr" v-model="filters.correlationId" /></div>
    <div class="tm-actions" style="align-self:end"><button class="tm-btn primary" type="submit"><i class="pi pi-search" aria-hidden="true" /> Filtrar</button><button class="tm-btn ghost" type="button" @click="clear">Limpar</button></div>
  </form>

  <div class="tm-toolbar" style="margin-top:12px">
    <div class="tm-toolbar-left"><strong>{{ meta.total }}</strong> evento(s)</div>
    <div class="tm-toolbar-left">
      <label for="f-sort" class="tm-muted" style="font-size:12px">Ordem</label>
      <select id="f-sort" v-model="sort" class="tm-select" @change="search()"><option value="desc">Mais recentes</option><option value="asc">Mais antigos</option></select>
    </div>
  </div>
  <div class="tm-table-wrap">
    <table class="tm-table" :aria-busy="loading">
      <thead><tr><th>Data/hora</th><th>Evento</th><th>Resultado</th><th>Ator</th><th>Objeto</th><th>Processo</th><th>Correlação</th></tr></thead>
      <tbody>
        <tr v-if="!rows.length"><td colspan="7" class="tm-empty">{{ loading ? 'Carregando...' : 'Nenhum evento para os filtros.' }}</td></tr>
        <tr v-for="r in rows" :key="r.id" class="clickable" tabindex="0" @click="open(r)" @keydown.enter="open(r)">
          <td>{{ fmtDateTime(r.occurredAt) }}</td>
          <td><span class="tm-strong">{{ r.label }}</span><div class="tm-hint">{{ categoryLabel(r.category) }} · <code>{{ r.action }}</code></div></td>
          <td><span class="tm-pill" :class="OUTCOME[r.outcome]?.tone">{{ OUTCOME[r.outcome]?.label ?? r.outcome }}</span></td>
          <td>{{ r.actorName ?? '—' }}<div class="tm-hint">{{ r.actorType === 'system' ? 'sistema' : r.actorUpn }}</div></td>
          <td>{{ r.objectName ?? r.entityType }}<div class="tm-hint">{{ r.entityId }}</div></td>
          <td>{{ r.instanceNumber ?? r.flowKey ?? '—' }}<div class="tm-hint" v-if="r.stageKey">etapa {{ r.stageKey }}</div></td>
          <td><code class="small">{{ r.correlationId?.slice(0, 8) }}</code></td>
        </tr>
      </tbody>
    </table>
  </div>
  <div class="tm-actions" style="justify-content:flex-end;margin-top:10px">
    <button class="tm-btn small" :disabled="meta.page <= 1" @click="search(meta.page - 1)">Anterior</button>
    <span class="tm-muted" style="font-size:12px">Página {{ meta.page }} de {{ meta.lastPage }}</span>
    <button class="tm-btn small" :disabled="meta.page >= meta.lastPage" @click="search(meta.page + 1)">Próxima</button>
  </div>

  <Dialog :visible="!!detail" modal :header="detail?.label" :style="{ width: 'min(900px, 96vw)' }" @update:visible="(v) => { if (!v) detail = null }">
    <template v-if="detail">
      <dl class="tm-kv">
        <dt>Data/hora (UTC)</dt><dd>{{ detail.occurredAt }}</dd>
        <dt>Evento</dt><dd><code>{{ detail.action }}</code> · {{ categoryLabel(detail.category) }} · {{ OUTCOME[detail.outcome]?.label }}</dd>
        <dt>Ator</dt><dd>{{ detail.actorName }} ({{ detail.actorType === 'system' ? 'sistema' : 'usuário' }})<template v-if="detail.actorUpn"> · {{ detail.actorUpn }}</template><template v-if="detail.actorOid"> · oid {{ detail.actorOid }}</template></dd>
        <dt>Objeto</dt><dd>{{ detail.entityType }} {{ detail.entityId }}<template v-if="detail.objectName"> — {{ detail.objectName }}</template></dd>
        <dt>Processo</dt><dd>{{ show(detail.flowKey) }} v{{ show(detail.definitionVersion) }} · {{ show(detail.instanceNumber) }} · engine {{ show(detail.engineProcessInstanceId) }}</dd>
        <dt>Etapa / tarefa</dt><dd>{{ show(detail.stageKey) }} · {{ show(detail.taskId) }}</dd>
        <dt>Formulário (schema)</dt><dd>{{ show(detail.formSchemaVersionId) }}</dd>
        <dt>Correlation / causa</dt><dd><button class="tm-link-btn" @click="filterBy('correlationId', detail.correlationId)">{{ detail.correlationId }}</button> · {{ show(detail.causationId) }}</dd>
        <dt>Outbox / idempotência</dt><dd>{{ show(detail.outboxId) }} · {{ show(detail.idempotencyKey) }}</dd>
        <dt>Operação / origem</dt><dd>{{ show(detail.operation) }} · {{ show(detail.ipAddress) }}</dd>
      </dl>
      <template v-if="detail.diff && Object.keys(detail.diff).length">
        <h2 style="margin:14px 0 6px">Alterações (diff redigido)</h2>
        <table class="tbl"><thead><tr><th>Campo</th><th>Antes</th><th>Depois</th></tr></thead><tbody>
          <tr v-for="(c, k) in detail.diff" :key="k"><td><code>{{ k }}</code></td><td class="from">{{ show(c.from) }}</td><td class="to">{{ show(c.to) }}</td></tr>
        </tbody></table>
      </template>
      <template v-if="detail.data && Object.keys(detail.data).length">
        <h2 style="margin:14px 0 6px">Metadados</h2>
        <pre class="json">{{ JSON.stringify(detail.data, null, 2) }}</pre>
      </template>
      <h2 style="margin:14px 0 6px">Linha do tempo correlacionada</h2>
      <div v-if="!detail.related?.length" class="tm-muted" style="font-size:12px">Sem eventos correlacionados.</div>
      <div class="tm-timeline">
        <div v-for="e in detail.related" :key="e.id" class="tm-tl-item">
          <div class="tm-tl-marker"><span class="tm-tl-dot" :class="{ pending: e.outcome !== 'success' }" /></div>
          <div class="tm-tl-text"><strong>{{ e.label }}</strong><span>{{ fmtDateTime(e.occurredAt) }} · {{ e.actorName }}<template v-if="e.instanceNumber"> · {{ e.instanceNumber }}</template><template v-if="e.stageKey"> · {{ e.stageKey }}</template></span></div>
        </div>
      </div>
      <div v-if="detail.processInstanceId" class="tm-actions" style="margin-top:10px"><button class="tm-btn small" @click="filterBy('number', detail.instanceNumber)">Todos os eventos desta solicitação</button><RouterLink class="tm-btn small" :to="{ name: 'instance', params: { id: detail.processInstanceId } }">Abrir solicitação</RouterLink></div>
    </template>
  </Dialog>
</template>

<style scoped>
.filters { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; padding: 14px; }
@media (max-width: 1100px) { .filters { grid-template-columns: repeat(2, 1fr); } }
.filters .tm-field { margin: 0; }
.tbl { width: 100%; border-collapse: collapse; font-size: 12px; }
.tbl th { text-align: left; font-size: 10px; text-transform: uppercase; color: #788696; padding: 5px; }
.tbl td { border-top: 1px solid #edf1f4; padding: 5px; vertical-align: top; word-break: break-word; }
.from { color: var(--red); }
.to { color: var(--green); }
.json { background: #f7f9fb; border: 1px solid var(--line); border-radius: 8px; padding: 10px; font-size: 11px; max-height: 240px; overflow: auto; }
code { font-size: 10px; background: #eef2f5; padding: 1px 4px; border-radius: 4px; }
</style>
