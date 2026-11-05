<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import MultiSelect from 'primevue/multiselect'
import type { AssignmentMode, Condition, FieldInfo, Stage, StageType, UserFieldInfo } from '@/api/types'
import { STAGE_TYPE_LABELS } from '@/composables/format'
import { useSession } from '@/stores/session'
import RoutingEditor from './RoutingEditor.vue'
import StageNotification from './StageNotification.vue'

const props = defineProps<{ stage: Stage; stages: Stage[]; fields: FieldInfo[]; userFields?: UserFieldInfo[]; editable: boolean; isOpening: boolean; apiBase?: string }>()
const emit = defineEmits<{
  update: [attrs: Record<string, unknown>]
  delete: []
  openForm: []
  transitions: [t: { target: string; condition: Condition | null; isDefault: boolean; label: string | null }[]]
}>()
const session = useSession()

const name = ref('')
const description = ref('')
const config = ref<Record<string, any>>({})
let nameTimer: number | undefined
let descTimer: number | undefined

watch(() => props.stage.key, () => {
  name.value = props.stage.name
  description.value = props.stage.description ?? ''
  config.value = JSON.parse(JSON.stringify(props.stage.config ?? {}))
}, { immediate: true })
watch(() => props.stage.config, (c) => { config.value = JSON.parse(JSON.stringify(c ?? {})) })

const groups = computed(() => session.meta?.groups ?? [])
const dateFields = computed(() => props.fields.filter((f) => f.type === 'datetime'))
const selectedOperation = computed(() => session.meta?.serviceOperations.find((o) => o.key === config.value.operation) ?? null)
const changeableTypes: StageType[] = ['user_task', 'exclusive_gateway', 'parallel_gateway', 'service_task', 'timer']
const assignmentModes: { value: AssignmentMode; label: string }[] = [
  { value: 'group', label: 'Grupo' },
  { value: 'field', label: 'Campo do formulário' },
  { value: 'requester', label: 'Solicitante' },
  { value: 'requester_manager', label: 'Gestor do solicitante' },
]
const assignmentMode = computed<AssignmentMode>(() => config.value.assignment?.mode ?? 'group')
const assignmentFields = computed<string[]>(() => config.value.assignment?.fields ?? [])
const freeUserFields = computed(() => (props.userFields ?? []).filter((f) => !assignmentFields.value.includes(f.key)))
const laneIsAssignee = computed(() => props.stage.type === 'user_task' && assignmentMode.value === 'group')

function onName() {
  window.clearTimeout(nameTimer)
  nameTimer = window.setTimeout(() => { if (name.value.trim() && name.value !== props.stage.name) emit('update', { name: name.value.trim() }) }, 600)
}
function onDescription() {
  window.clearTimeout(descTimer)
  descTimer = window.setTimeout(() => emit('update', { description: description.value }), 800)
}
function saveConfig() {
  emit('update', { config: config.value })
}
function setConfig(key: string, value: unknown) {
  config.value = { ...config.value, [key]: value }
  saveConfig()
}
// o restante da regra (excludeFields, requiredUnless) não tem controle na tela e é preservado pelo espalhamento
function setAssignment(patch: Record<string, unknown>) {
  setConfig('assignment', { ...config.value.assignment, ...patch })
}
function setAssignmentMode(mode: AssignmentMode) {
  if (mode === 'group') setConfig('assignment', null)
  else setAssignment({ mode, fallbackGroup: config.value.assignment?.fallbackGroup ?? props.stage.lane ?? undefined })
}
function moveAssignmentField(index: number, delta: number) {
  const fields = [...assignmentFields.value]
  fields.splice(index + delta, 0, ...fields.splice(index, 1))
  setAssignment({ fields })
}
function userFieldLabel(key: string) {
  return props.userFields?.find((f) => f.key === key)?.label ?? key
}
</script>

