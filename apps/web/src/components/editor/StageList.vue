<script setup lang="ts">
import { computed, ref } from 'vue'
import type { Stage, StageType, ValidationProblem } from '@/api/types'
import { STAGE_TYPE_LABELS } from '@/composables/format'
import { useSession } from '@/stores/session'

const props = defineProps<{ stages: Stage[]; selectedKey: string | null; problems: ValidationProblem[]; editable: boolean }>()
const emit = defineEmits<{ select: [key: string]; add: [type: StageType]; reorder: [keys: string[]] }>()
const session = useSession()

const ICONS: Record<string, string> = {
  start: 'pi-play-circle', user_task: 'pi-user', exclusive_gateway: 'pi-question-circle', parallel_gateway: 'pi-clone',
  service_task: 'pi-cog', timer: 'pi-clock', end: 'pi-stop-circle',
}

const dragKey = ref<string | null>(null)
const overKey = ref<string | null>(null)

function problemsFor(key: string) {
  return props.problems.filter((p) => p.stageKey === key && p.severity === 'error').length
}

function onDrop(target: Stage) {
  if (!dragKey.value || dragKey.value === target.key) return
  const keys = props.stages.map((s) => s.key).filter((k) => k !== dragKey.value)
  keys.splice(keys.indexOf(target.key), 0, dragKey.value)
  emit('reorder', keys)
  dragKey.value = null
  overKey.value = null
}

const numbers = computed(() => {
  let n = 0
  return Object.fromEntries(props.stages.map((s) => [s.key, s.type === 'start' ? '▶' : s.type === 'end' ? '■' : String(++n)]))
})

const ADD: { type: StageType; label: string; icon: string }[] = [
  { type: 'user_task', label: 'Adicionar etapa', icon: 'pi-plus' },
  { type: 'exclusive_gateway', label: 'Adicionar decisão', icon: 'pi-question' },
  { type: 'parallel_gateway', label: 'Adicionar caminhos paralelos', icon: 'pi-clone' },
  { type: 'service_task', label: 'Adicionar integração', icon: 'pi-cog' },
  { type: 'timer', label: 'Adicionar timer', icon: 'pi-clock' },
  { type: 'end', label: 'Adicionar fim', icon: 'pi-stop' },
]
</script>

<template>
  <section class="tm-panel stage-panel">
    <div class="tm-panel-head">
      <div><div class="tm-panel-title">Etapas do processo</div><div class="tm-panel-caption">Selecione para configurar{{ editable ? ' · arraste para reordenar' : '' }}</div></div>
      <span class="tm-pill neutral">{{ stages.length }}</span>
    </div>
    <div class="stage-list">
      <button
        v-for="s in stages"
        :key="s.key"
        class="step-card"
        :class="{ selected: s.key === selectedKey, over: overKey === s.key }"
        :draggable="editable && s.type !== 'start'"
        @click="emit('select', s.key)"
        @dragstart="dragKey = s.key"
        @dragover.prevent="overKey = s.key"
        @dragleave="overKey = null"
        @drop.prevent="onDrop(s)"
      >
        <span class="step-num" :class="s.type">{{ numbers[s.key] }}</span>
        <span class="step-meta">
          <span class="step-name">{{ s.name }}</span>
          <span class="step-kind"><i class="pi" :class="ICONS[s.type]" /> {{ STAGE_TYPE_LABELS[s.type] }}<template v-if="s.lane"> · {{ session.groupName(s.lane) }}</template></span>
          <span v-if="s.type === 'user_task'" class="step-form" :class="{ missing: !s.form || s.form.fieldCount === 0 }">
            <i class="pi" :class="s.form && s.form.fieldCount ? 'pi-file' : 'pi-exclamation-circle'" />
            {{ s.form ? (s.form.fieldCount ? `${s.form.title} · ${s.form.fieldCount} campo(s)` : 'Configurar formulário') : 'Sem formulário' }}
          </span>
        </span>
        <span v-if="problemsFor(s.key)" class="step-err" v-tooltip="'Há problemas de validação nesta etapa'">{{ problemsFor(s.key) }}</span>
      </button>
    </div>
    <div v-if="editable" class="add-zone">
      <button v-for="a in ADD" :key="a.type" class="add-btn" @click="emit('add', a.type)"><i class="pi" :class="a.icon" /> {{ a.label }}</button>
    </div>
  </section>
</template>

<style scoped>
.stage-panel { padding-bottom: 10px; align-self: start; }
.stage-list { padding: 10px 8px 6px; max-height: 62vh; overflow: auto; }
.step-card {
  position: relative; width: 100%; display: flex; align-items: flex-start; gap: 10px; text-align: left; padding: 10px 9px;
  border: 1px solid transparent; border-radius: 8px; color: #47586a; background: transparent; cursor: pointer; font-family: inherit;
}
.step-card:hover { background: #f7fafb; }
.step-card.selected { border-color: #b6dadd; background: #eef8f8; }
.step-card.over { border-top: 2px solid var(--brand); }
.step-num { width: 24px; height: 24px; display: grid; place-items: center; border-radius: 7px; color: #627385; background: #edf1f4; font-size: 10px; font-weight: 750; flex: 0 0 auto; }
.step-num.exclusive_gateway, .step-num.parallel_gateway { transform: rotate(45deg) scale(0.85); }
.step-card.selected .step-num { color: var(--on-brand); background: var(--brand); }
.step-meta { min-width: 0; flex: 1; display: flex; flex-direction: column; }
.step-name { color: #344659; font-size: 12px; font-weight: 700; line-height: 1.35; }
.step-kind { color: #8794a0; font-size: 10px; margin-top: 3px; }
.step-kind .pi { font-size: 10px; }
.step-form { display: flex; align-items: center; gap: 4px; margin-top: 6px; color: var(--brand-text); font-size: 10px; font-weight: 650; }
.step-form .pi { font-size: 10px; }
.step-form.missing { color: #a77831; }
.step-err { min-width: 18px; height: 18px; border-radius: 9px; background: var(--red-soft); color: var(--red); font-size: 10px; font-weight: 800; display: grid; place-items: center; }
.add-zone { padding: 4px 8px 0; display: flex; flex-direction: column; gap: 6px; }
.add-btn {
  width: 100%; display: flex; align-items: center; gap: 8px; height: 32px; border: 1px dashed #cbd5dd; border-radius: 7px; color: #607282;
  background: #fff; padding: 0 10px; font-size: 11px; font-weight: 650; cursor: pointer; font-family: inherit;
}
.add-btn:hover { border-color: var(--brand-line); color: var(--brand-text); background: var(--brand-softer); }
</style>
