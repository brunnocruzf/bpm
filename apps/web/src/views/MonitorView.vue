<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Paginator from 'primevue/paginator'
import { api } from '@/api/http'
import type { CatalogItem, InstanceSummary, Paged } from '@/api/types'
import { useNotify } from '@/composables/notify'
import { fmtDateTime } from '@/composables/format'
import { DUE_FILTERS, useGridFilters } from '@/composables/gridFilters'
import StatusPill from '@/components/StatusPill.vue'

interface Metrics { running: number; awaitingMe: number; completedThisMonth: number; onTimePercent: number | null; overdueTasks: number }

const AGE = [{ value: '0-7', label: 'Até 7 dias' }, { value: '8-30', label: '8 a 30 dias' }, { value: '31-90', label: '31 a 90 dias' }, { value: '91-', label: 'Mais de 90 dias' }]
const STATUS = [{ value: 'starting,running', label: 'Em andamento' }, { value: 'completed', label: 'Concluída' }, { value: 'cancelled', label: 'Cancelada' }, { value: 'error', label: 'Erro de integração' }]

const router = useRouter()
const notify = useNotify()
const flows = ref<CatalogItem[]>([])
const items = ref<InstanceSummary[]>([])
const total = ref(0)
const page = ref(1)
const metrics = ref<Metrics | null>(null)
const loading = ref(false)
const COLUMNS = [
  { key: 'number', label: 'Nº / ano' }, { key: 'requester', label: 'Solicitante' }, { key: 'area', label: 'Área do solicitante' },
  { key: 'started', label: 'Data do registro' }, { key: 'stage', label: 'Etapa atual' }, { key: 'assignee', label: 'Responsável' },
  { key: 'age', label: 'Idade' }, { key: 'due', label: 'Prazo' }, { key: 'status', label: 'Status' },
]
const { filters, active, clear, toggleSort, sortIcon } = useGridFilters(
  ['flow', 'q', 'number', 'requester', 'area', 'startedFrom', 'startedTo', 'stage', 'assignee', 'age', 'due', 'status', 'sort', 'dir'] as const,
  () => { page.value = 1; load() },
)

const flowName = computed(() => flows.value.find((f) => f.key === filters.flow)?.name)

let seq = 0
async function load() {
  const mine = ++seq
  loading.value = true
  try {
    const [list, m] = await Promise.all([
      api<Paged<InstanceSummary>>('/process-instances', { query: { ...filters, page: page.value, perPage: 20 } }),
      api<Metrics>('/process-instances/metrics', { query: { flow: filters.flow } }),
    ])
    if (mine !== seq) return // resposta de um filtro já substituído
    items.value = list.data
    total.value = list.meta.total
    metrics.value = m
  } catch (e) {
    notify.error(e)
  } finally {
    if (mine === seq) loading.value = false
  }
}

onMounted(async () => {
  load()
  try { flows.value = (await api<{ data: CatalogItem[] }>('/catalog/processes')).data.filter((f) => f.publishedVersion) } catch { /* filtro opcional */ }
})
</script>

