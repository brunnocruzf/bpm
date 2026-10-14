<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Paginator from 'primevue/paginator'
import { api } from '@/api/http'
import type { CatalogItem, InstanceSummary, Paged } from '@/api/types'
import { useNotify } from '@/composables/notify'
import { fmtDateTime } from '@/composables/format'
import StatusPill from '@/components/StatusPill.vue'

interface Metrics { running: number; awaitingMe: number; completedThisMonth: number; onTimePercent: number | null; overdueTasks: number }

const route = useRoute()
const router = useRouter()
const notify = useNotify()
const flows = ref<CatalogItem[]>([])
const flow = ref((route.query.flow as string) || '')
const status = ref('')
const q = ref((route.query.q as string) || '')
const items = ref<InstanceSummary[]>([])
const total = ref(0)
const page = ref(1)
const metrics = ref<Metrics | null>(null)
const loading = ref(false)

const flowName = computed(() => flows.value.find((f) => f.key === flow.value)?.name)

async function load() {
  loading.value = true
  try {
    const [list, m] = await Promise.all([
      api<Paged<InstanceSummary>>('/process-instances', { query: { flow: flow.value, status: status.value, q: q.value, page: page.value, perPage: 20 } }),
      api<Metrics>('/process-instances/metrics', { query: { flow: flow.value } }),
    ])
    items.value = list.data
    total.value = list.meta.total
    metrics.value = m
  } catch (e) {
    notify.error(e)
  } finally {
    loading.value = false
  }
}

let timer: number | undefined
watch(q, () => { window.clearTimeout(timer); timer = window.setTimeout(() => { page.value = 1; load() }, 350) })
watch([flow, status], () => { page.value = 1; router.replace({ query: { ...(flow.value ? { flow: flow.value } : {}), ...(q.value ? { q: q.value } : {}) } }); load() })
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
      <select v-model="flow" class="tm-select" aria-label="Fluxo">
        <option value="">Todos os fluxos</option>
        <option v-for="f in flows" :key="f.key" :value="f.key">{{ f.name }}</option>
      </select>
      <select v-model="status" class="tm-select" aria-label="Status">
        <option value="">Todos os status</option>
        <option value="starting,running">Em andamento</option>
        <option value="completed">Concluídas</option>
        <option value="cancelled,error">Canceladas / erro</option>
      </select>
      <span class="tm-muted" style="font-size:12px">{{ total }} solicitação(ões){{ flowName ? ` · ${flowName}` : '' }}</span>
    </div>
    <input v-model="q" class="tm-input" placeholder="Número, solicitante ou área..." aria-label="Buscar" />
  </div>
  <div class="tm-table-wrap">
    <table class="tm-table">
      <thead><tr><th>Nº / ano</th><th>Solicitante</th><th>Área do solicitante</th><th>Data do registro</th><th>Etapa atual</th><th>Responsável</th><th>Idade</th><th>Prazo</th><th>Status</th></tr></thead>
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
