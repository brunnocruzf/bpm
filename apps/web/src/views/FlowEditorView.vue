<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useConfirm } from 'primevue/useconfirm'
import Dialog from 'primevue/dialog'
import MultiSelect from 'primevue/multiselect'
import { api } from '@/api/http'
import type { CatalogItem, Condition, FormSchema, StageType, ValidationProblem, Version } from '@/api/types'
import { useSession } from '@/stores/session'
import { useNotify } from '@/composables/notify'
import { fmtDateTime, STAGE_TYPE_LABELS } from '@/composables/format'
import StatusPill from '@/components/StatusPill.vue'
import StageList from '@/components/editor/StageList.vue'
import StageSettings from '@/components/editor/StageSettings.vue'
import DiagramTab from '@/components/editor/DiagramTab.vue'
import FormBuilderPanel from '@/components/forms/FormBuilderPanel.vue'
import DeleteFlowDialog from '@/components/DeleteFlowDialog.vue'
import FormRenderer from '@/components/forms/FormRenderer.vue'

interface VersionRow { version: number; status: string; hash: string | null; publishedAt: string | null; engineDeployed: boolean; createdAt: string | null; updatedAt: string | null }

const props = defineProps<{ flowKey: string }>()
const flowKey = computed(() => props.flowKey)
const router = useRouter()
const session = useSession()
const notify = useNotify()
const confirm = useConfirm()

const flow = ref<CatalogItem | null>(null)
const versions = ref<VersionRow[]>([])
const version = ref<Version | null>(null)
const selectedKey = ref<string | null>(null)
const tab = ref<'stages' | 'forms' | 'diagram' | 'settings'>('stages')
const schemas = reactive<Record<string, FormSchema>>({})
const pending = reactive<Record<string, FormSchema>>({})
const problems = ref<ValidationProblem[]>([])
const showProblems = ref(true)
const busy = ref(false)
const status = ref('Rascunho não publicado')
const preview = ref(false)
const showLibrary = ref(false)
const library = ref<{ id: string; title: string; formKey: string; flowName: string; version: number; fieldCount: number }[]>([])
const settings = reactive({ name: '', category: '', description: '', instancePrefix: '', initiatorGroups: [] as string[] })
let deployPoll: number | undefined

const editable = computed(() => version.value?.status === 'draft' && session.can('design'))
const stage = computed(() => version.value?.stages.find((s) => s.key === selectedKey.value) ?? null)
const humanStages = computed(() => version.value?.stages.filter((s) => s.type === 'user_task') ?? [])
const errorCount = computed(() => problems.value.filter((p) => p.severity === 'error').length)
const pendingCount = computed(() => Object.keys(pending).length)
const base = computed(() => `/definitions/${flowKey.value}/versions/${version.value?.version}`)

// exclusão de rascunho: fluxo nunca publicado (inteiro) ou rascunho de fluxo já publicado
const deleting = ref<'flow' | 'draft' | null>(null)
function afterDelete() {
  const mode = deleting.value
  deleting.value = null
  if (mode === 'flow') router.push('/')
  else load(flow.value?.publishedVersion?.version)
}

async function load(versionNumber?: number) {
  busy.value = true
  try {
    const res = await api<{ flow: CatalogItem; versions: VersionRow[] }>(`/flows/${flowKey.value}`)
    flow.value = res.flow
    versions.value = res.versions
    const v = versionNumber ?? res.flow.draftVersion ?? res.versions[res.versions.length - 1]?.version ?? 1
    applyVersion(await api<Version>(`/definitions/${flowKey.value}/versions/${v}`))
    problems.value = version.value?.validation?.problems ?? []
    Object.assign(settings, { name: res.flow.name, category: res.flow.category, description: res.flow.description ?? '', instancePrefix: res.flow.instancePrefix, initiatorGroups: res.flow.initiatorGroups ?? [] })
  } catch (e) {
    notify.error(e)
  } finally {
    busy.value = false
  }
}

