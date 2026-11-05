<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { api } from '@/api/http'
import type { InstanceDetail, TimelineEvent } from '@/api/types'
import { useNotify } from '@/composables/notify'
import { fmtDate, fmtDateTime } from '@/composables/format'
import StatusPill from '@/components/StatusPill.vue'
import BpmnViewer from '@/components/runtime/BpmnViewer.vue'
import TimelineList from '@/components/runtime/TimelineList.vue'
import AttachmentList from '@/components/runtime/AttachmentList.vue'
import SubmissionDialog from '@/components/runtime/SubmissionDialog.vue'
import InstanceReportDialog from '@/components/reports/InstanceReportDialog.vue'
import { useSession } from '@/stores/session'

const props = defineProps<{ id: string }>()
const notify = useNotify()
const inst = ref<InstanceDetail | null>(null)
const timeline = ref<TimelineEvent[]>([])
const tab = ref<'timeline' | 'answers' | 'attachments' | 'actions'>('timeline')
const submissionId = ref<string | null>(null)
const session = useSession()
const reportOpen = ref(false)
const reportStage = ref<string | null>(null)
// etapas humanas percorridas (tarefas e submissões), na ordem em que aconteceram
const reportStages = computed(() => {
  const map = new Map<string, { key: string; name: string; done: boolean }>()
  inst.value?.tasks.forEach((t) => map.set(t.stageKey, { key: t.stageKey, name: t.name, done: map.get(t.stageKey)?.done ?? false }))
  inst.value?.submissions.forEach((s) => map.set(s.stageKey, { key: s.stageKey, name: s.stageName, done: true }))
  return [...map.values()]
})
function openReport(stageKey: string | null = null) {
  reportStage.value = stageKey
  reportOpen.value = true
}
let poll: number | undefined

const openTasks = computed(() => inst.value?.tasks.filter((t) => ['open', 'claimed', 'completing'].includes(t.status)) ?? [])
const failed = computed(() => inst.value?.pendingCommands.filter((c) => c.state === 'FAILED') ?? [])

async function load() {
  try {
    const [detail, tl] = await Promise.all([
      api<InstanceDetail>(`/process-instances/${props.id}`),
      api<{ data: TimelineEvent[] }>(`/process-instances/${props.id}/timeline`),
    ])
    inst.value = detail
    timeline.value = tl.data
    window.clearTimeout(poll)
    // Enquanto o engine processa (start/conclusão/integração/timer), atualiza sozinho.
    if (detail.processing || detail.status === 'running') poll = window.setTimeout(load, detail.processing ? 2000 : 15000)
  } catch (e) {
    notify.error(e)
  }
}

onMounted(load)
onBeforeUnmount(() => window.clearTimeout(poll))
</script>

