<script setup lang="ts">
/**
 * Execuções das integrações (cada chamada a sistema externo): filtros, pedido/resposta mascarados,
 * explicação do erro e reconciliação de escritas com resultado incerto (processes.manage).
 */
import { onMounted, reactive, ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import { api } from '@/api/http'
import { fmtDateTime } from '@/composables/format'
import { useNotify } from '@/composables/notify'
import { useSession } from '@/stores/session'
import { RESULT_LABEL, RESULT_TONE, type Execution } from './types'

const notify = useNotify()
const session = useSession()
const items = ref<Execution[]>([])
const stats = ref<Record<string, number>>({})
const next = ref<number | null>(null)
const page = ref(1)
const loading = ref(false)
const open = ref<string | null>(null)
const filters = reactive({ result: '', since: '24h', q: '', system: '' })
const reconciling = ref<Execution | null>(null)
const rec = reactive({ outcome: 'done' as 'done' | 'not_done', outputs: '' as string, note: '' })
let debounce: number | undefined

async function load(more = false) {
  loading.value = true
  try {
    page.value = more ? page.value + 1 : 1
    const res = await api<{ data: Execution[]; next: number | null; stats: Record<string, number> }>('/admin/integrations/executions', { query: { ...filters, page: page.value } })
    items.value = more ? [...items.value, ...res.data] : res.data
    next.value = res.next
    stats.value = res.stats
  } catch (e) {
    notify.error(e)
  } finally {
    loading.value = false
  }
}
watch(() => [filters.result, filters.since, filters.system], () => load())
watch(() => filters.q, () => {
  window.clearTimeout(debounce)
  debounce = window.setTimeout(() => load(), 300)
})
onMounted(() => load())

function startReconcile(x: Execution) {
  reconciling.value = x
  Object.assign(rec, { outcome: 'done', outputs: '', note: '' })
}
async function reconcile() {
  const x = reconciling.value
  if (!x) return
  const resultData: Record<string, string> = {}
  for (const line of rec.outputs.split('\n')) {
    const [k, ...v] = line.split('=')
    if (k?.trim() && v.length) resultData[k.trim()] = v.join('=').trim()
  }
  try {
    await api(`/admin/integrations/executions/${x.id}/reconcile`, { method: 'POST', body: { outcome: rec.outcome, resultData, note: rec.note || null } })
    notify.success(rec.outcome === 'done' ? 'Reconciliado como executado: a próxima tentativa devolve o resultado informado, sem reenviar.' : 'Reconciliado como não executado: a próxima tentativa reenvia.')
    reconciling.value = null
    load()
  } catch (e) {
    notify.error(e)
  }
}
const pretty = (v: unknown) => JSON.stringify(v, null, 2)
defineExpose({ load })
</script>

<template>
  <section class="tm-panel">
    <div class="tm-panel-head">
      <div><div class="tm-panel-title">Execuções</div><div class="tm-panel-caption">Cada chamada a sistema externo, com pedido e resposta mascarados (sem CPF, CNPJ, e-mail, telefone ou campos sensíveis) e o correlation ID para rastreio.</div></div>
      <button class="tm-btn small ghost" :disabled="loading" @click="load()"><i class="pi pi-refresh" :class="{ 'pi-spin': loading }" aria-hidden="true" /> Atualizar</button>
    </div>
    <div class="tm-panel-body">
      <div class="stats">
        <span class="tm-muted">Últimas 24 h:</span>
        <span v-for="(n, r) in stats" :key="r" class="tm-pill" :class="RESULT_TONE(String(r))">{{ RESULT_LABEL[String(r)] ?? r }}: {{ n }}</span>
        <span v-if="!Object.keys(stats).length" class="tm-muted">nenhuma chamada</span>
      </div>
      <div class="toolbar">
        <select v-model="filters.result" class="tm-select" aria-label="Resultado"><option value="">Todos os resultados</option><option value="failures">Somente falhas</option><option value="success">Sucesso</option><option value="not_found">Não encontrado</option><option value="unknown">Resultado incerto (reconciliar)</option></select>
        <select v-model="filters.since" class="tm-select" aria-label="Período"><option value="1h">Última hora</option><option value="24h">Últimas 24 h</option><option value="7d">Últimos 7 dias</option><option value="30d">Últimos 30 dias</option></select>
        <input v-model="filters.q" class="tm-input" type="search" placeholder="Operação, endpoint ou correlation ID..." aria-label="Buscar execuções" />
      </div>
      <table class="tm-table" style="min-width:0">
        <thead><tr><th>Quando</th><th>Operação</th><th>Endpoint / transporte</th><th>Resultado</th><th>HTTP</th><th>Duração</th><th /></tr></thead>
        <tbody>
          <tr v-if="!items.length && !loading"><td colspan="7" class="tm-empty">Nenhuma execução no filtro.</td></tr>
          <template v-for="x in items" :key="x.id">
            <tr>
              <td>{{ fmtDateTime(x.at) }}</td>
              <td><code>{{ x.operation }}</code><div class="tm-hint">{{ x.system }}<template v-if="x.attempt > 1"> · tentativa {{ x.attempt }}</template></div></td>
              <td>{{ x.endpoint ?? '—' }}<div class="tm-hint">{{ x.transport }}</div></td>
              <td><span class="tm-pill" :class="RESULT_TONE(x.result)">{{ RESULT_LABEL[x.result] ?? x.result }}</span></td>
              <td>{{ x.httpStatus ?? '—' }}</td>
              <td>{{ x.durationMs }} ms</td>
              <td class="tm-actions">
                <button class="tm-link-btn" @click="open = open === x.id ? null : x.id">{{ open === x.id ? 'fechar' : 'detalhes' }}</button>
                <button v-if="x.result === 'unknown' && session.can('processesManage')" class="tm-btn small" @click="startReconcile(x)">Reconciliar</button>
              </td>
            </tr>
            <tr v-if="open === x.id" class="det">
              <td colspan="7">
                <div v-if="x.explain" class="tm-note err" style="margin-top:0"><i class="pi pi-question-circle" aria-hidden="true" /><span><strong>{{ x.explain.title }}.</strong> {{ x.explain.cause }}<br /><strong>O que fazer:</strong> {{ x.explain.action }}</span></div>
                <div class="cols">
                  <div><strong>Pedido (mascarado)</strong><pre>{{ pretty(x.request) }}</pre></div>
                  <div><strong>Resposta (mascarada)</strong><pre>{{ pretty(x.response) }}</pre></div>
                </div>
                <div class="tm-hint">Correlation ID <code>{{ x.correlationId ?? '—' }}</code><template v-if="x.idempotencyKey"> · chave de idempotência <code>{{ x.idempotencyKey }}</code></template>
                  <template v-if="x.processInstanceId"> · <RouterLink :to="{ name: 'instance', params: { id: x.processInstanceId } }">solicitação</RouterLink></template></div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
      <div v-if="next" class="more"><button class="tm-btn small" :disabled="loading" @click="load(true)">Carregar mais</button></div>
    </div>
  </section>

  <Dialog :visible="!!reconciling" modal header="Reconciliar escrita com resultado incerto" :style="{ width: 'min(620px, 95vw)' }" @update:visible="(v) => !v && (reconciling = null)">
    <p class="tm-hint" style="margin-top:0">A chamada <code>{{ reconciling?.operation }}</code> esgotou o tempo depois do envio: o sistema de destino pode ter gravado. Confira lá antes de decidir.</p>
    <label class="check"><input v-model="rec.outcome" type="radio" value="done" /> <span><strong>Foi executada</strong> — informar as saídas (a próxima tentativa devolve este resultado, sem reenviar)</span></label>
    <label class="check"><input v-model="rec.outcome" type="radio" value="not_done" /> <span><strong>Não foi executada</strong> — a próxima tentativa reenvia</span></label>
    <div v-if="rec.outcome === 'done'" class="tm-field"><label for="r-out">Saídas (uma por linha: campo=valor)</label><textarea id="r-out" v-model="rec.outputs" placeholder="numeroDiarioTransferencia=OT-000124" /></div>
    <div class="tm-field"><label for="r-note">Observação</label><input id="r-note" v-model="rec.note" maxlength="300" /></div>
    <template #footer>
      <button class="tm-btn" @click="reconciling = null">Cancelar</button>
      <button class="tm-btn primary" @click="reconcile">Confirmar</button>
    </template>
  </Dialog>
</template>

<style scoped>
.stats { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 10px; font-size: 11px; }
.toolbar { display: flex; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; }
.toolbar .tm-input { flex: 1; min-width: 240px; }
tr.det td { background: #fbfcfd; }
.cols { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 8px 0; }
pre { max-height: 260px; overflow: auto; margin: 4px 0 0; padding: 8px; background: #f6f8fa; border-radius: 6px; font-size: 10.5px; }
.check { display: flex; gap: 8px; align-items: flex-start; font-size: 12px; color: #43586a; margin-bottom: 8px; }
.more { display: flex; justify-content: center; margin-top: 10px; }
code { font-size: 11px; background: #eef2f5; padding: 1px 4px; border-radius: 4px; overflow-wrap: anywhere; }
</style>
