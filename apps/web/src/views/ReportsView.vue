<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, newId } from '@/api/http'
import type { CatalogItem, ReportJobView } from '@/api/types'
import { fmtDateTime } from '@/composables/format'
import { useNotify } from '@/composables/notify'
import { useSession } from '@/stores/session'
import ReportJobPreview from '@/components/reports/ReportJobPreview.vue'

const session = useSession()
const notify = useNotify()
interface Catalog { operational: { key: string; label: string; formats: string[] }[]; canInstance: boolean; canManageTemplates: boolean; templates: { key: string; name: string; version: number; kind: string }[] }
const catalog = ref<Catalog | null>(null)
const flows = ref<CatalogItem[]>([])
const jobs = ref<ReportJobView[]>([])
const selectedJob = ref<string | null>(null)
const form = reactive({ type: 'instances', format: 'pdf', filters: { flowKey: '', version: '', status: '', area: '', group: '', stageKey: '', openedFrom: '', openedTo: '', closedFrom: '', closedTo: '' } as Record<string, string> })
const busy = ref(false)

const TYPE_HINT: Record<string, string> = {
  instances: 'Instâncias por status, com solicitante, área, abertura/encerramento e duração.',
  stage_aging: 'Tempo por etapa (média, mediana, P90) e envelhecimento das pendências.',
  pending: 'Pendências por grupo e responsável, com atrasos.',
  sla: 'Etapas com prazo: concluídas no prazo, com atraso e abertas vencidas.',
  integration_errors: 'Falhas e reprocessamentos do engine e das integrações (AX).',
  notifications: 'Notificações por estado; falhas, reagendamentos e descartes.',
}
const label = (key: string) => catalog.value?.operational.find((o) => o.key === key)?.label ?? (key === 'instance' ? 'Relatório da solicitação' : key)
const filtersFor = computed(() => (['integration_errors', 'notifications'].includes(form.type) ? ['openedFrom', 'openedTo'] : ['flowKey', 'version', 'status', 'area', 'group', 'stageKey', 'openedFrom', 'openedTo', 'closedFrom', 'closedTo']))

async function loadJobs() {
  jobs.value = (await api<{ data: ReportJobView[] }>('/reports')).data
}
onMounted(async () => {
  catalog.value = await api<Catalog>('/reports/catalog')
  flows.value = (await api<{ data: CatalogItem[] }>('/catalog/processes').catch(() => ({ data: [] as CatalogItem[] }))).data
  await loadJobs()
})

async function generate() {
  busy.value = true
  try {
    const filters = Object.fromEntries(Object.entries(form.filters).filter(([k, v]) => v && filtersFor.value.includes(k)))
    const job = await api<ReportJobView>('/reports', { method: 'POST', body: { type: form.type, format: form.format, filters }, idempotencyKey: newId() })
    selectedJob.value = job.id
    await loadJobs()
  } catch (e) {
    notify.error(e)
  } finally {
    busy.value = false
  }
}
const STATUS: Record<string, { label: string; tone: string }> = { queued: { label: 'Na fila', tone: 'amber' }, rendering: { label: 'Gerando', tone: 'amber' }, ready: { label: 'Pronto', tone: 'green' }, failed: { label: 'Falhou', tone: 'red' }, expired: { label: 'Expirado', tone: 'neutral' } }
</script>