<template>
  <div class="tm-crumbs">
    <RouterLink :to="{ name: 'monitor' }">Acompanhar</RouterLink><span class="sep">›</span>
    <RouterLink v-if="inst" :to="{ name: 'monitor', query: { flow: inst.flow.key } }">{{ inst.flow.name }}</RouterLink><span class="sep">›</span>
    <span>{{ inst?.number }}</span>
  </div>

  <template v-if="inst">
    <div class="tm-heading">
      <div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <h1>{{ inst.number }}</h1>
          <StatusPill :status="inst.status" />
          <span v-if="inst.resultLabel" class="tm-pill brand">{{ inst.resultLabel }}</span>
          <span v-if="inst.processing" class="tm-processing"><span class="tm-spinner" /> Processando</span>
        </div>
        <p class="tm-subtitle">{{ inst.flow.name }} · versão {{ inst.version }} · aberta por <b>{{ inst.requesterName }}</b>{{ inst.requesterArea ? ` (${inst.requesterArea})` : '' }} em {{ fmtDateTime(inst.startedAt) }}</p>
      </div>
      <div class="tm-actions">
        <button v-if="session.can('reportsInstance')" class="tm-btn" @click="openReport()"><i class="pi pi-print" aria-hidden="true" /> Relatório / imprimir</button>
        <button class="tm-btn" @click="load"><i class="pi pi-refresh" /> Atualizar</button>
      </div>
    </div>

    <div v-if="failed.length" class="tm-note err" style="margin-top:0"><i class="pi pi-exclamation-triangle" /><span><strong>Falha de comunicação com o engine.</strong> {{ failed.length }} comando(s) aguardam reconciliação pela administração. Nenhum dado foi perdido.</span></div>

    <div class="tm-metrics">
      <div class="tm-metric"><div class="tm-metric-label">Etapas em aberto</div><div class="tm-metric-value">{{ openTasks.length }}</div></div>
      <div class="tm-metric"><div class="tm-metric-label">Respostas registradas</div><div class="tm-metric-value">{{ inst.submissions.length }}</div></div>
      <div class="tm-metric"><div class="tm-metric-label">Idade</div><div class="tm-metric-value">{{ inst.ageDays }} d</div></div>
      <div class="tm-metric"><div class="tm-metric-label">{{ inst.endedAt ? 'Encerrada em' : 'Anexos' }}</div><div class="tm-metric-value" style="font-size:17px">{{ inst.endedAt ? fmtDate(inst.endedAt) : inst.attachments.length }}</div></div>
    </div>

    <section v-if="openTasks.length" class="tm-panel" style="margin-bottom:14px">
      <div class="tm-panel-head"><div class="tm-panel-title">Etapas atuais</div></div>
      <table class="tm-table" style="min-width:0">
        <thead><tr><th>Etapa</th><th>Grupo</th><th>Responsável</th><th>Prazo</th><th>Status</th><th /></tr></thead>
        <tbody>
          <tr v-for="t in openTasks" :key="t.id">
            <td class="tm-strong">{{ t.name }}</td>
            <td>{{ t.candidateGroupName ?? 'Responsável direto' }}</td>
            <td>{{ t.assignee?.name ?? '—' }}</td>
            <td :class="{ 'tm-overdue': t.overdue }">{{ fmtDateTime(t.dueAt) }}</td>
            <td><StatusPill kind="task" :status="t.status" /></td>
            <td><RouterLink v-if="t.canWork || t.canClaim" class="tm-btn small primary" :to="{ name: 'task', params: { id: t.id } }">Abrir tarefa</RouterLink><RouterLink v-else class="tm-btn small" :to="{ name: 'task', params: { id: t.id } }">Ver</RouterLink></td>
          </tr>
        </tbody>
      </table>
    </section>

    <section class="tm-panel" style="margin-bottom:14px">
      <div class="tm-panel-head">
        <div><div class="tm-panel-title">Diagrama da execução</div><div class="tm-panel-caption">Azul: etapas atuais · verde: concluídas</div></div>
      </div>
      <div style="padding:10px"><BpmnViewer :xml="inst.diagram.bpmnXml" :active="inst.diagram.activeElements" :completed="inst.diagram.completedElements" :end-element="inst.diagram.endElement" height="420px" /></div>
    </section>

    <div class="tm-tabbar">
      <button class="tm-tab" :class="{ active: tab === 'timeline' }" @click="tab = 'timeline'">Linha do tempo</button>
      <button class="tm-tab" :class="{ active: tab === 'answers' }" @click="tab = 'answers'">Respostas <span class="tm-tab-count">{{ inst.submissions.length }}</span></button>
      <button class="tm-tab" :class="{ active: tab === 'attachments' }" @click="tab = 'attachments'">Anexos <span class="tm-tab-count">{{ inst.attachments.length }}</span></button>
      <button v-if="inst.supplierActions.length" class="tm-tab" :class="{ active: tab === 'actions' }" @click="tab = 'actions'">Plano de ação <span class="tm-tab-count">{{ inst.supplierActions.filter((a) => a.status !== 'superseded').length }}</span></button>
    </div>

    <section class="tm-panel tm-panel-body">
      <TimelineList v-if="tab === 'timeline'" :events="timeline" />
      <table v-else-if="tab === 'answers'" class="tm-table" style="min-width:0">
        <thead><tr><th>Etapa</th><th>Autor</th><th>Revisão</th><th>Enviado em</th><th /></tr></thead>
        <tbody>
          <tr v-for="s in inst.submissions" :key="s.id" class="clickable" @click="submissionId = s.id">
            <td class="tm-strong">{{ s.stageName }}</td><td>{{ s.authorName }}</td><td>{{ s.revision }}</td><td>{{ fmtDateTime(s.createdAt) }}</td>
            <td class="tm-actions"><button class="tm-link-btn">Ver resposta</button><button v-if="session.can('reportsInstance')" class="tm-link-btn" @click.stop="openReport(s.stageKey)"><i class="pi pi-print" aria-hidden="true" /> Imprimir etapa</button></td>
          </tr>
        </tbody>
      </table>
      <AttachmentList v-else-if="tab === 'attachments'" :items="inst.attachments" />
      <table v-else class="tm-table" style="min-width:0">
        <thead><tr><th>Ação</th><th>Responsável</th><th>Prazo</th><th>Status</th><th>Concluída por</th></tr></thead>
        <tbody>
          <tr v-for="a in inst.supplierActions" :key="a.id" :style="a.status === 'superseded' ? 'opacity:.55' : ''">
            <td class="tm-strong">{{ a.action }}</td><td>{{ a.responsible }}</td><td>{{ fmtDate(a.dueDate) }}</td>
            <td><span class="tm-pill" :class="a.status === 'done' ? 'green' : a.status === 'pending' ? 'amber' : 'neutral'">{{ a.status === 'done' ? 'Concluída' : a.status === 'pending' ? 'Pendente' : 'Substituída' }}</span></td>
            <td>{{ a.completedBy ?? '—' }}</td>
          </tr>
        </tbody>
      </table>
    </section>
  </template>
  <div v-else class="tm-panel tm-empty">Carregando solicitação...</div>

  <SubmissionDialog v-model:id="submissionId" />
  <InstanceReportDialog v-if="inst" v-model:visible="reportOpen" :instance-id="inst.id" :number="inst.number" :stages="reportStages" :initial-stage="reportStage" />
</template>