<template>
  <section class="tm-panel settings">
    <div class="tm-panel-head">
      <div><div class="tm-panel-title">Configuração da etapa</div><div class="tm-panel-caption">Quem faz, o que preenche e o que ocorre depois</div></div>
      <button v-if="editable && stage.type !== 'start'" class="tm-btn ghost small danger" @click="emit('delete')"><i class="pi pi-trash" /> Excluir</button>
    </div>
    <div class="tm-panel-body">
      <div class="tm-field">
        <label for="st-name">Nome da etapa <span class="tm-req">*</span></label>
        <input id="st-name" v-model="name" maxlength="120" :disabled="!editable" @input="onName" />
      </div>
      <div class="tm-field">
        <label for="st-type">Tipo de etapa</label>
        <select v-if="changeableTypes.includes(stage.type)" id="st-type" :value="stage.type" :disabled="!editable" @change="emit('update', { type: ($event.target as HTMLSelectElement).value })">
          <option v-for="t in changeableTypes" :key="t" :value="t">{{ STAGE_TYPE_LABELS[t] }}</option>
        </select>
        <input v-else id="st-type" :value="STAGE_TYPE_LABELS[stage.type]" disabled />
        <div class="tm-hint">
          <template v-if="stage.type === 'user_task'">Tarefa humana pede responsável e formulário.</template>
          <template v-else-if="stage.type === 'exclusive_gateway'">Decisão escolhe UM caminho conforme dados de etapas anteriores; não tem formulário.</template>
          <template v-else-if="stage.type === 'parallel_gateway'">Divide em trilhas simultâneas ou sincroniza trilhas (várias entradas).</template>
          <template v-else-if="stage.type === 'service_task'">Executada automaticamente pelo worker de integrações (AX).</template>
          <template v-else-if="stage.type === 'timer'">Aguarda um prazo ou uma data informada em formulário.</template>
          <template v-else-if="stage.type === 'start'">Ponto de partida. A primeira tarefa humana após o início é o formulário de abertura (botão "Novo").</template>
          <template v-else>Encerra o caminho com um resultado de negócio.</template>
        </div>
      </div>

      <template v-if="stage.type === 'user_task'">
        <div class="tm-field">
          <label for="st-who">Quem executa? <span class="tm-req">*</span></label>
          <select id="st-who" :value="assignmentMode" :disabled="!editable || isOpening" @change="setAssignmentMode(($event.target as HTMLSelectElement).value as AssignmentMode)">
            <option v-for="m in assignmentModes" :key="m.value" :value="m.value">{{ m.label }}</option>
          </select>
          <div v-if="isOpening" class="tm-hint">A etapa de abertura é preenchida pelo solicitante: o grupo define quem pode abrir.</div>
          <div v-else-if="assignmentMode !== 'group'" class="tm-hint">A tarefa vai só para esta pessoa, e o grupo da raia não a vê. O sistema define o responsável ao criar a tarefa.</div>
        </div>
        <div v-if="assignmentMode === 'field'" class="tm-field">
          <label for="st-who-field">Campos que definem o responsável <span class="tm-req">*</span></label>
          <ol v-if="assignmentFields.length" class="who-list">
            <li v-for="(key, i) in assignmentFields" :key="key">
              <span>{{ userFieldLabel(key) }}</span>
              <template v-if="editable">
                <button class="tm-btn ghost small" type="button" :disabled="i === 0" aria-label="Subir" @click="moveAssignmentField(i, -1)"><i class="pi pi-arrow-up" /></button>
                <button class="tm-btn ghost small" type="button" :disabled="i === assignmentFields.length - 1" aria-label="Descer" @click="moveAssignmentField(i, 1)"><i class="pi pi-arrow-down" /></button>
                <button class="tm-btn ghost small" type="button" aria-label="Remover" @click="setAssignment({ fields: assignmentFields.filter((f) => f !== key) })"><i class="pi pi-times" /></button>
              </template>
            </li>
          </ol>
          <select id="st-who-field" :value="''" :disabled="!editable || !freeUserFields.length" @change="setAssignment({ fields: [...assignmentFields, ($event.target as HTMLSelectElement).value] })">
            <option value="">{{ (userFields ?? []).length ? 'Adicionar campo...' : 'Nenhum campo disponível' }}</option>
            <option v-for="f in freeUserFields" :key="f.key" :value="f.key">{{ f.label }}</option>
          </select>
          <div class="tm-hint">O primeiro preenchido vence. Só aparecem campos de consulta de colaborador em que o usuário é confirmado pelo servidor, nunca valores digitados.</div>
          <div v-if="!userFields?.length" class="tm-hint who-empty">Este fluxo ainda não tem campo que identifique um usuário. Em uma etapa anterior, abra a consulta de colaborador e, em "Preencher campos com o resultado", ligue "ID do colaborador" ou "ID do gestor imediato" a uma chave nova, sem criar campo no formulário.</div>
        </div>
        <div v-if="assignmentMode !== 'group'" class="tm-field">
          <label for="st-who-fallback">Grupo de contingência <span class="tm-req">*</span></label>
          <select id="st-who-fallback" :value="config.assignment?.fallbackGroup ?? ''" :disabled="!editable" @change="setAssignment({ fallbackGroup: ($event.target as HTMLSelectElement).value || undefined })">
            <option value="">Selecione o grupo...</option>
            <option v-for="g in groups" :key="g.key" :value="g.key">{{ g.name }}</option>
          </select>
          <div class="tm-hint">Recebe a tarefa quando o responsável não puder executá-la (inativo, sem permissão ou impedido pela regra).</div>
        </div>
        <label v-if="assignmentMode === 'field' || assignmentMode === 'requester_manager'" class="who-check">
          <input type="checkbox" :checked="!!config.assignment?.excludeRequester" :disabled="!editable" @change="setAssignment({ excludeRequester: ($event.target as HTMLInputElement).checked })" />
          <span>Não permitir o solicitante</span>
        </label>
      </template>

      <div v-if="['user_task', 'service_task', 'start', 'end', 'exclusive_gateway', 'parallel_gateway', 'timer'].includes(stage.type)" class="tm-field">
        <label for="st-lane">{{ laneIsAssignee ? 'Grupo responsável' : 'Raia no diagrama' }} <span v-if="laneIsAssignee" class="tm-req">*</span></label>
        <select id="st-lane" :value="stage.lane ?? ''" :disabled="!editable" @change="emit('update', { lane: ($event.target as HTMLSelectElement).value || null })">
          <option value="">{{ laneIsAssignee ? 'Selecione o grupo...' : 'Automática (herda da etapa vizinha)' }}</option>
          <option v-for="g in groups" :key="g.key" :value="g.key">{{ g.name }}</option>
        </select>
      </div>

      <template v-if="stage.type === 'user_task'">
        <div class="tm-field">
          <label for="st-desc">Instrução para o usuário</label>
          <textarea id="st-desc" v-model="description" :disabled="!editable" placeholder="Explique o que deve ser feito nesta etapa..." @input="onDescription" />
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div class="tm-field">
            <label for="st-sla">Prazo (SLA, dias)</label>
            <input id="st-sla" type="number" min="1" max="365" :value="config.slaDays ?? ''" :disabled="!editable" @change="setConfig('slaDays', ($event.target as HTMLInputElement).value || null)" />
          </div>
          <div class="tm-field">
            <label for="st-plan">Plano de ação</label>
            <select id="st-plan" :value="config.actionPlan?.mode ?? ''" :disabled="!editable" @change="setConfig('actionPlan', ($event.target as HTMLSelectElement).value ? { mode: ($event.target as HTMLSelectElement).value, field: 'acoes' } : null)">
              <option value="">Não usa</option>
              <option value="capture">Registrar ações (repetidor "acoes")</option>
              <option value="execute">Executar ações uma a uma</option>
            </select>
          </div>
        </div>
        <div class="tm-field">
          <label>Notificar ao concluir</label>
          <MultiSelect :model-value="config.notifyOnComplete ?? []" :options="groups" option-label="name" option-value="key" placeholder="Nenhum grupo" :disabled="!editable" display="chip" style="width:100%" @update:model-value="(v) => setConfig('notifyOnComplete', v)" />
        </div>
        <StageNotification v-if="apiBase && !isOpening" :config="config.notifyAssignee" :editable="editable" :preview-base="`${apiBase}/stages/${stage.key}`" :lane="stage.lane" :assignment="config.assignment" @save="(v) => setConfig('notifyAssignee', v)" />
        <div v-else-if="isOpening" class="tm-hint" style="margin:8px 0">Etapa de abertura: preenchida pelo solicitante, não gera e-mail de nova tarefa.</div>
        <div class="assoc">
          <div class="assoc-title">Formulário associado</div>
          <div class="assoc-form"><span class="assoc-icon"><i class="pi pi-file" /></span><span>{{ stage.form?.title ?? 'Formulário da etapa' }}</span></div>
          <div class="tm-hint">{{ stage.form?.fieldCount ?? 0 }} campo(s) · chave técnica <code>{{ stage.form?.formKey }}</code><template v-if="isOpening"> · usado na abertura (Novo)</template></div>
          <button class="tm-link-btn" style="margin-top:6px" @click="emit('openForm')">Editar formulário em tela cheia →</button>
        </div>
      </template>

      <template v-else-if="stage.type === 'service_task'">
        <div class="tm-field">
          <label for="st-op">Integração executada</label>
          <select id="st-op" :value="config.operation ?? ''" :disabled="!editable" @change="setConfig('operation', ($event.target as HTMLSelectElement).value || null)">
            <option value="">Selecione...</option>
            <option v-for="op in session.meta?.serviceOperations" :key="op.key" :value="op.key">{{ op.label }}{{ op.active === false ? ' (inativa)' : '' }}</option>
          </select>
          <template v-if="selectedOperation">
            <div class="tm-hint">Lê do processo: {{ selectedOperation.inputs.join(', ') || '—' }}</div>
            <div class="tm-hint">Grava no processo: {{ selectedOperation.outputs.join(', ') || '—' }}</div>
            <div v-if="selectedOperation.active === false" class="tm-hint" style="color:var(--red)">Mapeamento inativo: a etapa vai falhar até ativá-lo em Integrações → Mapeamentos.</div>
          </template>
        </div>
      </template>

      <template v-else-if="stage.type === 'timer'">
        <div class="tm-field">
          <label>Aguardar</label>
          <select :value="config.mode ?? 'duration'" :disabled="!editable" @change="config = { mode: ($event.target as HTMLSelectElement).value, duration: 'P1D' }; saveConfig()">
            <option value="duration">Um prazo fixo</option>
            <option value="date">Até a data de um campo</option>
          </select>
        </div>
        <div v-if="(config.mode ?? 'duration') === 'duration'" class="tm-field">
          <label for="st-dur">Duração (ISO 8601)</label>
          <input id="st-dur" :value="config.duration ?? 'P1D'" :disabled="!editable" placeholder="P2D, PT4H, P1W" @change="setConfig('duration', ($event.target as HTMLInputElement).value)" />
          <div class="tm-hint">Ex.: P2D = 2 dias; PT4H = 4 horas; P30D = 30 dias.</div>
        </div>
        <div v-else class="tm-field">
          <label>Campo de data</label>
          <select :value="config.dateField ?? ''" :disabled="!editable" @change="setConfig('dateField', ($event.target as HTMLSelectElement).value)">
            <option value="">Selecione...</option>
            <option v-for="f in dateFields" :key="f.key" :value="f.key">{{ f.label }} ({{ f.key }})</option>
          </select>
        </div>
      </template>

      <template v-else-if="stage.type === 'end'">
        <div class="tm-field">
          <label>Resultado de negócio</label>
          <select :value="config.result ?? 'concluido'" :disabled="!editable" @change="setConfig('result', ($event.target as HTMLSelectElement).value)">
            <option v-for="r in session.meta?.endResults" :key="r.key" :value="r.key">{{ r.label }}</option>
          </select>
        </div>
        <div class="tm-field">
          <label>Notificar ao encerrar</label>
          <MultiSelect :model-value="config.notify ?? []" :options="groups" option-label="name" option-value="key" placeholder="Somente o solicitante" :disabled="!editable" display="chip" style="width:100%" @update:model-value="(v) => setConfig('notify', v)" />
        </div>
      </template>

      <RoutingEditor :stage="stage" :stages="stages" :fields="fields" :editable="editable" @save="(t) => emit('transitions', t)" />
    </div>
  </section>
</template>

<style scoped>
.settings { align-self: start; }
.who-list { list-style: decimal inside; margin: 0 0 6px; padding: 0; display: grid; gap: 4px; }
.who-list li { display: flex; align-items: center; gap: 4px; font-size: 12px; color: #43586a; }
.who-list li span { flex: 1; min-width: 0; }
.who-empty { color: var(--red); }
.who-check { display: flex; gap: 8px; align-items: center; margin-bottom: 12px; font-size: 12px; color: #43586a; }
.assoc { padding: 11px; border: 1px solid var(--brand-line); border-radius: 8px; background: var(--brand-softer); }
.assoc-title { color: var(--brand-ink); font-size: 10px; font-weight: 750; text-transform: uppercase; letter-spacing: 0.06em; }
.assoc-form { margin-top: 7px; display: flex; align-items: center; gap: 8px; color: var(--brand-ink); font-size: 12px; font-weight: 700; }
.assoc-icon { width: 26px; height: 26px; border-radius: 7px; background: var(--brand-soft); color: var(--brand-text); display: grid; place-items: center; font-size: 12px; }
code { font-size: 10px; background: #eef2f5; padding: 1px 4px; border-radius: 4px; }
</style>
