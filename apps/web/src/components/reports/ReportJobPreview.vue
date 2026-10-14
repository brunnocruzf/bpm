<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { api, apiBlob, saveBlob } from '@/api/http'
import type { ReportJobView } from '@/api/types'
import { fmtDateTime, fmtSize } from '@/composables/format'
import { useNotify } from '@/composables/notify'

/**
 * Acompanha um job de relatório (fila) e, quando pronto, mostra o PDF renderizado. O arquivo é
 * baixado pela API (autorização revalidada + auditoria) e exibido via blob local — sem URL pública.
 */
const props = defineProps<{ jobId: string }>()
const emit = defineEmits<{ ready: [job: ReportJobView] }>()
const notify = useNotify()
const job = ref<ReportJobView | null>(null)
const pdfUrl = ref<string | null>(null)
const frame = ref<HTMLIFrameElement | null>(null)
let timer: number | undefined

const STATUS: Record<string, string> = { queued: 'Na fila', rendering: 'Gerando', ready: 'Pronto', failed: 'Falhou', expired: 'Expirado' }

async function refresh() {
  window.clearTimeout(timer)
  job.value = await api<ReportJobView>(`/reports/${props.jobId}`)
  if (job.value.status === 'queued' || job.value.status === 'rendering') {
    timer = window.setTimeout(refresh, 1500)
  } else if (job.value.status === 'ready') {
    emit('ready', job.value)
    if (job.value.format === 'pdf') await loadPdf()
  }
}

async function loadPdf() {
  try {
    const { blob } = await apiBlob(`/reports/${props.jobId}/download`, { purpose: 'preview' })
    if (pdfUrl.value) URL.revokeObjectURL(pdfUrl.value)
    pdfUrl.value = URL.createObjectURL(blob)
  } catch (e) {
    notify.error(e)
  }
}

async function download() {
  try {
    const { blob, fileName } = await apiBlob(`/reports/${props.jobId}/download`, { purpose: 'download' })
    saveBlob(blob, fileName ?? job.value?.fileName ?? 'relatorio')
  } catch (e) {
    notify.error(e)
  }
}

async function print() {
  try {
    // registra a impressão (auditoria) e imprime a mesma versão exibida
    const { blob } = await apiBlob(`/reports/${props.jobId}/download`, { purpose: 'print' })
    const url = URL.createObjectURL(blob)
    const w = frame.value?.contentWindow
    if (frame.value && w) {
      frame.value.src = url
      frame.value.onload = () => frame.value?.contentWindow?.print()
    } else {
      window.open(url, '_blank', 'noopener')
    }
  } catch (e) {
    notify.error(e)
  }
}

watch(() => props.jobId, () => {
  pdfUrl.value = null
  refresh()
}, { immediate: true })
onBeforeUnmount(() => {
  window.clearTimeout(timer)
  if (pdfUrl.value) URL.revokeObjectURL(pdfUrl.value)
})
</script>

<template>
  <div v-if="job" class="job">
    <div class="job-head">
      <div>
        <span class="tm-pill" :class="job.status === 'ready' ? 'green' : job.status === 'failed' ? 'red' : job.status === 'expired' ? 'neutral' : 'amber'">
          <span v-if="job.status === 'queued' || job.status === 'rendering'" class="tm-spinner" aria-hidden="true" /> {{ STATUS[job.status] }}
        </span>
        <span class="tm-muted" style="font-size:12px;margin-left:8px">{{ job.format.toUpperCase() }}<template v-if="job.size"> · {{ fmtSize(job.size) }}</template><template v-if="job.rows != null"> · {{ job.rows }} linha(s)</template><template v-if="job.expiresAt"> · expira {{ fmtDateTime(job.expiresAt) }}</template></span>
      </div>
      <div v-if="job.status === 'ready'" class="tm-actions">
        <button v-if="job.format === 'pdf'" class="tm-btn small" @click="print"><i class="pi pi-print" aria-hidden="true" /> Imprimir</button>
        <button class="tm-btn small primary" @click="download"><i class="pi pi-download" aria-hidden="true" /> Baixar</button>
      </div>
    </div>
    <div v-if="job.status === 'failed'" class="tm-note err" role="alert"><i class="pi pi-times-circle" aria-hidden="true" /><span>{{ job.error }} <code>{{ job.errorCode }}</code></span></div>
    <iframe v-if="pdfUrl" ref="frame" :src="pdfUrl" title="Pré-visualização do relatório em PDF" class="pdf" />
    <div v-else-if="job.status === 'ready' && job.format !== 'pdf'" class="tm-note"><i class="pi pi-file" aria-hidden="true" /><span>Arquivo tabular pronto para download.</span></div>
  </div>
</template>

<style scoped>
.job { display: flex; flex-direction: column; gap: 10px; }
.job-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
.pdf { width: 100%; height: 68vh; border: 1px solid var(--line); border-radius: 8px; background: #fff; }
code { font-size: 10px; background: rgba(0, 0, 0, 0.05); padding: 1px 4px; border-radius: 4px; }
</style>