function applyVersion(v: Version) {
  const switched = version.value?.version !== v.version
  version.value = v
  if (switched) {
    Object.keys(schemas).forEach((k) => delete schemas[k])
    Object.keys(pending).forEach((k) => delete pending[k])
  }
  if (!v.stages.some((s) => s.key === selectedKey.value)) {
    selectedKey.value = v.stages.find((s) => s.type === 'user_task')?.key ?? v.stages[0]?.key ?? null
  }
  status.value = v.status === 'draft' ? 'Rascunho não publicado · alterações salvas no servidor' : v.engineDeployed ? 'Versão publicada e implantada no engine' : 'Publicada · implantação no engine em andamento'
}

async function reloadVersion() {
  if (version.value) applyVersion(await api<Version>(base.value))
}

async function ensureSchema(formKey?: string | null) {
  if (!formKey || schemas[formKey]) return
  try {
    const res = await api<{ schema: FormSchema }>(`${base.value}/forms/${formKey}`)
    schemas[formKey] = res.schema
  } catch (e) {
    notify.error(e)
  }
}
watch([stage, tab], () => ensureSchema(stage.value?.form?.formKey), { immediate: true })

async function mutate(fn: () => Promise<Version | { version: Version }>, ok?: string) {
  busy.value = true
  try {
    const res: any = await fn()
    applyVersion(res.version && res.stages === undefined ? res.version : res)
    if (ok) notify.success(ok)
    return res
  } catch (e) {
    notify.error(e)
  } finally {
    busy.value = false
  }
}

async function addStage(type: StageType) {
  await savePending()
  const res: any = await mutate(() => api(`${base.value}/stages`, { method: 'POST', body: { type, after: selectedKey.value } }),
    type === 'user_task' ? 'Nova etapa criada com formulário já associado.' : `${STAGE_TYPE_LABELS[type]} adicionado(a); não possui formulário.`)
  if (res?.stage) selectedKey.value = res.stage.key
}

const updateStage = (attrs: Record<string, unknown>) => {
  const key = selectedKey.value
  return mutate(() => api(`${base.value}/stages/${key}`, { method: 'PATCH', body: attrs }))
}
const setTransitions = (t: { target: string; condition: Condition | null; isDefault: boolean; label: string | null }[]) =>
  mutate(() => api(`${base.value}/stages/${selectedKey.value}/transitions`, { method: 'PUT', body: { transitions: t } }), 'Roteamento salvo.')
const reorder = (keys: string[]) => mutate(() => api(`${base.value}/stages-order`, { method: 'PUT', body: { keys } }))

function deleteStage() {
  const s = stage.value
  if (!s) return
  confirm.require({
    header: 'Excluir etapa',
    message: `Excluir "${s.name}"${s.form ? ' e o formulário associado' : ''}? A alteração vale apenas para este rascunho.`,
    acceptLabel: 'Excluir', rejectLabel: 'Cancelar', acceptClass: 'p-button-danger',
    accept: async () => {
      delete pending[s.form?.formKey ?? '']
      await mutate(() => api(`${base.value}/stages/${s.key}`, { method: 'DELETE' }), 'Etapa removida.')
    },
  })
}

function onBuilderChange(formKey: string, schema: FormSchema) {
  pending[formKey] = schema
  status.value = 'Formulário com alterações não salvas'
}

async function saveForm(formKey: string, quiet = false) {
  const schema = pending[formKey]
  if (!schema || !version.value) return true
  try {
    const res = await api<{ schema: FormSchema; lockVersion: number }>(`${base.value}/forms/${formKey}`, { method: 'PUT', body: { schema } })
    schemas[formKey] = res.schema
    delete pending[formKey]
    await reloadVersion()
    if (!quiet) notify.success('Formulário salvo. Componentes não suportados ou scripts foram removidos pelo servidor.')
    return true
  } catch (e) {
    notify.error(e, 'Formulário não salvo')
    return false
  }
}

async function savePending() {
  for (const k of Object.keys(pending)) {
    if (!(await saveForm(k, true))) return false
  }
  return true
}

async function validate(showToast = true) {
  if (!(await savePending())) return false
  try {
    const res = await api<{ problems: ValidationProblem[]; valid: boolean }>(`${base.value}/validate`, { method: 'POST' })
    problems.value = res.problems
    showProblems.value = true
    if (showToast) res.valid ? notify.success('Validação concluída: etapas, formulários, rotas e responsáveis estão configurados.') : notify.info(`${errorCount.value} problema(s) para corrigir antes de publicar.`, 'Validação')
    return res.valid
  } catch (e) {
    notify.error(e)
    return false
  }
}

