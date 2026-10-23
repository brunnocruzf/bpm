import { flushPromises, mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSession } from '@/stores/session'
import AdminGroupsView from './AdminGroupsView.vue'

vi.mock('vue-router', () => ({ useRoute: () => ({ query: {} }), useRouter: () => ({ replace: vi.fn() }) }))
vi.mock('@/auth', () => ({ auth: { getToken: async () => 'dev:admin' } }))

const group = { key: 'CQ', name: 'CQ / Recebimento', description: 'Qualidade', entraGroupId: null, email: null, members: 2 }
const members = {
  group: { key: 'CQ', name: 'CQ / Recebimento' },
  data: [
    { id: 'u1', name: 'Pessoa Manual', email: 'manual@example.test', authSource: 'local', active: true, source: 'manual', createdAt: '2026-10-16T12:00:00Z', createdBy: 'Ana' },
    { id: 'u2', name: 'Pessoa Entra', email: 'entra@example.test', authSource: 'entra', active: true, source: 'entra_sync', createdAt: null, createdBy: null },
  ],
  excluded: [{ id: 'u3', name: 'Pessoa Bloqueada', email: 'bloq@example.test', authSource: 'entra', createdAt: '2026-10-16T13:00:00Z', createdBy: 'Ana' }],
  meta: { currentPage: 1, lastPage: 1, total: 2 },
}

describe('AdminGroupsView — membros de grupos/raias', () => {
  let calls: { method: string; url: string }[]

  beforeEach(() => {
    calls = []
    vi.stubGlobal('confirm', vi.fn(() => true))
    vi.stubGlobal('matchMedia', (q: string) => ({ matches: true, media: q, addEventListener: vi.fn(), removeEventListener: vi.fn(), addListener: vi.fn(), removeListener: vi.fn() }))
    vi.stubGlobal('fetch', vi.fn(async (url: string | URL, init?: RequestInit) => {
      const u = String(url)
      const method = init?.method ?? 'GET'
      calls.push({ method, url: u })
      if (method !== 'GET') return new Response(null, { status: 204 })
      if (u.includes('/admin/groups/CQ/members')) return new Response(JSON.stringify(members), { status: 200 })
      if (u.includes('/admin/groups')) return new Response(JSON.stringify({ data: [group] }), { status: 200 })
      if (u.includes('/facets')) return new Response(JSON.stringify({ data: { total: 0, source: {}, active: { true: 0, false: 0 }, category: {}, attention: {}, areas: [], roles: [] } }), { status: 200 })
      return new Response(JSON.stringify({ data: [], meta: { currentPage: 1, lastPage: 1, perPage: 25, total: 0 } }), { status: 200 })
    }))
  })
  afterEach(() => vi.unstubAllGlobals())

  async function open(manage: boolean) {
    const pinia = createPinia()
    setActivePinia(pinia)
    const session = useSession()
    session.me = { id: 'a', name: 'Ana', email: null, area: null, roles: [], groups: [], permissions: { settingsManage: manage } } as never
    session.meta = { groups: [] } as never
    const w = mount(AdminGroupsView, { global: { plugins: [PrimeVue, ToastService, pinia], stubs: { RouterLink: true, Dialog: { props: ['visible', 'header'], template: '<div v-if="visible" class="dlg"><slot /></div>' }, Drawer: true } } })
    await flushPromises()
    await w.findAll('[role="tab"]').find((t) => t.text() === 'Grupos/Raias')!.trigger('click')
    await flushPromises()
    await w.findAll('button').find((b) => b.text() === 'Membros')?.trigger('click')
    await flushPromises()
    return w
  }

  it('mostra a origem de cada vínculo e oferece "Remover do grupo" para qualquer origem; o bloqueio chama a API e recarrega', async () => {
    const w = await open(true)
    const dialog = w.find('.dlg')
    expect(dialog.text()).toContain('Manual — BPM')
    expect(dialog.text()).toContain('Entra ID — sincronização')
    expect(dialog.text()).not.toContain('entra_sync')
    expect(calls.find((c) => c.url.includes('/admin/groups/CQ/members'))!.url).toContain('perPage=100')

    const removeButtons = dialog.findAll('button').filter((b) => b.text() === 'Remover do grupo')
    expect(removeButtons).toHaveLength(2)
    const before = calls.length
    await removeButtons[1].trigger('click')
    await flushPromises()
    const put = calls.slice(before).find((c) => c.method === 'PUT')!
    expect(put.url).toContain('/admin/groups/CQ/exclusions/u2')
    expect(calls.slice(before).some((c) => c.method === 'GET' && c.url.includes('/admin/groups/CQ/members'))).toBe(true)
    expect(window.confirm).toHaveBeenCalledTimes(1)
    expect(String((window.confirm as unknown as ReturnType<typeof vi.fn>).mock.calls[0][0])).toContain('o Entra ID não é alterado')
  })

  it('lista os removidos neste sistema e permite restaurar', async () => {
    const w = await open(true)
    const dialog = w.find('.dlg')
    expect(dialog.text()).toContain('Removidos neste sistema (1)')
    expect(dialog.text()).toContain('Pessoa Bloqueada')
    const before = calls.length
    await dialog.findAll('button').find((b) => b.text() === 'Restaurar')!.trigger('click')
    await flushPromises()
    const del = calls.slice(before).find((c) => c.method === 'DELETE')!
    expect(del.url).toContain('/admin/groups/CQ/exclusions/u3')
  })

  it('sem settings.manage não há como abrir os membros nem remover/restaurar (a API também nega)', async () => {
    const w = await open(false)
    expect(w.find('.dlg').exists()).toBe(false)
    expect(w.findAll('button').filter((b) => ['Membros', 'Remover do grupo', 'Restaurar'].includes(b.text()))).toHaveLength(0)
  })
})
