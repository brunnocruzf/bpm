// Páginas do portal de documentação de integrações.
// Fonte: docs/ (cópia versionada em src/docs/content, gerada por `npm run docs:sync`).
import type { RouteRecordRaw } from 'vue-router'

export type DocGroup = 'Primeiros passos' | 'Guias' | 'Referência'

export const DOC_GROUPS: DocGroup[] = ['Primeiros passos', 'Guias', 'Referência']

export interface DocPage {
  slug: string
  title: string
  group: DocGroup
  /** nome do arquivo de origem: usado para reescrever links relativos entre os documentos */
  file: string
  load: () => Promise<string>
}

export const DOC_PAGES: DocPage[] = [
  {
    slug: 'visao-geral',
    title: 'Visão geral',
    group: 'Primeiros passos',
    file: 'README.md',
    load: () => import('./content/integracoes/README.md?raw').then((m) => m.default),
  },
  {
    slug: 'criar-integracao',
    title: 'Criar uma integração',
    group: 'Guias',
    file: 'guia-criar-integracao.md',
    load: () => import('./content/integracoes/guia-criar-integracao.md?raw').then((m) => m.default),
  },
  {
    slug: 'mapeamentos-e-mock',
    title: 'Mapeamentos e MOCK',
    group: 'Guias',
    file: 'guia-mapeamentos-e-mock.md',
    load: () => import('./content/integracoes/guia-mapeamentos-e-mock.md?raw').then((m) => m.default),
  },
  {
    slug: 'consumir-formulario',
    title: 'Consumir em formulários (Form.io)',
    group: 'Guias',
    file: 'guia-consumir-formulario.md',
    load: () => import('./content/integracoes/guia-consumir-formulario.md?raw').then((m) => m.default),
  },
  {
    slug: 'etapa-integracao',
    title: 'Etapa do tipo Integração',
    group: 'Guias',
    file: 'guia-etapa-integracao.md',
    load: () => import('./content/integracoes/guia-etapa-integracao.md?raw').then((m) => m.default),
  },
  {
    slug: 'operacao-e-suporte',
    title: 'Operação e suporte',
    group: 'Guias',
    file: 'guia-operacao-e-suporte.md',
    load: () => import('./content/integracoes/guia-operacao-e-suporte.md?raw').then((m) => m.default),
  },
  {
    slug: 'adr-0016-catalogo-de-integracoes-rest',
    title: 'ADR-0016 — Catálogo de integrações REST',
    group: 'Referência',
    file: '0016-catalogo-de-integracoes-rest.md',
    load: () => import('./content/adr/0016-catalogo-de-integracoes-rest.md?raw').then((m) => m.default),
  },
  {
    slug: 'adr-0006-integracao-ax-2012-r3',
    title: 'ADR-0006 — Integração AX 2012 R3',
    group: 'Referência',
    file: '0006-integracao-ax-2012-r3.md',
    load: () => import('./content/adr/0006-integracao-ax-2012-r3.md?raw').then((m) => m.default),
  },
]

export function findDocPage(slug: string): DocPage | undefined {
  return DOC_PAGES.find((p) => p.slug === slug)
}

export function docPath(slug: string): string {
  return slug === 'visao-geral' ? '/docs/integracoes' : `/docs/integracoes/${slug}`
}

/** Link relativo entre arquivos de origem (ex.: "guia-operacao-e-suporte.md", "../adr/0016-...md") → rota; null = fora do portal. */
export function resolveDocLink(href: string): string | null {
  const name = href.split('/').pop() ?? ''
  const page = DOC_PAGES.find((p) => p.file === name)
  return page ? docPath(page.slug) : null
}

// Rotas PÚBLICAS (documentação do repositório, sem dados sensíveis): meta.public dispensa o login no guard.
export const docsRoutes: RouteRecordRaw = {
  path: '/docs/integracoes',
  component: () => import('../layouts/DocsLayout.vue'),
  meta: { public: true },
  children: [
    { path: '', name: 'docs-home', component: () => import('../views/docs/DocsPageView.vue'), props: { slug: 'visao-geral' } },
    { path: ':slug', name: 'docs-page', component: () => import('../views/docs/DocsPageView.vue'), props: true },
  ],
}
