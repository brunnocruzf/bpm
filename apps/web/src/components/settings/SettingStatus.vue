<script setup lang="ts">
import { computed } from 'vue'
import type { SettingStatus } from '@/api/types'

const props = defineProps<{ status: SettingStatus | string }>()

const MAP: Record<string, { label: string; tone: string; icon: string }> = {
  configured: { label: 'Configurada', tone: 'green', icon: 'pi-check-circle' },
  incomplete: { label: 'Incompleta', tone: 'amber', icon: 'pi-exclamation-circle' },
  test_pending: { label: 'Teste pendente', tone: 'blue', icon: 'pi-clock' },
  error: { label: 'Erro', tone: 'red', icon: 'pi-times-circle' },
  disabled: { label: 'Desabilitada', tone: 'neutral', icon: 'pi-minus-circle' },
}
const info = computed(() => MAP[props.status] ?? { label: props.status, tone: 'neutral', icon: 'pi-circle' })
</script>

<template>
  <span class="tm-pill" :class="info.tone"><i class="pi" :class="info.icon" aria-hidden="true" /> {{ info.label }}</span>
</template>