async function publish() {
  if (!(await validate(false))) {
    notify.info('Corrija os problemas apontados antes de publicar.', 'Não foi possível publicar')
    return
  }
  confirm.require({
    header: `Publicar v${version.value?.version}`,
    message: 'A publicação congela BPMN, etapas e formulários desta versão. Instâncias novas usarão esta versão; as em andamento continuam na versão original.',
    acceptLabel: 'Publicar', rejectLabel: 'Cancelar',
    accept: async () => {
      await mutate(() => api(`${base.value}/publish`, { method: 'POST' }), 'Versão publicada. Implantação no engine em andamento.')
      startDeployPoll()
      load(version.value?.version)
    },
  })
}

function startDeployPoll() {
  let tries = 0
  window.clearInterval(deployPoll)
  deployPoll = window.setInterval(async () => {
    tries++
    await reloadVersion().catch(() => undefined)
    if (version.value?.engineDeployed || tries > 40) {
      window.clearInterval(deployPoll)
      if (version.value?.engineDeployed) notify.success('Versão implantada no Flowable. O fluxo já pode receber solicitações.')
    }
  }, 2500)
}

async function newVersion() {
  const v: any = await mutate(() => api(`/definitions/${flowKey.value}/versions`, { method: 'POST' }), 'Novo rascunho criado a partir da última versão.')
  if (v) load(v.version)
}

async function openLibrary() {
  showLibrary.value = true
  try {
    library.value = (await api<{ data: typeof library.value }>('/form-library')).data.filter((f) => f.formKey !== stage.value?.form?.formKey || f.version !== version.value?.version)
  } catch (e) {
    notify.error(e)
  }
}

async function copyFrom(sourceId: string) {
  const formKey = stage.value?.form?.formKey
  if (!formKey) return
  try {
    const res = await api<{ schema: FormSchema }>(`${base.value}/forms/${formKey}/copy-from`, { method: 'POST', body: { sourceFormId: sourceId } })
    schemas[formKey] = res.schema
    delete pending[formKey]
    showLibrary.value = false
    await reloadVersion()
    notify.success('Modelo copiado: o formulário desta etapa é independente do original.')
  } catch (e) {
    notify.error(e)
  }
}

async function saveSettings() {
  try {
    const res = await api<{ flow: CatalogItem }>(`/flows/${flowKey.value}`, {
      method: 'PATCH',
      body: { name: settings.name, category: settings.category, description: settings.description || null, instancePrefix: settings.instancePrefix, initiatorGroups: settings.initiatorGroups.length ? settings.initiatorGroups : null },
    })
    flow.value = res.flow
    notify.success('Configurações do fluxo salvas.')
  } catch (e) {
    notify.error(e)
  }
}

function goToProblem(p: ValidationProblem) {
  if (p.stageKey) {
    selectedKey.value = p.stageKey
    tab.value = 'stages'
  }
}

async function switchTab(t: typeof tab.value) {
  if (t !== 'forms' && t !== 'stages') await savePending()
  tab.value = t
  preview.value = false
}

async function selectStage(key: string) {
  selectedKey.value = key
}

onMounted(() => load())
onBeforeUnmount(() => window.clearInterval(deployPoll))
</script>

