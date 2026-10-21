<script setup lang="ts">
import { ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { docPath, findDocPage, resolveDocImage, resolveDocLink } from '@/docs/catalog'
import { renderMarkdown, type RenderedDoc } from '@/docs/markdown'

const props = withDefaults(defineProps<{ slug?: string }>(), { slug: 'visao-geral' })
const router = useRouter()

const doc = ref<RenderedDoc | null>(null)
const failed = ref(false)
let request = 0

watch(
  () => props.slug,
  async (slug) => {
    const page = findDocPage(slug)
    const current = ++request
    doc.value = null
    failed.value = false
    if (!page) return
    try {
      const source = await page.load()
      if (current !== request) return
      doc.value = renderMarkdown(source, resolveDocLink, resolveDocImage)
      document.title = `${page.title} · Documentação de integrações`
    } catch {
      if (current === request) failed.value = true
    }
  },
  { immediate: true },
)

function scrollToHeading(id: string) {
  document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

// links do Markdown: âncoras rolam a página; links do próprio portal usam o roteador (sem recarregar)
function onArticleClick(event: MouseEvent) {
  const link = (event.target as HTMLElement).closest('a')
  const href = link?.getAttribute('href') ?? ''
  if (href.startsWith('#')) {
    event.preventDefault()
    scrollToHeading(href.slice(1))
  } else if (href.startsWith('/docs/')) {
    event.preventDefault()
    router.push(href)
  }
}
</script>

<template>
  <div class="docs-content">
    <div class="docs-content__main">
      <nav class="docs-breadcrumb" aria-label="Você está em">
        <ol>
          <li><RouterLink :to="docPath('visao-geral')">Início</RouterLink></li>
          <li><RouterLink :to="docPath('visao-geral')">Integrações</RouterLink></li>
          <li aria-current="page">{{ findDocPage(slug)?.title ?? 'Página não encontrada' }}</li>
        </ol>
      </nav>

      <template v-if="findDocPage(slug) && doc">
        <h1 class="docs-title">{{ doc.title || findDocPage(slug)?.title }}</h1>
        <!-- HTML gerado por renderMarkdown (html: false): sem HTML bruto dos arquivos -->
        <article class="docs-article" @click="onArticleClick" v-html="doc.html" />
      </template>
      <div v-else-if="!findDocPage(slug)" class="docs-notfound">
        <h1 class="docs-title">Página não encontrada</h1>
        <p>Esta página não existe no portal. <RouterLink :to="docPath('visao-geral')">Voltar à visão geral</RouterLink>.</p>
      </div>
      <p v-else-if="failed" class="docs-notfound">Não foi possível carregar esta página.</p>
      <p v-else class="docs-loading">Carregando…</p>
    </div>

    <aside v-if="doc && doc.headings.length > 0" class="docs-toc" aria-label="Nesta página">
      <p class="docs-toc__title">Nesta página</p>
      <ul>
        <li v-for="h in doc.headings" :key="h.id" :class="h.level === 3 ? 'docs-toc__item--sub' : undefined">
          <a :href="`#${h.id}`" @click.prevent="scrollToHeading(h.id)">{{ h.text }}</a>
        </li>
      </ul>
    </aside>
  </div>
</template>
