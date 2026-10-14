<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Paginator from 'primevue/paginator'
import { api } from '@/api/http'
import type { Paged, TaskSummary } from '@/api/types'
import { useNotify } from '@/composables/notify'
import { fmtAge, fmtDateTime } from '@/composables/format'
import StatusPill from '@/components/StatusPill.vue'

type Scope = 'mine' | 'groups' | 'started' | 'completed'
const route = useRoute()
const router = useRouter()
const notify = useNotify()
const scope = ref<Scope>((route.query.scope as Scope) || 'mine')
const tasks = ref<TaskSummary[]>([])
const total = ref(0)
const page = ref(1)
const loading = ref(false)

const TABS: { key: Scope; label: string }[] = [
  { key: 'mine', label: 'Minhas tarefas' },
  { key: 'groups', label: 'Tarefas do meu grupo' },
  { key: 'started', label: 'Solicitações abertas por mim' },
  { key: 'completed', label: 'Concluídas por mim' },
]

async function load() {
  loading.value = true
  try {
    const res = await api<Paged<TaskSummary>>('/tasks', { query: { scope: scope.value, page: page.value, perPage: 25 } })
    tasks.value = res.data
    total.value = res.meta.total
  } catch (e) {
    notify.error(e)
  } finally {
    loading.value = false
  }
}

watch(scope, (s) => { page.value = 1; router.replace({ query: { scope: s } }); load() })
onMounted(load)
</script>

<template>
  <div class="tm-crumbs"><span>Início</span><span class="sep">›</span><strong style="color:#36495c">Tarefas</strong></div>
  <div class="tm-heading">
    <div><h1>Tarefas</h1><p class="tm-subtitle">Etapas que aguardam você ou seu grupo. Abra a tarefa para preencher o formulário da etapa.</p></div>
    <button class="tm-btn" @click="load"><i class="pi pi-refresh" /> Atualizar</button>
  </div>
  <div class="tm-tabbar">
    <button v-for="t in TABS" :key="t.key" class="tm-tab" :class="{ active: scope === t.key }" @click="scope = t.key">{{ t.label }}</button>
  </div>
  <div class="tm-table-wrap" style="border-top:1px solid var(--line);border-radius:11px">
    <table class="tm-table">
      <thead><tr><th>Solicitação</th><th>Etapa</th><th>Grupo / responsável</th><th>Solicitante</th><th>{{ scope === 'completed' ? 'Concluída' : 'Idade' }}</th><th>Prazo</th><th>Status</th></tr></thead>
      <tbody>
        <tr v-if="loading"><td colspan="7" class="tm-empty">Carregando...</td></tr>
        <tr v-else-if="!tasks.length"><td colspan="7"><div class="tm-empty"><div class="tm-empty-icon"><i class="pi pi-inbox" /></div><strong>Nada por aqui</strong>Não há tarefas neste filtro.</div></td></tr>
        <tr v-for="t in tasks" :key="t.id" class="clickable" @click="router.push({ name: 'task', params: { id: t.id } })">
          <td><div class="tm-strong">{{ t.instance?.number }}</div><div class="tm-flow-sub">{{ t.instance?.flowName }}</div></td>
          <td class="tm-strong">{{ t.name }}</td>
          <td>{{ t.candidateGroupName }}<div class="tm-flow-sub">{{ t.assignee?.name ?? 'Não assumida' }}</div></td>
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
