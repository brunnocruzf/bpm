<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError, newId } from '@/api/http'
import type { TaskDetail, TaskSummary } from '@/api/types'
import { useSession } from '@/stores/session'
import { useNotify } from '@/composables/notify'
import { fmtDateTime } from '@/composables/format'
import StatusPill from '@/components/StatusPill.vue'
import FormRenderer from '@/components/forms/FormRenderer.vue'
import ActionPlanPanel from '@/components/runtime/ActionPlanPanel.vue'
import TimelineList from '@/components/runtime/TimelineList.vue'
import AttachmentList from '@/components/runtime/AttachmentList.vue'

const props = defineProps<{ id: string }>()
const router = useRouter()
const session = useSession()
const notify = useNotify()

const task = ref<TaskDetail | null>(null)
const renderer = ref<InstanceType<typeof FormRenderer>>()
const serverErrors = ref<Record<string, string[]>>({})
const sending = ref(false)
const processing = ref(false)
const draftSavedAt = ref<string | null>(null)
// Mantém na tela o que foi enviado enquanto o engine confirma (evita parecer perda de dados).
const submittedData = ref<Record<string, any> | null>(null)
let idempotencyKey = newId()
let poll: number | undefined

const readOnly = computed(() => !task.value?.canWork)
const isMine = computed(() => task.value?.assignee?.id === session.me?.id)
const formData = computed(() => submittedData.value ?? task.value?.draft ?? task.value?.data ?? {})

async function load() {
  try {
    task.value = await api<TaskDetail>(`/tasks/${props.id}`)
    processing.value = task.value.status === 'completing'
    if (processing.value) startPoll()
  } catch (e) {
    notify.error(e)
  }
}

async function claim() {
  try {
    await api<TaskSummary>(`/tasks/${props.id}/claim`, { method: 'POST' })
    notify.success('Tarefa assumida por você.')
    await load()
  } catch (e) {
    notify.error(e)
    load()
  }
}

async function release() {
  try {
    await api(`/tasks/${props.id}/release`, { method: 'POST' })
    notify.info('Tarefa devolvida ao grupo.')
    await load()
  } catch (e) {
    notify.error(e)
  }
}

async function saveDraft() {
  try {
    const res = await api<{ at: string }>(`/tasks/${props.id}/draft`, { method: 'PUT', body: { data: renderer.value?.getData() ?? {} } })
    draftSavedAt.value = res.at
    notify.success('Rascunho salvo. Você pode continuar depois.')
  } catch (e) {
    notify.error(e)
  }
}

async function complete() {
  serverErrors.value = {}
  const result = await renderer.value?.validate()
  if (!result?.valid) {
    notify.info('Verifique os campos destacados no formulário.', 'Formulário incompleto')
    return
  }
  sending.value = true
  try {
    await api(`/tasks/${props.id}/complete`, { method: 'POST', idempotencyKey, body: { data: result.data, rowVersion: task.value?.rowVersion } })
    idempotencyKey = newId()
    submittedData.value = result.data
    processing.value = true
    notify.info('Etapa enviada. Aguardando confirmação do engine...', 'Processando')
    startPoll()
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) serverErrors.value = e.errors
    if (e instanceof ApiError && e.status !== 0) idempotencyKey = newId()
    if (e instanceof ApiError && e.status === 409) load()
    notify.error(e)
  } finally {
    sending.value = false
  }
}

function startPoll() {
  let tries = 0
  window.clearInterval(poll)
  poll = window.setInterval(async () => {
    tries++
    try {
      const t = await api<TaskDetail>(`/tasks/${props.id}`)
      if (t.status === 'completed') {
        window.clearInterval(poll)
        session.refreshCounters()
        notify.success(`Etapa "${t.name}" concluída na solicitação ${t.instance?.number}.`)
        router.push({ name: 'instance', params: { id: t.instance!.id } })
      } else if (t.status === 'open' || t.status === 'claimed') {
        window.clearInterval(poll)
        processing.value = false
        submittedData.value = null
        task.value = t
        notify.error(new Error('O engine recusou a conclusão. A etapa foi reaberta; tente novamente ou acione o suporte.'))
      } else if (tries > 40) {
        window.clearInterval(poll)
        notify.info('O processamento está demorando. A solicitação continuará assim que o engine responder.', 'Processando')
      }
    } catch {
      /* tenta novamente */
    }
  }, 1500)
}

onMounted(load)
onBeforeUnmount(() => window.clearInterval(poll))
</script>