<template>
  <div class="tm-crumbs">
    <RouterLink to="/">Catálogo de fluxos</RouterLink><span class="sep">›</span><span>{{ flow?.name ?? flowKey }}</span>
  </div>

  <div v-if="version" class="editor-top">
    <div class="editor-title">
      <button class="tm-btn icon" aria-label="Voltar" @click="router.push('/')"><i class="pi pi-angle-left" /></button>
      <div>
        <div style="display:flex;align-items:center;gap:9px;flex-wrap:wrap">
          <h1>{{ flow?.name }}</h1>
          <StatusPill kind="version" :status="version.status" :label="`${version.status === 'draft' ? 'Rascunho' : version.status === 'published' ? 'Publicado' : 'Substituída'} v${version.version}`" />
          <span v-if="version.status === 'published' && !version.engineDeployed" class="tm-processing"><span class="tm-spinner" /> implantando no engine</span>
        </div>
        <p class="tm-subtitle">Configure o processo por etapas. Cada tarefa humana tem um formulário ligado a ela; decisões e integrações definem os caminhos.</p>
      </div>
    </div>
    <div class="tm-actions">
      <select class="tm-select" :value="version.version" aria-label="Versão" @change="load(Number(($event.target as HTMLSelectElement).value))">
        <option v-for="v in versions" :key="v.version" :value="v.version">v{{ v.version }} · {{ v.status === 'draft' ? 'rascunho' : v.status === 'published' ? 'publicada' : 'substituída' }}</option>
      </select>
      <template v-if="editable">
        <button class="tm-btn" :disabled="!pendingCount" @click="savePending().then((ok) => ok && notify.success('Rascunho salvo.'))"><i class="pi pi-save" /> Salvar rascunho<span v-if="pendingCount" class="tm-tab-count">{{ pendingCount }}</span></button>
        <button class="tm-btn" :disabled="busy" @click="validate()"><i class="pi pi-check-square" /> Validar</button>
      </template>
      <button v-if="version.status === 'draft' && session.can('publish')" class="tm-btn primary" :disabled="busy" @click="publish"><i class="pi pi-send" /> Publicar</button>
      <button v-if="version.status !== 'draft' && session.can('design') && !flow?.hasDraft" class="tm-btn primary" :disabled="busy" @click="newVersion"><i class="pi pi-copy" /> Nova versão</button>
      <button v-if="version.status !== 'draft' && flow?.hasDraft" class="tm-btn" @click="load(flow?.draftVersion ?? undefined)">Abrir rascunho v{{ flow?.draftVersion }}</button>
      <template v-if="version.status === 'draft' && session.can('design')">
        <button v-if="!flow?.publishedVersion" class="tm-btn ghost danger" :disabled="busy" v-tooltip.bottom="'O fluxo nunca foi publicado: pode ser excluído'" @click="deleting = 'flow'"><i class="pi pi-trash" /> Excluir fluxo</button>
        <button v-else class="tm-btn ghost danger" :disabled="busy" v-tooltip.bottom="'Descarta só o rascunho; a versão publicada continua'" @click="deleting = 'draft'"><i class="pi pi-trash" /> Descartar rascunho</button>
      </template>
    </div>
  </div>

  <div v-if="version && version.status !== 'draft'" class="tm-note" style="margin:-6px 0 14px">
    <i class="pi pi-lock" /><span>Versões publicadas são imutáveis (BPMN, etapas e formulários congelados, hash <code>{{ version.hash?.slice(0, 12) }}</code>). Para alterar, crie uma <b>nova versão</b>.</span>
  </div>

  <div v-if="version" class="tm-tabbar">
    <button class="tm-tab" :class="{ active: tab === 'stages' }" @click="switchTab('stages')">Etapas <span class="tm-tab-count">{{ version.stages.length }}</span></button>
    <button class="tm-tab" :class="{ active: tab === 'forms' }" @click="switchTab('forms')">Formulários <span class="tm-tab-count">{{ humanStages.length }}</span></button>
    <button class="tm-tab" :class="{ active: tab === 'diagram' }" @click="switchTab('diagram')">Diagrama BPMN</button>
    <button class="tm-tab" :class="{ active: tab === 'settings' }" @click="switchTab('settings')">Configurações</button>
  </div>

  <div v-if="problems.length && showProblems && version" class="problems tm-panel">
    <div class="tm-panel-head">
      <div class="tm-panel-title"><i class="pi" :class="errorCount ? 'pi-exclamation-triangle' : 'pi-check-circle'" :style="{ color: errorCount ? 'var(--red)' : 'var(--green)' }" /> Validação: {{ errorCount }} erro(s), {{ problems.filter((p) => p.severity === 'warning').length }} aviso(s)</div>
      <button class="tm-btn small ghost" @click="showProblems = false">Ocultar</button>
    </div>
    <ul>
      <li v-for="(p, i) in problems" :key="i" :class="p.severity" @click="goToProblem(p)">
        <i class="pi" :class="p.severity === 'error' ? 'pi-times-circle' : p.severity === 'warning' ? 'pi-exclamation-circle' : 'pi-info-circle'" /> {{ p.message }}
      </li>
    </ul>
  </div>

  <template v-if="version">
    <!-- ETAPAS -->
    <div v-if="tab === 'stages'" class="editor-grid">
      <StageList :stages="version.stages" :selected-key="selectedKey" :problems="problems" :editable="editable" @select="selectStage" @add="addStage" @reorder="reorder" />
      <StageSettings
        v-if="stage"
        :stage="stage"
        :stages="version.stages"
        :fields="version.fields"
        :user-fields="version.userFields"
        :editable="editable"
        :is-opening="version.openingStageKey === stage.key"
        :api-base="base"
        @update="updateStage"
        @delete="deleteStage"
        @transitions="setTransitions"
        @open-form="switchTab('forms')"
      />
      <section v-if="stage" class="tm-panel builder-panel">
        <template v-if="stage.type === 'user_task' && stage.form">
          <div class="tm-panel-head">
            <div style="display:flex;gap:9px;align-items:center">
              <div class="b-icon"><i class="pi pi-file-edit" /></div>
              <div><div class="tm-panel-title">{{ stage.form.title }}</div><div class="tm-panel-caption">Arraste componentes do menu para o formulário</div></div>
            </div>
            <span class="tm-pill brand">Vinculado à {{ stage.name }}</span>
          </div>
          <div class="builder-body">
            <FormBuilderPanel v-if="schemas[stage.form.formKey]" :schema="pending[stage.form.formKey] ?? schemas[stage.form.formKey]" :form-key="`${version.version}:${stage.form.formKey}`" :read-only="!editable" @change="(s) => onBuilderChange(stage!.form!.formKey, s)" />
            <div v-else class="tm-empty">Carregando formulário...</div>
          </div>
          <div class="builder-foot">
            <span>{{ stage.form.fieldCount }} campo(s) salvos · Schema Form.io · chave <code>{{ stage.form.formKey }}</code></span>
            <button v-if="editable" class="tm-btn small primary" :disabled="!pending[stage.form.formKey]" @click="saveForm(stage.form.formKey)">Salvar formulário</button>
          </div>
        </template>
        <div v-else class="tm-empty">
          <div class="tm-empty-icon"><i class="pi" :class="stage.type === 'exclusive_gateway' ? 'pi-question-circle' : stage.type === 'service_task' ? 'pi-cog' : stage.type === 'timer' ? 'pi-clock' : 'pi-flag'" /></div>
          <strong>Esta etapa não precisa de formulário</strong>
          {{ stage.type === 'exclusive_gateway' ? 'Decisões usam dados de etapas anteriores. Configure os caminhos em "O que ocorre depois?".' : 'Configure o comportamento no painel ao lado.' }}
        </div>
      </section>
    </div>

    <!-- FORMULÁRIOS -->
    <div v-else-if="tab === 'forms'" class="forms-grid">
      <section class="tm-panel" style="align-self:start">
        <div class="tm-panel-head"><div><div class="tm-panel-title">Formulários do fluxo</div><div class="tm-panel-caption">Cada formulário pertence a uma etapa</div></div><span class="tm-pill neutral">{{ humanStages.length }}</span></div>
        <div style="padding:8px">
          <button v-for="s in humanStages" :key="s.key" class="form-item" :class="{ active: s.key === selectedKey }" @click="selectedKey = s.key; preview = false">
            <strong><i class="pi pi-file" /> {{ s.form?.title }}</strong>
            <span>Etapa: {{ s.name }} · {{ s.form?.fieldCount ?? 0 }} campo(s){{ pending[s.form?.formKey ?? ''] ? ' · não salvo' : '' }}</span>
          </button>
        </div>
      </section>
      <section v-if="stage?.form" class="tm-panel builder-panel">
        <div class="tm-panel-head">
          <div><div class="tm-panel-title">{{ stage.form.title }}</div><div class="tm-panel-caption">Etapa "{{ stage.name }}" · {{ session.groupName(stage.lane) }}</div></div>
          <div class="tm-actions">
            <button class="tm-btn small" @click="preview = !preview"><i class="pi" :class="preview ? 'pi-pencil' : 'pi-eye'" /> {{ preview ? 'Voltar ao editor' : 'Pré-visualizar como usuário' }}</button>
            <button v-if="editable" class="tm-btn small" @click="openLibrary"><i class="pi pi-clone" /> Usar como modelo</button>
            <button v-if="editable" class="tm-btn small primary" :disabled="!pending[stage.form.formKey]" @click="saveForm(stage.form.formKey)">Salvar formulário</button>
          </div>
        </div>
        <div class="builder-body">
          <template v-if="schemas[stage.form.formKey]">
            <div v-if="preview" class="preview-sheet">
              <div class="tm-note" style="margin-top:0"><i class="pi pi-eye" /><span>Pré-visualização: campos automáticos e consultas AX são preenchidos pelo servidor na execução real.</span></div>
              <FormRenderer :schema="pending[stage.form.formKey] ?? schemas[stage.form.formKey]" preview />
            </div>
            <FormBuilderPanel v-else :schema="pending[stage.form.formKey] ?? schemas[stage.form.formKey]" :form-key="`${version.version}:${stage.form.formKey}:full`" :read-only="!editable" @change="(s) => onBuilderChange(stage!.form!.formKey, s)" />
          </template>
        </div>
      </section>
      <section v-else class="tm-panel tm-empty"><strong>Selecione um formulário</strong>Formulários existem apenas em tarefas humanas.</section>
    </div>

    <!-- DIAGRAMA -->
    <DiagramTab
      v-else-if="tab === 'diagram'"
      :flow-key="flowKey"
      :version="version"
      :editable="editable"
      :problems="problems"
      :selected-key="selectedKey"
      @select="(k) => { selectedKey = k; tab = 'stages' }"
      @imported="(v) => applyVersion(v)"
    />

    <!-- CONFIGURAÇÕES -->
    <div v-else class="settings-grid">
      <section class="tm-panel">
        <div class="tm-panel-head"><div class="tm-panel-title">Dados do fluxo</div></div>
        <div class="tm-panel-body">
          <div class="tm-field"><label>Nome</label><input v-model="settings.name" :disabled="!session.can('design')" maxlength="90" /></div>
          <div class="tm-field"><label>Área / categoria</label><select v-model="settings.category" :disabled="!session.can('design')"><option v-for="c in session.meta?.categories" :key="c">{{ c }}</option></select></div>
          <div class="tm-field"><label>Descrição</label><textarea v-model="settings.description" :disabled="!session.can('design')" maxlength="240" /></div>
          <div class="tm-field"><label>Prefixo do número da solicitação</label><input v-model="settings.instancePrefix" :disabled="!session.can('design')" maxlength="6" /><div class="tm-hint">Ex.: {{ settings.instancePrefix || 'IRR' }}-000001/26 (sequência anual transacional).</div></div>
          <div class="tm-field">
            <label>Quem pode abrir solicitações (botão Novo)</label>
            <MultiSelect v-model="settings.initiatorGroups" :options="session.meta?.groups ?? []" option-label="name" option-value="key" placeholder="Qualquer usuário com papel Iniciador" display="chip" style="width:100%" :disabled="!session.can('design')" />
          </div>
          <div class="tm-hint" style="margin-bottom:10px">Chave técnica: <code>{{ flowKey }}</code> (id do processo no BPMN)</div>
          <button v-if="session.can('design')" class="tm-btn primary" @click="saveSettings">Salvar configurações</button>
        </div>
      </section>
      <section class="tm-panel">
        <div class="tm-panel-head"><div class="tm-panel-title">Histórico de versões</div></div>
        <table class="tm-table" style="min-width:0">
          <thead><tr><th>Versão</th><th>Estado</th><th>Publicada</th><th>Engine</th><th>Hash</th></tr></thead>
          <tbody>
            <tr v-for="v in versions" :key="v.version" class="clickable" @click="load(v.version)">
              <td class="tm-strong">v{{ v.version }}</td>
              <td><StatusPill kind="version" :status="v.status" /></td>
              <td>{{ fmtDateTime(v.publishedAt) }}</td>
              <td>{{ v.status === 'draft' ? '—' : v.engineDeployed ? 'Implantada' : 'Pendente' }}</td>
              <td><code>{{ v.hash?.slice(0, 10) ?? '—' }}</code></td>
            </tr>
          </tbody>
        </table>
      </section>
    </div>

    <div class="editor-foot">
      <span class="tm-muted" style="font-size:11px">{{ status }}</span>
      <span class="tm-muted" style="font-size:11px">Versão {{ version.version }} · revisão {{ version.lockVersion }}</span>
    </div>
  </template>
  <div v-else class="tm-panel tm-empty">Carregando fluxo...</div>

  <Dialog v-model:visible="showLibrary" modal header="Usar formulário como modelo" :style="{ width: 'min(640px, 96vw)' }">
    <p class="tm-muted" style="font-size:12px;margin-top:0">O schema será copiado para o formulário desta etapa como uma cópia independente; alterações futuras no original não afetam esta etapa.</p>
    <table class="tm-table" style="min-width:0">
      <thead><tr><th>Formulário</th><th>Fluxo</th><th>Campos</th><th /></tr></thead>
      <tbody>
        <tr v-for="f in library" :key="f.id">
          <td class="tm-strong">{{ f.title }}</td>
          <td>{{ f.flowName }} · v{{ f.version }}</td>
          <td>{{ f.fieldCount }}</td>
          <td><button class="tm-btn small" @click="copyFrom(f.id)">Copiar</button></td>
        </tr>
      </tbody>
    </table>
  </Dialog>

  <DeleteFlowDialog v-if="deleting && flow" :flow="flow" :mode="deleting" :version="version?.version" @close="deleting = null" @deleted="afterDelete" />
