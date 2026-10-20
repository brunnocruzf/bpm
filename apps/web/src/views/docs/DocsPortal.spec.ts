import { flushPromises, mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import { defineComponent, h } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import { describe, expect, it, vi } from 'vitest'
import { docsRoutes } from '@/docs/catalog'

const Empty = defineComponent({ render: () => h('div') })

async function openPortal(path: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [docsRoutes, { path: '/', component: Empty }],
  })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(defineComponent({ render: () => h(RouterView) }), {
    global: { plugins: [router, PrimeVue] },
  })
  await flushPromises()
  return wrapper
}

describe('Portal de documentação de integrações (público)', () => {
  it('renderiza o menu com a visão geral e os 5 guias, em grupos recolhíveis', async () => {
    const wrapper = await openPortal('/docs/integracoes')

    expect(wrapper.find('header').text()).toContain('BPM TMSA — Documentação de integrações')
    const items = wrapper.findAll('.docs-sidebar a').map((a) => a.text())
    expect(items.slice(0, 6)).toEqual([
      'Visão geral',
      'Criar uma integração',
      'Mapeamentos e MOCK',
      'Consumir em formulários (Form.io)',
      'Etapa do tipo Integração',
      'Operação e suporte',
    ])
    const groups = wrapper.findAll('.docs-group__toggle').map((b) => b.text())
    expect(groups).toEqual(['Primeiros passos', 'Guias', 'Referência'])
  })

  it('abre a página "Criar uma integração" pela rota, com breadcrumb, título e "Nesta página"', async () => {
    const wrapper = await openPortal('/docs/integracoes/criar-integracao')

    const crumbs = wrapper.findAll('.docs-breadcrumb li').map((li) => li.text())
    expect(crumbs).toEqual(['Início', 'Integrações', 'Criar uma integração'])
    // o conteúdo do .md é carregado de forma assíncrona (import ?raw)
    await vi.waitFor(() => expect(wrapper.find('h1').exists()).toBe(true))
    expect(wrapper.find('h1').text()).toContain('Criar uma integração')
    expect(wrapper.find('.docs-article').exists()).toBe(true)
    expect(wrapper.find('.docs-toc').text()).toContain('Nesta página')
    expect(wrapper.find('.docs-toc a').exists()).toBe(true)
  })

  it('mostra aviso para página inexistente, sem exigir login', async () => {
    const wrapper = await openPortal('/docs/integracoes/nao-existe')
    expect(wrapper.text()).toContain('Página não encontrada')
  })
})
