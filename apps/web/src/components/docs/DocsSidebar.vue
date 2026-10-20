<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import InputText from 'primevue/inputtext'
import { DOC_GROUPS, DOC_PAGES, docPath, type DocGroup, type DocPage } from '@/docs/catalog'

const route = useRoute()
const query = ref('')
// grupos recolhidos pelo usuário; com busca ativa, todos os grupos com resultado ficam abertos
const collapsed = reactive<Record<string, boolean>>({})

function normalize(text: string): string {
  return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
}

const groups = computed(() => {
  const q = normalize(query.value.trim())
  return DOC_GROUPS.map((group) => ({
    name: group,
    pages: DOC_PAGES.filter((p) => p.group === group && (!q || normalize(p.title).includes(q))),
  })).filter((g) => g.pages.length > 0)
})

const currentSlug = computed(() => (route.params.slug as string | undefined) ?? 'visao-geral')

function isOpen(group: DocGroup): boolean {
  return !!query.value.trim() || !collapsed[group]
}

function toggle(group: DocGroup) {
  collapsed[group] = !collapsed[group]
}

function pathOf(page: DocPage): string {
  return docPath(page.slug)
}
</script>

<template>
  <aside class="docs-sidebar" aria-label="Menu da documentação">
    <div class="docs-search">
      <InputText v-model="query" type="search" placeholder="Buscar na documentação" aria-label="Buscar páginas" class="w-full" />
    </div>
    <nav>
      <p v-if="groups.length === 0" class="docs-sidebar__empty">Nenhuma página encontrada.</p>
      <div v-for="group in groups" :key="group.name" class="docs-group">
        <button type="button" class="docs-group__toggle" :aria-expanded="isOpen(group.name)" @click="toggle(group.name)">
          <i :class="isOpen(group.name) ? 'pi pi-chevron-down' : 'pi pi-chevron-right'" aria-hidden="true" />
          <span>{{ group.name }}</span>
        </button>
        <ul v-if="isOpen(group.name)" class="docs-group__items">
          <li v-for="page in group.pages" :key="page.slug">
            <RouterLink :to="pathOf(page)" :class="{ 'is-active': page.slug === currentSlug }" :aria-current="page.slug === currentSlug ? 'page' : undefined">
              {{ page.title }}
            </RouterLink>
          </li>
        </ul>
      </div>
    </nav>
  </aside>
</template>