<template>
  <div class="tm-crumbs">
    <RouterLink :to="{ name: 'inbox' }">Minhas tarefas</RouterLink><span class="sep">›</span>
    <RouterLink v-if="task?.instance" :to="{ name: 'instance', params: { id: task.instance.id } }">{{ task.instance.number }}</RouterLink><span class="sep">›</span>
    <span>{{ task?.name }}</span>
  </div>

  <template v-if="task">
    <div class="tm-heading">
      <div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <h1>{{ task.name }}</h1>
          <StatusPill kind="task" :status="task.status" />
          <span v-if="task.overdue" class="tm-pill red">Prazo vencido</span>
        </div>
        <p class="tm-subtitle">{{ task.instance?.flowName }} · <b>{{ task.instance?.number }}</b> · grupo {{ task.candidateGroupName }}<template v-if="task.dueAt"> · prazo {{ fmtDateTime(task.dueAt) }}</template></p>
      </div>
      <div class="tm-actions">
        <button v-if="task.canClaim" class="tm-btn" @click="claim"><i class="pi pi-user-plus" /> Assumir</button>
        <button v-if="isMine && task.status === 'claimed'" class="tm-btn ghost" @click="release">Devolver ao grupo</button>
        <span v-if="task.assignee" class="tm-pill neutral"><i class="pi pi-user" /> {{ task.assignee.name }}</span>
      </div>
    </div>

    <div v-if="processing" class="tm-note warn"><span class="tm-spinner" /><span><strong>Processando.</strong> Sua resposta foi registrada; o engine está avançando o processo. A tela será atualizada automaticamente.</span></div>
    <div v-else-if="!task.canWork && task.status !== 'completed'" class="tm-note"><i class="pi pi-info-circle" /><span>Somente leitura: {{ task.canClaim ? 'assuma a tarefa para preenchê-la.' : 'esta tarefa está com outra pessoa ou pertence a outro grupo.' }}</span></div>

    <div class="tm-grid-2" style="margin-top:12px">
      <div>
        <div v-if="task.description" class="tm-note" style="margin:0 0 14px"><i class="pi pi-book" /><span><strong>Instrução:</strong> {{ task.description }}</span></div>
        <ActionPlanPanel v-if="task.actionPlan" :task-id="task.id" :mode="task.actionPlan.mode" :actions="task.actionPlan.actions" :can-work="task.canWork" @updated="(a) => { if (task?.actionPlan) task.actionPlan.actions = a }" />
        <section class="tm-panel">
          <div class="tm-panel-head">
            <div><div class="tm-panel-title">{{ task.form?.title ?? 'Formulário' }}</div><div class="tm-panel-caption">{{ task.submission ? `Enviado por ${task.submission.authorName} em ${fmtDateTime(task.submission.createdAt)}` : 'Campos com * são obrigatórios' }}</div></div>
            <span v-if="task.draft && task.canWork" class="tm-pill amber">Rascunho carregado</span>
          </div>
          <div class="tm-panel-body">
            <div v-if="Object.keys(serverErrors).length" class="tm-note err" style="margin-top:0">
              <i class="pi pi-times-circle" />
              <span><strong>O servidor recusou alguns dados:</strong><br /><template v-for="(msgs, k) in serverErrors" :key="k">• {{ msgs.join(' ') }}<br /></template></span>
            </div>
            <FormRenderer v-if="task.form" ref="renderer" :schema="task.form.schema" :data="formData" :read-only="readOnly || processing" :task-id="task.id" />
          </div>
          <div v-if="task.canWork && !processing" class="tm-actions" style="justify-content:space-between;padding:12px 14px;border-top:1px solid #edf1f4">
            <span class="tm-muted" style="font-size:11px">{{ draftSavedAt ? `Rascunho salvo ${fmtDateTime(draftSavedAt)}` : 'A validação definitiva é feita no servidor.' }}</span>
            <div class="tm-actions">
              <button class="tm-btn" @click="saveDraft"><i class="pi pi-save" /> Salvar rascunho</button>
              <button class="tm-btn primary" :disabled="sending" @click="complete"><i class="pi pi-check" /> Concluir etapa</button>
            </div>
          </div>
        </section>
      </div>
      <aside>
        <section class="tm-panel" style="margin-bottom:14px">
          <div class="tm-panel-head"><div class="tm-panel-title">Solicitação</div><RouterLink v-if="task.instance" class="tm-btn small" :to="{ name: 'instance', params: { id: task.instance.id } }">Ver detalhes</RouterLink></div>
          <dl class="tm-kv tm-panel-body" style="margin:0">
            <dt>Número</dt><dd>{{ task.instance?.number }}</dd>
            <dt>Solicitante</dt><dd>{{ task.instance?.requesterName }}</dd>
            <dt>Área</dt><dd>{{ task.instance?.requesterArea ?? '—' }}</dd>
            <dt>Aberta em</dt><dd>{{ fmtDateTime(task.instance?.startedAt) }}</dd>
            <dt>Etapa criada</dt><dd>{{ fmtDateTime(task.createdAt) }}</dd>
          </dl>
        </section>
        <section class="tm-panel" style="margin-bottom:14px">
          <div class="tm-panel-head"><div class="tm-panel-title">Linha do tempo</div></div>
          <div class="tm-panel-body"><TimelineList :events="task.timeline" /></div>
        </section>
        <section class="tm-panel">
          <div class="tm-panel-head"><div class="tm-panel-title">Anexos desta etapa</div></div>
          <div class="tm-panel-body"><AttachmentList :items="task.attachments" /></div>
        </section>
      </aside>
    </div>
  </template>
  <div v-else class="tm-panel tm-empty">Carregando tarefa...</div>
</template>
