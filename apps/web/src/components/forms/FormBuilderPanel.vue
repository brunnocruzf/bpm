<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { FormSchema } from '@/api/types'
import { useSession } from '@/stores/session'
import { Formio, builderOptions, setStrictEvaluation, setupFormio } from './formio'

const props = defineProps<{ schema: FormSchema; formKey: string; readOnly?: boolean }>()
const emit = defineEmits<{ change: [schema: FormSchema] }>()

const session = useSession()
const el = ref<HTMLElement>()
let builder: any = null
let renderer: any = null

const clone = <T>(v: T): T => JSON.parse(JSON.stringify(v ?? null))

async function create() {
  if (!el.value) return
  setupFormio(session.meta)
  builder?.destroy?.()
  renderer?.destroy?.(true)
  builder = null
  renderer = null
  el.value.innerHTML = ''
  if (props.readOnly) {
    // Versão publicada: imutável, apenas visualização.
    setStrictEvaluation(true)
    renderer = await (Formio as any).createForm(el.value, clone(props.schema), { readOnly: true, language: 'pt', i18n: builderOptions().i18n, noAlerts: true, buttonSettings: { showSubmit: false } })
    return
  }
  setStrictEvaluation(false)
  builder = await (Formio as any).builder(el.value, clone(props.schema), builderOptions())
  const notify = () => emit('change', { display: 'form', components: clone(builder.schema?.components ?? []) })
  builder.on('change', notify)
  builder.on('saveComponent', notify)
  builder.on('removeComponent', notify)
  builder.on('updateComponent', notify)
}

onMounted(create)
watch(() => [props.formKey, props.readOnly], create)
onBeforeUnmount(() => {
  builder?.destroy?.()
  renderer?.destroy?.(true)
})
</script>

<template>
  <div ref="el" class="tm-builder" />
</template>

<style scoped>
.tm-builder { min-height: 320px; }
</style>