<template>
  <div class="tm-crumbs"><RouterLink to="/">Início</RouterLink><span class="sep">/</span><span>Relatórios</span></div>
  <div class="tm-heading">
    <div>
      <h1>Relatórios</h1>
      <p class="tm-subtitle">Relatórios operacionais sobre um catálogo de dados aprovado (sem consultas livres). Para imprimir uma solicitação ou uma etapa, abra a solicitação e use "Relatório / imprimir".</p>
    </div>
  </div>

  <div class="grid">
    <section v-if="session.can('reportsOperational')" class="tm-panel">
      <div class="tm-panel-head"><div class="tm-panel-title">Gerar relatório operacional</div></div>
      <div class="tm-panel-body">
        <div class="two">
          <div class="tm-field"><label for="r-t">Relatório</label><select id="r-t" v-model="form.type"><option v-for="o in catalog?.operational" :key="o.key" :value="o.key">{{ o.label }}</option></select><div class="tm-hint">{{ TYPE_HINT[form.type] }}</div></div>
          <div class="tm-field"><label for="r-f">Formato</label><select id="r-f" v-model="form.format"><option value="pdf">PDF (pré-visualização e impressão)</option><option value="csv">CSV (Excel pt-BR)</option><option value="xlsx">XLSX</option></select></div>
        </div>
        <div class="filters">
          <div v-if="filtersFor.includes('flowKey')" class="tm-field"><label for="r-fl">Fluxo</label><select id="r-fl" v-model="form.filters.flowKey"><option value="">Todos</option><option v-for="f in flows" :key="f.key" :value="f.key">{{ f.name }}</option></select></div>
          <div v-if="filtersFor.includes('version')" class="tm-field"><label for="r-v">Versão</label><input id="r-v" v-model="form.filters.version" inputmode="numeric" /></div>
          <div v-if="filtersFor.includes('status')" class="tm-field"><label for="r-s">Status</label><select id="r-s" v-model="form.filters.status"><option value="">Todos</option><option value="open">Abertas</option><option value="completed">Concluídas</option><option value="cancelled">Canceladas</option><option value="error">Com erro</option></select></div>
          <div v-if="filtersFor.includes('area')" class="tm-field"><label for="r-a">Área do solicitante</label><input id="r-a" v-model="form.filters.area" /></div>
          <div v-if="filtersFor.includes('group')" class="tm-field"><label for="r-g">Grupo</label><select id="r-g" v-model="form.filters.group"><option value="">Todos</option><option v-for="g in session.meta?.groups" :key="g.key" :value="g.key">{{ g.name }}</option></select></div>
          <div v-if="filtersFor.includes('stageKey')" class="tm-field"><label for="r-st">Etapa (chave)</label><input id="r-st" v-model="form.filters.stageKey" /></div>
          <div class="tm-field"><label for="r-of">Aberta de</label><input id="r-of" v-model="form.filters.openedFrom" type="date" /></div>
          <div class="tm-field"><label for="r-ot">Aberta até</label><input id="r-ot" v-model="form.filters.openedTo" type="date" /></div>
          <div v-if="filtersFor.includes('closedFrom')" class="tm-field"><label for="r-cf">Encerrada de</label><input id="r-cf" v-model="form.filters.closedFrom" type="date" /></div>
          <div v-if="filtersFor.includes('closedTo')" class="tm-field"><label for="r-ct">Encerrada até</label><input id="r-ct" v-model="form.filters.closedTo" type="date" /></div>
        </div>
        <p class="tm-hint">Datas no fuso de negócio (America/Sao_Paulo), dias inteiros. Geração assíncrona com limite de linhas e tamanho.</p>
        <button class="tm-btn primary" :disabled="busy" @click="generate"><i class="pi pi-play" aria-hidden="true" /> Gerar</button>
      </div>
    </section>
    <section class="tm-panel">
      <div class="tm-panel-head"><div class="tm-panel-title">Meus relatórios</div><button class="tm-btn small ghost" @click="loadJobs"><i class="pi pi-refresh" aria-hidden="true" /> Atualizar</button></div>
      <div v-if="!jobs.length" class="tm-empty"><strong>Nenhum relatório gerado</strong>Arquivos expiram conforme a política de retenção.</div>
      <ul class="jobs">
        <li v-for="j in jobs" :key="j.id" :class="{ active: selectedJob === j.id }">
          <button class="tm-link-btn" @click="selectedJob = j.id">{{ j.type === 'instance' ? `Solicitação (${j.parameters.mode})` : label(j.type) }}</button>
          <span class="tm-pill" :class="STATUS[j.status]?.tone">{{ STATUS[j.status]?.label }}</span>
          <div class="tm-hint">{{ j.format.toUpperCase() }} · {{ fmtDateTime(j.createdAt) }}<template v-if="j.processInstanceId"> · <RouterLink :to="{ name: 'instance', params: { id: j.processInstanceId } }">abrir solicitação</RouterLink></template></div>
        </li>
      </ul>
    </section>
  </div>
  <section v-if="selectedJob" class="tm-panel" style="margin-top:16px">
    <div class="tm-panel-body"><ReportJobPreview :job-id="selectedJob" @ready="loadJobs" /></div>
  </section>
</template>

<style scoped>
.grid { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 16px; align-items: start; }
@media (max-width: 1100px) { .grid { grid-template-columns: 1fr; } }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.filters { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
@media (max-width: 900px) { .filters { grid-template-columns: repeat(2, 1fr); } }
.jobs { list-style: none; margin: 0; padding: 0; }
.jobs li { padding: 10px 14px; border-top: 1px solid #edf1f4; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; justify-content: space-between; }
.jobs li.active { background: var(--brand-softer); }
.jobs .tm-hint { width: 100%; margin: 0; }
</style>
