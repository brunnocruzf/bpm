<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import { api, newId } from '@/api/http'
import type { ReportJobView } from '@/api/types'
import { useNotify } from '@/composables/notify'
import ReportJobPreview from './ReportJobPreview.vue'

/** Relatório/impressão de uma solicitação: resumo, etapas selecionadas ou completo (snapshots). */
const props = defineProps<{ visible: boolean; instanceId: string; number: string; stages: { key: string; name: string; done: boolean }[]; initialStage?: string | null }>()
const emit = defineEmits<{ 'update:visible': [v: boolean] }>()
const notify = useNotify()
const mode = ref<'summary' | 'stages' | 'full'>('full')
const selected = ref<string[]>([])
const jobId = ref<string | null>(null)
const busy = ref(false)

watch(() => props.visible, (v) => {
  if (v) {
    jobId.value = null
    mode.value = props.initialStage ? 'stages' : 'full'
    selected.value = props.initialStage ? [props.initialStage] : props.stages.filter((s) => s.done).map((s) => s.key)
  }
})
const canGenerate = computed(() => mode.value !== 'stages' || selected.value.length > 0)

async function generate() {
  busy.value = true
  try {
    const job = await api<ReportJobView>(`/process-instances/${props.instanceId}/reports`, { method: 'POST', body: { mode: mode.value, stages: selected.value }, idempotencyKey: newId() })
    jobId.value = job.id
  } catch (e) {
    notify.error(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Dialog :visible="visible" modal :header="`Relatório — ${number}`" :style="{ width: 'min(1100px, 96vw)' }" @update:visible="(v) => emit('update:visible', v)">
    <div v-if="!jobId" class="opts">
      <fieldset class="modes">
        <legend class="tm-strong" style="font-size:12px">Conteúdo</legend>
        <label><input v-model="mode" type="radio" value="summary" /> Resumo e linha do tempo completa</label>
        <label><input v-model="mode" type="radio" value="stages" /> Etapas selecionadas (com os formulários)</label>
        <label><input v-model="mode" type="radio" value="full" /> Relatório completo (todas as etapas)</label>
      </fieldset>
      <fieldset v-if="mode === 'stages'" class="stages">
        <legend class="tm-strong" style="font-size:12px">Etapas</legend>
        <label v-for="s in stages" :key="s.key"><input v-model="selected" type="checkbox" :value="s.key" /> {{ s.name }} <span v-if="!s.done" class="tm-muted">(sem submissão)</span></label>
      </fieldset>
      <p class="tm-hint">Cada formulário aparece como foi submetido (versão do processo e do formulário da época). Campos sensíveis ficam mascarados conforme sua permissão. Geração assíncrona; visualização, impressão e download são registrados na auditoria.</p>
      <div class="tm-actions"><button class="tm-btn primary" :disabled="busy || !canGenerate" @click="generate"><i class="pi pi-file-pdf" aria-hidden="true" /> Gerar PDF</button></div>
    </div>
    <template v-else>
      <ReportJobPreview :job-id="jobId" />
      <div class="tm-actions" style="margin-top:10px"><button class="tm-btn ghost small" @click="jobId = null">Gerar outro</button></div>
    </template>
  </Dialog>
</template>

<style scoped>
.opts { display: flex; flex-direction: column; gap: 12px; }
fieldset { border: 1px solid var(--line); border-radius: 9px; padding: 10px 12px; display: flex; flex-direction: column; gap: 7px; font-size: 12px; margin: 0; }
.stages { max-height: 260px; overflow: auto; }
</style>
