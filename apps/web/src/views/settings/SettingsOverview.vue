<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '@/api/http'
import type { SettingView } from '@/api/types'
import { fmtDateTime } from '@/composables/format'
import SettingStatus from '@/components/settings/SettingStatus.vue'
import { SETTINGS_SECTIONS as sections } from './sections'

defineEmits<{ changed: [] }>()
const items = ref<Record<string, SettingView & { slug: string }>>({})

onMounted(async () => {
  const res = await api<{ data: (SettingView & { slug: string })[] }>('/admin/settings')
  items.value = Object.fromEntries(res.data.map((s) => [s.slug, s]))
})
</script>

<template>
  <div class="tm-heading" style="margin-top:0">
    <div>
      <h1>Configurações</h1>
      <p class="tm-subtitle">Integrações e parâmetros da organização. Segredos nunca são exibidos depois de gravados; cada alteração é versionada e auditada.</p>
    </div>
  </div>
  <div class="cards">
    <RouterLink v-for="s in sections" :key="s.name" :to="{ name: s.name }" class="tm-panel card">
      <div class="card-head"><i class="pi" :class="s.icon" aria-hidden="true" /><strong>{{ s.label }}</strong></div>
      <p class="tm-muted">{{ s.description }}</p>
      <template v-if="items[s.slug]">
        <SettingStatus :status="items[s.slug].status" />
        <div class="tm-hint">{{ items[s.slug].statusDetail }}</div>
        <div class="tm-hint" v-if="items[s.slug].updatedAt">Alterada em {{ fmtDateTime(items[s.slug].updatedAt) }} por {{ items[s.slug].updatedBy }}</div>
        <div class="tm-hint" v-if="items[s.slug].lastValidatedAt">Última validação: {{ items[s.slug].lastValidation?.ok ? 'OK' : 'falhou' }} em {{ fmtDateTime(items[s.slug].lastValidatedAt) }}</div>
      </template>
    </RouterLink>
  </div>
</template>

<style scoped>
.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
.card { padding: 16px; text-decoration: none; color: inherit; display: flex; flex-direction: column; gap: 6px; }
.card:hover { border-color: var(--brand-line); }
.card-head { display: flex; align-items: center; gap: 8px; font-size: 13px; }
.card-head i { color: var(--brand-text); }
.card p { margin: 0; font-size: 12px; }
</style>
