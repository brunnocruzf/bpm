<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import { api } from '@/api/http'
import type { FormSchema } from '@/api/types'
import { fmtDateTime } from '@/composables/format'
import { useNotify } from '@/composables/notify'
import FormRenderer from '@/components/forms/FormRenderer.vue'

interface SubmissionDetail {
  id: string
  instance: { id: string; number: string }
  stage: { key: string; name: string }
  form: { title: string; schema: FormSchema; hash: string | null }
  payload: Record<string, any>
  metadata: { lookups?: Record<string, { source: string; value: string; transport: string; at: string }> }
  authorName: string
  revision: number
  createdAt: string
}

const id = defineModel<string | null>('id', { required: true })
const notify = useNotify()
const detail = ref<SubmissionDetail | null>(null)

watch(id, async (v) => {
  detail.value = null
  if (!v) return
  try {
    detail.value = await api<SubmissionDetail>(`/submissions/${v}`)
  } catch (e) {
    notify.error(e)
    id.value = null
  }
})
</script>

<template>
  <Dialog :visible="!!id" modal :header="detail ? `${detail.stage.name} — ${detail.instance.number}` : 'Resposta'" :style="{ width: 'min(820px, 96vw)' }" @update:visible="(v) => { if (!v) id = null }">
    <template v-if="detail">
      <div class="tm-muted" style="font-size:12px;margin-bottom:12px">
        Enviado por <b>{{ detail.authorName }}</b> em {{ fmtDateTime(detail.createdAt) }} · revisão {{ detail.revision }} · schema da época <code>{{ detail.form.hash?.slice(0, 10) }}</code>
      </div>
      <FormRenderer :schema="detail.form.schema" :data="detail.payload" read-only />
      <div v-if="detail.metadata.lookups && Object.keys(detail.metadata.lookups).length" class="tm-note">
        <i class="pi pi-database" />
        <span>
          <strong>Consultas ao AX registradas:</strong>
          <template v-for="(l, k) in detail.metadata.lookups" :key="k"> {{ k }} = "{{ l.value }}" ({{ l.transport }}, {{ fmtDateTime(l.at) }});</template>
        </span>
      </div>
    </template>
    <div v-else class="tm-empty">Carregando...</div>
  </Dialog>
</template>
