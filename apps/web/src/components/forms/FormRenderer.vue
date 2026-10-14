<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { FormSchema } from '@/api/types'
import { useSession } from '@/stores/session'
import { Formio, PT_BR, fileService, setStrictEvaluation, setUploadContext, setupFormio } from './formio'

const props = withDefaults(defineProps<{
  schema: FormSchema
  data?: Record<string, any>
  readOnly?: boolean
  taskId?: string
  /** pré-visualização do designer (permite avaliações internas do Form.io) */
  preview?: boolean
}>(), { data: () => ({}), readOnly: false, preview: false })

const emit = defineEmits<{ change: [data: Record<string, any>] }>()

const session = useSession()
const el = ref<HTMLElement>()
let form: any = null
let creating: Promise<void> | null = null

const clone = <T>(v: T): T => JSON.parse(JSON.stringify(v ?? null))

async function create() {
  if (!el.value) return
  setupFormio(session.meta)
  setStrictEvaluation(!props.preview)
  setUploadContext({ taskId: props.taskId })
  form?.destroy?.(true)
  el.value.innerHTML = ''
  form = await (Formio as any).createForm(el.value, clone(props.schema), {
    readOnly: props.readOnly,
    language: 'pt',
    i18n: PT_BR,
    noAlerts: true,
    fileService: fileService(),
    buttonSettings: { showSubmit: false, showCancel: false },
  })
  form.submission = { data: clone(props.data) }
  form.on('change', () => emit('change', clone(form.submission?.data ?? {})))
}

function recreate() {
  creating = create()
}

onMounted(recreate)
watch(() => [props.schema, props.readOnly], recreate)
watch(() => props.data, (d) => {
  if (form) form.submission = { data: clone(d) }
})
onBeforeUnmount(() => {
  form?.destroy?.(true)
  form = null
})

/** Valida no cliente (UX); a validação de negócio definitiva é do servidor. */
async function validate(): Promise<{ valid: boolean; data: Record<string, any> }> {
  await creating
  if (!form) return { valid: false, data: {} }
  try {
    await form.submit()
    return { valid: true, data: clone(form.submission.data) }
  } catch {
    return { valid: false, data: clone(form.submission?.data ?? {}) }
  }
}

function getData(): Record<string, any> {
  return clone(form?.submission?.data ?? {})
}

defineExpose({ validate, getData })
</script>

<template>
  <div ref="el" class="tm-formio" />
</template>