</template>

<style scoped>
.editor-top { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: 16px; margin: 4px 0 18px; }
.editor-title { display: flex; align-items: flex-start; gap: 12px; }
.editor-grid { display: grid; grid-template-columns: 250px minmax(280px, 0.85fr) minmax(440px, 1.65fr); gap: 14px; align-items: start; }
@media (max-width: 1280px) { .editor-grid { grid-template-columns: 240px minmax(0, 1fr); } .editor-grid .builder-panel { grid-column: 1 / -1; } }
@media (max-width: 760px) { .editor-grid { grid-template-columns: 1fr; } }
.forms-grid { display: grid; grid-template-columns: 270px minmax(0, 1fr); gap: 14px; }
@media (max-width: 900px) { .forms-grid { grid-template-columns: 1fr; } }
.settings-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 14px; align-items: start; }
@media (max-width: 900px) { .settings-grid { grid-template-columns: 1fr; } }
.builder-panel { display: flex; flex-direction: column; min-height: 480px; }
.builder-body { flex: 1; padding: 14px; background-color: #fcfdfd; background-image: radial-gradient(#dce4e9 0.75px, transparent 0.75px); background-size: 15px 15px; overflow: auto; }
.builder-foot { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 10px 14px; border-top: 1px solid #edf1f4; color: #8794a0; font-size: 11px; }
.b-icon { width: 30px; height: 30px; display: grid; place-items: center; border-radius: 8px; color: var(--blue); background: var(--blue-soft); }
.preview-sheet { max-width: 760px; margin: 0 auto; padding: 18px; background: #fff; border: 1px solid #e2e8ed; border-radius: 9px; box-shadow: 0 5px 16px rgba(36, 57, 79, 0.055); }
.form-item { display: block; width: 100%; border: 0; border-radius: 7px; padding: 10px; background: transparent; text-align: left; color: #435568; cursor: pointer; font-family: inherit; }
.form-item:hover, .form-item.active { background: #eff8f8; }
.form-item strong { display: block; font-size: 12px; }
.form-item span { display: block; margin-top: 3px; font-size: 11px; color: #83909d; }
.problems { margin-bottom: 14px; }
.problems ul { list-style: none; margin: 0; padding: 6px 14px 12px; max-height: 190px; overflow: auto; }
.problems li { font-size: 12px; padding: 5px 0; cursor: pointer; display: flex; gap: 7px; align-items: baseline; }
.problems li.error { color: #87413a; }
.problems li.warning { color: #7d5418; }
.problems li.info { color: #526677; }
.editor-foot { display: flex; justify-content: space-between; margin-top: 14px; }
code { font-size: 10px; background: #eef2f5; padding: 1px 4px; border-radius: 4px; }
</style>
