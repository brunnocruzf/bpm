<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Paginator from 'primevue/paginator'
import { api } from '@/api/http'
import type { CatalogItem, Paged, TaskSummary } from '@/api/types'
import { useNotify } from '@/composables/notify'
import { TASK_STATUS, fmtAge, fmtDateTime } from '@/composables/format'
import { DUE_FILTERS, useGridFilters } from '@/composables/gridFilters'
import StatusPill from '@/components/StatusPill.vue'

type Scope = 'mine' | 'groups' | 'started' | 'completed'
const route = useRoute()
const router = useRouter()
const notify = useNotify()
const scope = ref<Scope>((route.query.scope as Scope) || 'mine')
const flows = ref<CatalogItem[]>([])
const tasks = ref<TaskSummary[]>([])
const total = ref(0)
const page = ref(1)
const loading = ref(false)
const { filters, active, apply, clear, toggleSort, sortIcon } = useGridFilters(
  ['flow', 'q', 'number', 'stage', 'assignee', 'requester', 'age', 'completedFrom', 'completedTo', 'due', 'status', 'sort', 'dir'] as const,
  () => { page.value = 1; load() },
  () => ({ scope: scope.value }),
)

const TABS: { key: Scope; label: string }[] = [
  { key: 'mine', label: 'Minhas tarefas' },
  { key: 'groups', label: 'Tarefas do meu grupo' },
  { key: 'started', label: 'Solicitações abertas por mim' },
  { key: 'completed', label: 'Concluídas por mim' },
]
const columns = computed(() => [
  { key: 'number', label: 'Solicitação' }, { key: 'stage', label: 'Etapa' }, { key: 'assignee', label: 'Grupo / responsável' },
  { key: 'requester', label: 'Solicitante' },
  scope.value === 'completed' ? { key: 'completed', label: 'Concluída' } : { key: 'age', label: 'Idade' },
  { key: 'due', label: 'Prazo' }, { key: 'status', label: 'Status' },
])
const AGE = [{ value: '0-0', label: 'Menos de 1 dia' }, { value: '1-3', label: '1 a 3 dias' }, { value: '4-7', label: '4 a 7 dias' }, { value: '8-', label: 'Mais de 7 dias' }]
const statusOptions = computed(() => (scope.value === 'completed' ? ['completed'] : ['open', 'claimed', 'completing']).map((value) => ({ value, label: TASK_STATUS[value].label })))
const dueOptions = computed(() => scope.value === 'completed'
  ? [{ value: 'overdue', label: 'Fora do prazo' }, { value: 'ontime', label: 'No prazo' }, { value: 'none', label: 'Sem prazo' }]
  : DUE_FILTERS)

let seq = 0
async function load() {
  const mine = ++seq
  loading.value = true
  try {
    const res = await api<Paged<TaskSummary>>('/tasks', { query: { ...filters, scope: scope.value, page: page.value, perPage: 25 } })
    if (mine !== seq) return // resposta de um filtro já substituído
    tasks.value = res.data
    total.value = res.meta.total
  } catch (e) {
    notify.error(e)
  } finally {
    if (mine === seq) loading.value = false
  }
}

function setScope(s: Scope) {
  if (s === scope.value) return
  scope.value = s
  // filtros que só existem em uma das abas
  if (s === 'completed') filters.age = ''
  else { filters.completedFrom = ''; filters.completedTo = '' }
  if (filters.sort === (s === 'completed' ? 'age' : 'completed')) { filters.sort = ''; filters.dir = '' }
  if (!statusOptions.value.some((o) => o.value === filters.status)) filters.status = ''
  if (!dueOptions.value.some((o) => o.value === filters.due)) filters.due = ''
  apply()
}

onMounted(async () => {
  load()
  try { flows.value = (await api<{ data: CatalogItem[] }>('/catalog/processes')).data.filter((f) => f.publishedVersion) } catch { /* filtro opcional */ }
})
</script>

