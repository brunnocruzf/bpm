<script setup lang="ts">
import { ref } from 'vue'
import type { AttachmentInfo } from '@/api/types'
import { fmtSize } from '@/composables/format'
import AttachmentPreviewDialog from '@/components/runtime/AttachmentPreviewDialog.vue'

defineProps<{ items: AttachmentInfo[] }>()
const preview = ref<AttachmentInfo | null>(null)
</script>

<template>
  <ul class="tm-file-list">
    <li v-for="a in items" :key="a.id">
      <i class="pi pi-paperclip tm-muted" />
      <button type="button" class="tm-link-btn" @click="preview = a">{{ a.name }}</button>
      <span class="tm-muted">{{ fmtSize(a.size) }}</span>
      <span v-if="a.avStatus === 'not_scanned'" class="tm-pill neutral" v-tooltip="'Antivírus não configurado neste ambiente'">sem varredura</span>
      <span v-else-if="a.avStatus === 'clean'" class="tm-pill green">verificado</span>
    </li>
    <li v-if="!items.length" class="tm-muted">Nenhum anexo.</li>
  </ul>
  <AttachmentPreviewDialog v-model:attachment="preview" />
</template>
