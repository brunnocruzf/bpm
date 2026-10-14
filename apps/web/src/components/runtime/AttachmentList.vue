<script setup lang="ts">
import { api } from '@/api/http'
import type { AttachmentInfo } from '@/api/types'
import { fmtSize } from '@/composables/format'
import { useNotify } from '@/composables/notify'

defineProps<{ items: AttachmentInfo[] }>()
const notify = useNotify()

/** Download via URL assinada de curta duração gerada após autorização. */
async function download(a: AttachmentInfo) {
  try {
    const res = await api<{ url: string }>(`/attachments/${a.id}/url`)
    window.open(res.url, '_blank', 'noopener')
  } catch (e) {
    notify.error(e)
  }
}
</script>

<template>
  <ul class="tm-file-list">
    <li v-for="a in items" :key="a.id">
      <i class="pi pi-paperclip tm-muted" />
      <button class="tm-link-btn" @click="download(a)">{{ a.name }}</button>
      <span class="tm-muted">{{ fmtSize(a.size) }}</span>
      <span v-if="a.avStatus === 'not_scanned'" class="tm-pill neutral" v-tooltip="'Antivírus não configurado neste ambiente'">sem varredura</span>
      <span v-else-if="a.avStatus === 'clean'" class="tm-pill green">verificado</span>
    </li>
    <li v-if="!items.length" class="tm-muted">Nenhum anexo.</li>
  </ul>
</template>
