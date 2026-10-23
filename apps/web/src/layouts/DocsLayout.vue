<script setup lang="ts">
import { ref, watch } from 'vue'
import { RouterLink, RouterView } from 'vue-router'
import Button from 'primevue/button'
import DocsSidebar from '@/components/docs/DocsSidebar.vue'
import { useBranding } from '@/stores/branding'
import '@/styles/docs.scss'

const brand = useBranding()

const THEME_KEY = 'bpm-docs-theme'
const dark = ref(localStorage.getItem(THEME_KEY) === 'dark')
watch(dark, (value) => localStorage.setItem(THEME_KEY, value ? 'dark' : 'light'))
</script>

<template>
  <div class="docs-root" :class="{ 'docs-root--dark': dark }">
    <header class="docs-header">
      <RouterLink class="docs-brand" :to="{ name: 'docs-home' }">
        <i class="pi pi-book" aria-hidden="true" />
        <span>{{ brand.branding.appName }} — Documentação de integrações</span>
      </RouterLink>
      <div class="docs-header__actions">
        <RouterLink class="docs-header__link" to="/">Abrir o BPM</RouterLink>
        <Button
          :icon="dark ? 'pi pi-sun' : 'pi pi-moon'"
          text
          rounded
          :aria-label="dark ? 'Usar tema claro' : 'Usar tema escuro'"
          @click="dark = !dark"
        />
      </div>
    </header>
    <div class="docs-body">
      <DocsSidebar />
      <main class="docs-main">
        <RouterView />
      </main>
    </div>
  </div>
</template>
