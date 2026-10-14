<script setup lang="ts">
import type { TimelineEvent } from '@/api/types'
import { fmtDateTime } from '@/composables/format'

defineProps<{ events: TimelineEvent[] }>()

const tone = (t: string) => (t === 'task_created' || t === 'task_processing' ? 'pending' : t === 'task_cancelled' || t.startsWith('integration.failed') ? 'muted' : '')
</script>

<template>
  <div class="tm-timeline">
    <div v-for="(e, i) in events" :key="i" class="tm-tl-item">
      <div class="tm-tl-marker"><span class="tm-tl-dot" :class="tone(e.type)" /></div>
      <div class="tm-tl-text">
        <strong>{{ e.title }}</strong>
        <span>{{ fmtDateTime(e.at) }}<template v-if="e.detail"> · {{ e.detail }}</template></span>
      </div>
    </div>
    <div v-if="!events.length" class="tm-muted" style="font-size:12px">Sem eventos.</div>
  </div>
</template>
