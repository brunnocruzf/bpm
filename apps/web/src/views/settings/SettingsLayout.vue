<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '@/api/http'
import type { SettingView } from '@/api/types'
import SettingStatus from '@/components/settings/SettingStatus.vue'
import { useBranding } from '@/stores/branding'
import { SETTINGS_SECTIONS } from './sections'

const route = useRoute()
const brand = useBranding()
const statuses = ref<Record<string, string>>({})

const sections = SETTINGS_SECTIONS

async function refresh() {
  try {
    const res = await api<{ data: (SettingView & { slug: string })[] }>('/admin/settings')
    statuses.value = Object.fromEntries(res.data.map((s) => [s.slug, s.status]))
  } catch {
    /* a própria seção mostra o erro */
  }
}
onMounted(refresh)
</script>

<template>
  <div class="tm-crumbs"><RouterLink to="/">Início</RouterLink><span class="sep">/</span><RouterLink :to="{ name: 'settings' }">Configurações</RouterLink></div>
  <div class="settings-grid">
    <nav class="tm-panel settings-nav" aria-label="Seções de configuração">
      <div class="tm-panel-head"><div><div class="tm-panel-title">Configurações</div><div class="tm-panel-caption">Organização {{ brand.branding.orgName }}</div></div></div>
      <RouterLink :to="{ name: 'settings' }" class="nav-item" :class="{ active: route.name === 'settings' }"><i class="pi pi-th-large" aria-hidden="true" /><span>Visão geral</span></RouterLink>
      <RouterLink v-for="s in sections" :key="s.name" :to="{ name: s.name }" class="nav-item" :class="{ active: route.name === s.name }">
        <i class="pi" :class="s.icon" aria-hidden="true" /><span>{{ s.label }}</span>
        <SettingStatus v-if="statuses[s.slug]" :status="statuses[s.slug]" class="nav-status" />
      </RouterLink>
    </nav>
    <section class="settings-content">
      <RouterView v-slot="{ Component }">
        <component :is="Component" @changed="refresh" />
      </RouterView>
    </section>
  </div>
</template>

<style scoped>
.settings-grid { display: grid; grid-template-columns: 290px minmax(0, 1fr); gap: 18px; align-items: start; }
@media (max-width: 1000px) { .settings-grid { grid-template-columns: 1fr; } }
.settings-nav { padding-bottom: 8px; position: sticky; top: 80px; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 14px; color: #48596b; text-decoration: none; font-size: 12px; flex-wrap: wrap; border-left: 3px solid transparent; }
.nav-item:hover { background: var(--brand-softer); }
.nav-item.active { color: var(--brand-text); background: var(--brand-soft); font-weight: 700; border-left-color: var(--brand); }
.nav-item i { width: 16px; color: #708192; }
.nav-item span { flex: 1; min-width: 150px; }
.nav-status { font-size: 10px; margin-left: 26px; }
.settings-content { min-width: 0; }
</style>