<template>
  <div class="tm-crumbs"><RouterLink to="/">Catálogo de fluxos</RouterLink><span class="sep">›</span><span>Acompanhar</span></div>
  <div class="tm-heading">
    <div><h1>Acompanhar processos</h1><p class="tm-subtitle">Visualize solicitações, suas etapas atuais, responsáveis e prazos. Você vê as solicitações que abriu ou das quais seu grupo participa.</p></div>
    <button class="tm-btn" @click="load"><i class="pi pi-refresh" /> Atualizar</button>
  </div>
  <div v-if="metrics" class="tm-metrics">
    <div class="tm-metric"><div class="tm-metric-label">Solicitações em andamento</div><div class="tm-metric-value">{{ metrics.running }}</div></div>
    <div class="tm-metric"><div class="tm-metric-label">Aguardando minha ação</div><div class="tm-metric-value">{{ metrics.awaitingMe }}</div></div>
    <div class="tm-metric"><div class="tm-metric-label">Concluídas este mês</div><div class="tm-metric-value">{{ metrics.completedThisMonth }}</div></div>
    <div class="tm-metric"><div class="tm-metric-label">Etapas no prazo (30 dias)</div><div class="tm-metric-value">{{ metrics.onTimePercent === null ? '—' : metrics.onTimePercent + '%' }}<span v-if="metrics.overdueTasks" class="tm-pill red" style="margin-left:8px;vertical-align:middle">{{ metrics.overdueTasks }} vencida(s)</span></div></div>
  </div>
  <div class="tm-toolbar">
    <div class="tm-toolbar-left">
      <select v-model="filters.flow" class="tm-select" aria-label="Fluxo">
        <option value="">Todos os fluxos</option>
        <option v-for="f in flows" :key="f.key" :value="f.key">{{ f.name }}</option>
      </select>
      <span class="tm-muted" style="font-size:12px">{{ total }} solicitação(ões){{ flowName ? ` · ${flowName}` : '' }}</span>
    </div>
    <div class="tm-toolbar-left">
      <button v-if="active" class="tm-btn ghost small" @click="clear"><i class="pi pi-filter-slash" /> Limpar filtros</button>
      <input v-model="filters.q" class="tm-input" placeholder="Número, solicitante ou área..." aria-label="Buscar" />
    </div>
  </div>
  <div class="tm-table-wrap" :class="{ busy: loading }">
    <table class="tm-table">
      <thead>
        <tr>
          <th v-for="c in COLUMNS" :key="c.key" class="tm-sortable" :class="{ sorted: filters.sort === c.key }" :aria-sort="filters.sort !== c.key ? 'none' : filters.dir === 'desc' ? 'descending' : 'ascending'" @click="toggleSort(c.key)">
            {{ c.label }} <i :class="sortIcon(c.key)" />
          </th>
        </tr>
        <tr class="tm-filter-row">
          <th><input v-model="filters.number" class="tm-col-filter" :class="{ on: filters.number }" placeholder="Nº ou fluxo" aria-label="Filtrar por número ou fluxo" /></th>
          <th><input v-model="filters.requester" class="tm-col-filter" :class="{ on: filters.requester }" placeholder="Nome" aria-label="Filtrar por solicitante" /></th>
          <th><input v-model="filters.area" class="tm-col-filter" :class="{ on: filters.area }" placeholder="Área" aria-label="Filtrar por área do solicitante" /></th>
          <th>
            <div class="tm-col-range">
              <input v-model="filters.startedFrom" type="date" class="tm-col-filter" :class="{ on: filters.startedFrom }" title="Registrada a partir de" aria-label="Registrada a partir de" />
              <input v-model="filters.startedTo" type="date" class="tm-col-filter" :class="{ on: filters.startedTo }" title="Registrada até" aria-label="Registrada até" />
            </div>
          </th>
          <th><input v-model="filters.stage" class="tm-col-filter" :class="{ on: filters.stage }" placeholder="Etapa" aria-label="Filtrar por etapa atual" /></th>
          <th><input v-model="filters.assignee" class="tm-col-filter" :class="{ on: filters.assignee }" placeholder="Grupo ou pessoa" aria-label="Filtrar por responsável" /></th>
          <th>
            <select v-model="filters.age" class="tm-col-filter" :class="{ on: filters.age }" aria-label="Filtrar por idade">
              <option value="">Todas</option>
              <option v-for="o in AGE" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </th>
          <th>
            <select v-model="filters.due" class="tm-col-filter" :class="{ on: filters.due }" aria-label="Filtrar por prazo">
              <option value="">Todos</option>
              <option v-for="o in DUE_FILTERS" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </th>
          <th>
            <select v-model="filters.status" class="tm-col-filter" :class="{ on: filters.status }" aria-label="Filtrar por status">
              <option value="">Todos</option>
              <option v-for="o in STATUS" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="loading && !items.length"><td colspan="9" class="tm-empty">Carregando...</td></tr>
        <tr v-else-if="!items.length"><td colspan="9"><div class="tm-empty"><strong>Nenhuma solicitação</strong>Ajuste os filtros ou abra uma nova pelo catálogo.</div></td></tr>
        <tr v-for="i in items" :key="i.id" class="clickable" @click="router.push({ name: 'instance', params: { id: i.id } })">
          <td><div class="tm-strong">{{ i.number }}</div><div class="tm-flow-sub">{{ i.flow.name }}</div></td>
          <td>{{ i.requesterName }}</td>
          <td>{{ i.requesterArea ?? '—' }}</td>
          <td>{{ fmtDateTime(i.startedAt) }}</td>
          <td>
            <template v-if="i.currentTasks.length"><div v-for="t in i.currentTasks" :key="t.id">{{ t.name }}</div></template>
            <span v-else-if="i.processing" class="tm-processing"><span class="tm-spinner" /> Processando</span>
            <span v-else class="tm-muted">{{ i.resultLabel ?? '—' }}</span>
          </td>
          <td><div v-for="t in i.currentTasks" :key="t.id">{{ t.assigneeName ?? t.candidateGroupName }}</div></td>
          <td>{{ i.ageDays }} d</td>
          <td><div v-for="t in i.currentTasks" :key="t.id" :class="{ 'tm-overdue': t.overdue }">{{ fmtDateTime(t.dueAt) }}</div></td>
          <td><StatusPill :status="i.status" /></td>
        </tr>
      </tbody>
    </table>
  </div>
  <Paginator v-if="total > 20" :rows="20" :total-records="total" :first="(page - 1) * 20" @page="(e) => { page = e.page + 1; load() }" />
</template>