<template>
  <div class="tm-crumbs"><span>Início</span><span class="sep">›</span><strong style="color:#36495c">Tarefas</strong></div>
  <div class="tm-heading">
    <div><h1>Tarefas</h1><p class="tm-subtitle">Etapas que aguardam você ou seu grupo. Abra a tarefa para preencher o formulário da etapa.</p></div>
    <button class="tm-btn" @click="load"><i class="pi pi-refresh" /> Atualizar</button>
  </div>
  <div class="tm-tabbar">
    <button v-for="t in TABS" :key="t.key" class="tm-tab" :class="{ active: scope === t.key }" @click="setScope(t.key)">{{ t.label }}</button>
  </div>
  <div class="tm-toolbar">
    <div class="tm-toolbar-left">
      <select v-model="filters.flow" class="tm-select" aria-label="Fluxo">
        <option value="">Todos os fluxos</option>
        <option v-for="f in flows" :key="f.key" :value="f.key">{{ f.name }}</option>
      </select>
      <span class="tm-muted" style="font-size:12px">{{ total }} tarefa(s)</span>
    </div>
    <div class="tm-toolbar-left">
      <button v-if="active" class="tm-btn ghost small" @click="clear"><i class="pi pi-filter-slash" /> Limpar filtros</button>
      <input v-model="filters.q" class="tm-input" placeholder="Número, etapa, solicitante ou área..." aria-label="Buscar" />
    </div>
  </div>
  <div class="tm-table-wrap" :class="{ busy: loading }">
    <table class="tm-table">
      <thead>
        <tr>
          <th v-for="c in columns" :key="c.key" class="tm-sortable" :class="{ sorted: filters.sort === c.key }" :aria-sort="filters.sort !== c.key ? 'none' : filters.dir === 'desc' ? 'descending' : 'ascending'" @click="toggleSort(c.key)">
            {{ c.label }} <i :class="sortIcon(c.key)" />
          </th>
        </tr>
        <tr class="tm-filter-row">
          <th><input v-model="filters.number" class="tm-col-filter" :class="{ on: filters.number }" placeholder="Nº ou fluxo" aria-label="Filtrar por número ou fluxo" /></th>
          <th><input v-model="filters.stage" class="tm-col-filter" :class="{ on: filters.stage }" placeholder="Etapa" aria-label="Filtrar por etapa" /></th>
          <th><input v-model="filters.assignee" class="tm-col-filter" :class="{ on: filters.assignee }" placeholder="Grupo ou pessoa" aria-label="Filtrar por grupo ou responsável" /></th>
          <th><input v-model="filters.requester" class="tm-col-filter" :class="{ on: filters.requester }" placeholder="Nome ou área" aria-label="Filtrar por solicitante ou área" /></th>
          <th>
            <div v-if="scope === 'completed'" class="tm-col-range">
              <input v-model="filters.completedFrom" type="date" class="tm-col-filter" :class="{ on: filters.completedFrom }" title="Concluída a partir de" aria-label="Concluída a partir de" />
              <input v-model="filters.completedTo" type="date" class="tm-col-filter" :class="{ on: filters.completedTo }" title="Concluída até" aria-label="Concluída até" />
            </div>
            <select v-else v-model="filters.age" class="tm-col-filter" :class="{ on: filters.age }" aria-label="Filtrar por idade">
              <option value="">Todas</option>
              <option v-for="o in AGE" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </th>
          <th>
            <select v-model="filters.due" class="tm-col-filter" :class="{ on: filters.due }" aria-label="Filtrar por prazo">
              <option value="">Todos</option>
              <option v-for="o in dueOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </th>
          <th>
            <select v-model="filters.status" class="tm-col-filter" :class="{ on: filters.status }" aria-label="Filtrar por status">
              <option value="">Todos</option>
              <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="loading && !tasks.length"><td colspan="7" class="tm-empty">Carregando...</td></tr>
        <tr v-else-if="!tasks.length"><td colspan="7"><div class="tm-empty"><div class="tm-empty-icon"><i class="pi pi-inbox" /></div><strong>Nada por aqui</strong>Não há tarefas neste filtro.</div></td></tr>
        <tr v-for="t in tasks" :key="t.id" class="clickable" @click="router.push({ name: 'task', params: { id: t.id } })">
          <td><div class="tm-strong">{{ t.instance?.number }}</div><div class="tm-flow-sub">{{ t.instance?.flowName }}</div></td>
          <td class="tm-strong">{{ t.name }}</td>
          <td>{{ t.candidateGroupName ?? 'Responsável direto' }}<div class="tm-flow-sub">{{ t.assignee?.name ?? 'Não assumida' }}</div></td>
          <td>{{ t.instance?.requesterName }}<div class="tm-flow-sub">{{ t.instance?.requesterArea }}</div></td>
          <td>{{ scope === 'completed' ? fmtDateTime(t.completedAt) : fmtAge(t.ageHours) }}</td>
          <td :class="{ 'tm-overdue': t.overdue }">{{ fmtDateTime(t.dueAt) }}</td>
          <td><StatusPill kind="task" :status="t.status" /></td>
        </tr>
      </tbody>
    </table>
  </div>
  <Paginator v-if="total > 25" :rows="25" :total-records="total" :first="(page - 1) * 25" @page="(e) => { page = e.page + 1; load() }" />
</template>
