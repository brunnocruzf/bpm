<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { Condition, ConditionRule, FieldInfo, Stage } from '@/api/types'
import { STAGE_TYPE_LABELS } from '@/composables/format'
import { useSession } from '@/stores/session'

interface Row { target: string; isDefault: boolean; label: string; condition: Condition }

const props = defineProps<{ stage: Stage; stages: Stage[]; fields: FieldInfo[]; editable: boolean }>()
const emit = defineEmits<{ save: [transitions: { target: string; condition: Condition | null; isDefault: boolean; label: string | null }[]] }>()
const session = useSession()

const rows = ref<Row[]>([])
const dirty = ref(false)

const targets = computed(() => props.stages.filter((s) => s.key !== props.stage.key && s.type !== 'start'))
const conditionFields = computed(() => props.fields.filter((f) => !['datagrid', 'file', 'selectboxes'].includes(f.type)))
const UNARY = ['empty', 'not_empty']

function reset() {
  rows.value = props.stage.outgoing.map((t) => ({
    target: t.target,
    isDefault: t.isDefault,
    label: t.label ?? '',
    condition: t.condition ? JSON.parse(JSON.stringify(t.condition)) : { match: 'all', rules: [] },
  }))
  dirty.value = false
}
watch(() => [props.stage.key, JSON.stringify(props.stage.outgoing)], reset, { immediate: true })

function field(key: string) {
  return props.fields.find((f) => f.key === key)
}

function addPath() {
  const used = new Set(rows.value.map((r) => r.target))
  const next = targets.value.find((t) => !used.has(t.key))
  rows.value.push({ target: next?.key ?? '', isDefault: false, label: '', condition: { match: 'all', rules: [{ field: conditionFields.value[0]?.key ?? '', operator: 'eq', value: '' }] } })
  dirty.value = true
}

function addRule(row: Row) {
  row.condition.rules.push({ field: conditionFields.value[0]?.key ?? '', operator: 'eq', value: '' })
  dirty.value = true
}

function onFieldChange(rule: ConditionRule) {
  const f = field(rule.field)
  rule.value = f?.type === 'checkbox' ? true : f?.values[0]?.value ?? ''
  dirty.value = true
}

function setDefault(row: Row) {
  rows.value.forEach((r) => { if (r !== row) r.isDefault = false })
  dirty.value = true
}

function save() {
  emit('save', rows.value.filter((r) => r.target).map((r) => ({
    target: r.target,
    isDefault: props.stage.type === 'exclusive_gateway' ? r.isDefault : false,
    label: r.label.trim() || null,
    condition: props.stage.type === 'exclusive_gateway' && !r.isDefault
      ? { match: r.condition.match, rules: r.condition.rules.filter((x) => x.field).map((x) => ({ ...x, value: UNARY.includes(x.operator) ? null : normalizeValue(x) })) }
      : null,
  })))
}

function normalizeValue(rule: ConditionRule) {
  const f = field(rule.field)
  if (['gt', 'gte', 'lt', 'lte'].includes(rule.operator) || ['number', 'currency'].includes(f?.type ?? '')) {
    const n = Number(rule.value)
    return Number.isNaN(n) ? rule.value : n
  }
  if (f?.type === 'checkbox') return rule.value === true || rule.value === 'true'
  return rule.value
}

function saveSingle(target: string) {
  emit('save', target ? [{ target, condition: null, isDefault: false, label: null }] : [])
}

const targetLabel = (s: Stage) => `${s.name} (${STAGE_TYPE_LABELS[s.type]}${s.lane ? ' · ' + session.groupName(s.lane) : ''})`
</script>

