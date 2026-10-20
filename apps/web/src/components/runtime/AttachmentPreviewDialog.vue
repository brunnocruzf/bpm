<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import { attachmentBlob, attachmentPreviewType } from '@/api/attachments'
import { saveBlob } from '@/api/http'
import type { AttachmentInfo } from '@/api/types'
import { fmtSize } from '@/composables/format'
import { useNotify } from '@/composables/notify'

const attachment = defineModel<AttachmentInfo | null>('attachment', { required: true })
const notify = useNotify()

const previewUrl = ref<string | null>(null)
const file = ref<Blob | null>(null)
const loading = ref(false)
const previewType = computed(() => attachment.value ? attachmentPreviewType(attachment.value) : null)
const kind = computed(() => previewType.value === 'application/pdf' ? 'pdf' : previewType.value ? 'image' : 'none')

watch(attachment, async (a, _, onCleanup) => {
  previewUrl.value = null
  file.value = null
  loading.value = false
  if (!a) return
  const controller = new AbortController()
  let objectUrl: string | null = null
  onCleanup(() => {
    controller.abort()
    if (objectUrl) URL.revokeObjectURL(objectUrl)
  })
  loading.value = true
  try {
    const blob = await attachmentBlob(a.id, controller.signal)
    if (controller.signal.aborted) return
    file.value = blob
    if (!previewType.value || (kind.value === 'pdf' && navigator.pdfViewerEnabled === false)) {
      saveBlob(blob, a.name)
      notify.info('Arquivo sem visualizador disponível. Download iniciado.')
      attachment.value = null
      return
    }
    objectUrl = URL.createObjectURL(new Blob([blob], { type: previewType.value }))
    previewUrl.value = objectUrl
  } catch (e) {
    if (controller.signal.aborted) return
    notify.error(e)
    attachment.value = null
  } finally {
    if (!controller.signal.aborted) loading.value = false
  }
}, { immediate: true })

function download() {
  if (attachment.value && file.value) saveBlob(file.value, attachment.value.name)
}

function previewFailed() {
  notify.info('Não foi possível visualizar o arquivo. Download iniciado.')
  download()
  close()
}

function close() {
  attachment.value = null
}
</script>

<template>
  <Dialog
    :visible="!!attachment"
    modal
    :header="attachment?.name ?? 'Anexo'"
    :style="{ width: kind === 'none' ? 'min(480px, 96vw)' : 'min(960px, 96vw)' }"
    @update:visible="(v) => { if (!v) close() }"
  >
    <template v-if="attachment">
      <div v-if="loading" class="tm-empty">Carregando...</div>
      <template v-else>
        <img v-if="kind === 'image' && previewUrl" :src="previewUrl" :alt="attachment.name" style="max-width:100%;max-height:70vh;display:block;margin:0 auto" @error="previewFailed" />
        <iframe v-else-if="kind === 'pdf' && previewUrl" :src="previewUrl" title="Pré-visualização do PDF" style="width:100%;height:75vh;border:0" @error="previewFailed" />
        <div v-else class="tm-empty">
          <p>Não é possível pré-visualizar este tipo de arquivo ({{ attachment.type }}, {{ fmtSize(attachment.size) }}).</p>
        </div>
      </template>
      <div class="tm-dialog-actions" style="margin-top:12px;display:flex;justify-content:flex-end;gap:8px">
        <button type="button" class="tm-btn" :disabled="loading || !file" @click="download"><i class="pi pi-download" /> Baixar</button>
      </div>
    </template>
  </Dialog>
</template>
