<script setup lang="ts">
import { computed } from 'vue'
import { INSTANCE_STATUS, TASK_STATUS } from '@/composables/format'

const props = defineProps<{ status: string; kind?: 'instance' | 'task' | 'version'; label?: string }>()

const VERSION: Record<string, { label: string; tone: string }> = {
  draft: { label: 'Rascunho', tone: 'amber' },
  published: { label: 'Publicado', tone: 'green' },
  retired: { label: 'Substituída', tone: 'neutral' },
  suspended: { label: 'Suspensa', tone: 'red' },
}

const info = computed(() => {
  const map = props.kind === 'task' ? TASK_STATUS : props.kind === 'version' ? VERSION : INSTANCE_STATUS
  return map[props.status] ?? { label: props.status, tone: 'neutral' }
})
</script>

<template>
  <span class="tm-pill" :class="info.tone">● {{ label ?? info.label }}</span>
</template>