<template>
  <div class="routing">
    <div class="routing-title"><i class="pi pi-directions" /> O que ocorre depois?</div>

    <div v-if="stage.type === 'end'" class="tm-hint">Este é um fim do processo; não há próximo passo.</div>

    <!-- Etapas lineares: um único próximo passo -->
    <div v-else-if="stage.type !== 'exclusive_gateway' && stage.type !== 'parallel_gateway'" class="tm-field">
      <label>Próximo passo</label>
      <select :value="stage.outgoing[0]?.target ?? ''" :disabled="!editable" @change="saveSingle(($event.target as HTMLSelectElement).value)">
        <option value="">— selecione —</option>
        <option v-for="t in targets" :key="t.key" :value="t.key">{{ targetLabel(t) }}</option>
      </select>
      <div class="tm-hint">Para ramificar o caminho, aponte para uma <b>decisão</b>.</div>
    </div>

    <!-- Decisão exclusiva -->
    <template v-else-if="stage.type === 'exclusive_gateway'">
      <div v-for="(row, i) in rows" :key="i" class="path">
        <div class="path-head">
          <strong>Caminho {{ i + 1 }}</strong>
          <button v-if="editable" class="tm-link-btn" style="color:var(--red)" @click="rows.splice(i, 1); dirty = true">Remover</button>
        </div>
        <div class="tm-field">
          <label>Vai para</label>
          <select v-model="row.target" :disabled="!editable" @change="dirty = true">
            <option value="">— selecione —</option>
            <option v-for="t in targets" :key="t.key" :value="t.key">{{ targetLabel(t) }}</option>
          </select>
        </div>
        <label class="check"><input v-model="row.isDefault" type="checkbox" :disabled="!editable" @change="setDefault(row)" /> Caso contrário (caminho padrão)</label>
        <div v-if="!row.isDefault" class="rules">
          <div class="rules-head">
            Se
            <select v-model="row.condition.match" :disabled="!editable" class="mini" @change="dirty = true">
              <option value="all">todas</option>
              <option value="any">qualquer uma</option>
            </select>
            das regras:
          </div>
          <div v-for="(rule, j) in row.condition.rules" :key="j" class="rule">
            <select v-model="rule.field" :disabled="!editable" @change="onFieldChange(rule)">
              <option v-for="f in conditionFields" :key="f.key" :value="f.key">{{ f.label }}</option>
            </select>
            <select v-model="rule.operator" :disabled="!editable" @change="dirty = true">
              <option v-for="op in session.meta?.conditionOperators" :key="op.key" :value="op.key">{{ op.label }}</option>
            </select>
            <template v-if="!UNARY.includes(rule.operator)">
              <select v-if="field(rule.field)?.values.length" v-model="rule.value" :disabled="!editable" @change="dirty = true">
                <option v-for="v in field(rule.field)?.values" :key="v.value" :value="v.value">{{ v.label }}</option>
              </select>
              <select v-else-if="field(rule.field)?.type === 'checkbox'" v-model="rule.value" :disabled="!editable" @change="dirty = true">
                <option :value="true">Marcado</option>
                <option :value="false">Desmarcado</option>
              </select>
              <input v-else v-model="rule.value" :disabled="!editable" placeholder="valor" @input="dirty = true" />
            </template>
            <button v-if="editable" class="tm-btn small icon ghost" aria-label="Remover regra" @click="row.condition.rules.splice(j, 1); dirty = true"><i class="pi pi-times" /></button>
          </div>
          <button v-if="editable" class="tm-link-btn" @click="addRule(row)"><i class="pi pi-plus" /> regra</button>
          <div v-if="!conditionFields.length" class="tm-hint">Nenhum campo disponível: adicione campos aos formulários das etapas anteriores.</div>
        </div>
        <div v-if="!dirty && stage.outgoing[i]" class="tm-hint">{{ stage.outgoing[i].conditionText }}</div>
      </div>
      <div v-if="editable" class="tm-actions">
        <button class="tm-btn small" @click="addPath"><i class="pi pi-plus" /> Adicionar caminho</button>
        <button class="tm-btn small primary" :disabled="!dirty" @click="save">Salvar caminhos</button>
      </div>
    </template>

    <!-- Paralelo -->
    <template v-else>
      <div class="tm-hint" style="margin-bottom:8px">Todos os caminhos abaixo executam ao mesmo tempo. Para sincronizar, use outro gateway paralelo com várias entradas.</div>
      <div v-for="(row, i) in rows" :key="i" class="rule">
        <select v-model="row.target" :disabled="!editable" @change="dirty = true">
          <option value="">— selecione —</option>
          <option v-for="t in targets" :key="t.key" :value="t.key">{{ targetLabel(t) }}</option>
        </select>
        <button v-if="editable" class="tm-btn small icon ghost" aria-label="Remover caminho" @click="rows.splice(i, 1); dirty = true"><i class="pi pi-times" /></button>
      </div>
      <div v-if="editable" class="tm-actions" style="margin-top:6px">
        <button class="tm-btn small" @click="rows.push({ target: '', isDefault: false, label: '', condition: { match: 'all', rules: [] } }); dirty = true"><i class="pi pi-plus" /> Adicionar caminho</button>
        <button class="tm-btn small primary" :disabled="!dirty" @click="save">Salvar caminhos</button>
      </div>
    </template>
  </div>
</template>

<style scoped>
.routing { margin-top: 16px; padding-top: 14px; border-top: 1px solid #edf1f4; }
.routing-title { font-size: 12px; font-weight: 750; color: #2c3e50; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
.path { border: 1px solid #e4eaf0; border-radius: 8px; padding: 10px; margin-bottom: 10px; background: #fbfcfd; }
.path-head { display: flex; justify-content: space-between; align-items: center; font-size: 11px; margin-bottom: 8px; color: #43566a; }
.check { display: flex; align-items: center; gap: 6px; font-size: 11px; color: #526477; margin-bottom: 8px; }
.rules { border-left: 2px solid #cfe6e4; padding-left: 8px; }
.rules-head { font-size: 11px; color: #667789; margin-bottom: 6px; display: flex; gap: 6px; align-items: center; }
.rule { display: flex; gap: 6px; margin-bottom: 6px; align-items: center; flex-wrap: wrap; }
.rule select, .rule input, select.mini { height: 30px; border: 1px solid var(--line-strong); border-radius: 6px; padding: 0 6px; font-size: 11px; font-family: inherit; background: #fff; max-width: 100%; }
.rule select { flex: 1 1 120px; min-width: 0; }
.rule input { flex: 1 1 90px; min-width: 0; }
</style>
