<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError, newId } from '@/api/http'
import type { FormSchema, InstanceSummary } from '@/api/types'
import { useNotify } from '@/composables/notify'
import FormRenderer from '@/components/forms/FormRenderer.vue'

interface StartForm {
  flow: { key: string; name: string; description: string | null; category: string }
  version: number
  deployed: boolean
  canStart: boolean
  stage: { key: string; name: string; description: string | null } | null
  form: { id: string; title: string; schema: FormSchema } | null
  data: Record<string, any>
}

const props = defineProps<{ flowKey: string }>()
const router = useRouter()
const notify = useNotify()
const info = ref<StartForm | null>(null)
const renderer = ref<InstanceType<typeof FormRenderer>>()
const serverErrors = ref<Record<string, string[]>>({})
const sending = ref(false)
// Mesma chave em tentativas repetidas (ex.: queda de rede) evita solicitação duplicada.
let idempotencyKey = newId()

onMounted(async () => {
  try {
    info.value = await api<StartForm>(`/flows/${props.flowKey}/start-form`)
  } catch (e) {
    notify.error(e)
  }
})

async function submit() {
  serverErrors.value = {}
  const result = await renderer.value?.validate()
  if (info.value?.form && !result?.valid) {
    notify.info('Verifique os campos destacados no formulário.', 'Formulário incompleto')
    return
  }
  sending.value = true
  try {
    const res = await api<{ instance: InstanceSummary }>('/process-instances', {
      method: 'POST',
      idempotencyKey,
      body: { flowKey: props.flowKey, data: result?.data ?? {} },
    })
    idempotencyKey = newId()
    notify.success(`Solicitação ${res.instance.number} recebida. Processando no engine...`)
    router.push({ name: 'instance', params: { id: res.instance.id } })
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      serverErrors.value = e.errors
      idempotencyKey = newId()
    } else if (e instanceof ApiError && e.status !== 0) {
      idempotencyKey = newId()
    }
    notify.error(e)
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <div class="tm-crumbs"><RouterLink to="/">Catálogo de fluxos</RouterLink><span class="sep">›</span><span>{{ info?.flow.name }}</span><span class="sep">›</span><span>Nova solicitação</span></div>
  <template v-if="info">
    <div class="tm-heading">
      <div>
        <h1>{{ info.flow.name }}</h1>
        <p class="tm-subtitle">{{ info.stage?.description || info.flow.description }}</p>
      </div>
      <span class="tm-pill neutral">v{{ info.version }}</span>
    </div>
    <div v-if="!info.deployed" class="tm-note warn"><i class="pi pi-clock" /><span>A versão publicada ainda está sendo implantada no engine. Tente novamente em instantes.</span></div>
    <div v-else-if="!info.canStart" class="tm-note err"><i class="pi pi-lock" /><span>Você não tem permissão para abrir solicitações deste fluxo.</span></div>
    <div class="tm-grid-2" style="margin-top:14px">
      <section class="tm-panel">
        <div class="tm-panel-head"><div><div class="tm-panel-title">{{ info.stage?.name ?? 'Abertura' }}</div><div class="tm-panel-caption">{{ info.form?.title }}</div></div></div>
        <div class="tm-panel-body">
          <div v-if="Object.keys(serverErrors).length" class="tm-note err" style="margin-top:0">
            <i class="pi pi-times-circle" />
            <span><strong>O servidor recusou alguns dados:</strong><br /><template v-for="(msgs, k) in serverErrors" :key="k">• {{ msgs.join(' ') }}<br /></template></span>
          </div>
          <FormRenderer v-if="info.form" ref="renderer" :schema="info.form.schema" :data="info.data" />
          <div v-else class="tm-muted">Este fluxo não pede dados na abertura.</div>
        </div>
        <div class="tm-actions" style="justify-content:flex-end;padding:12px 14px;border-top:1px solid #edf1f4">
          <RouterLink class="tm-btn" to="/">Cancelar</RouterLink>
          <button class="tm-btn primary" :disabled="sending || !info.deployed || !info.canStart" @click="submit"><i class="pi pi-send" /> Abrir solicitação</button>
        </div>
      </section>
      <aside class="tm-panel tm-panel-body" style="font-size:12px;color:#526677;line-height:1.6">
        <strong style="color:#2c3e50">Como funciona</strong>
        <p>Ao enviar, o sistema gera o número da solicitação (sequência anual), registra seus dados como solicitante e inicia o processo no engine.</p>
        <p>Campos marcados como "AX" são consultados no sistema corporativo; use <b>Consultar</b> para conferir. O servidor repete a consulta ao salvar e registra origem e horário.</p>
      </aside>
    </div>
  </template>
  <div v-else class="tm-panel tm-empty">Carregando formulário...</div>
</template>
