import { flushPromises, mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSession } from '@/stores/session'
import UsersWorkspace from './UsersWorkspace.vue'

const { route, replace } = await vi.hoisted(async () => {
  const { reactive } = await import('vue')
  const route = reactive({ query: {} as Record<string, string> })
  const replace = vi.fn(async (to: { query: Record<string, string> }) => { route.query = to.query })
  return { route, replace }
})
vi.mock('vue-router', () => ({ useRoute: () => route, useRouter: () => ({ replace }) }))
vi.mock('@/auth', () => ({ auth: { getToken: async () => 'dev:admin' } }))

const row = (over: Record<string, unknown> = {}) => ({
  id: 'u1', name: 'Pessoa Local', email: 'pessoa@example.test', upn: null, username: 'pessoa.local', area: 'Operações', department: null,
  authSource: 'local', userCategory: 'supplier', userType: null, accountEnabled: null, disabledReason: null, roles: [], groups: [], isActive: false,
  invitationPending: true, invitationStatus: 'pending', locked: false, lastSeenAt: null, directorySyncedAt: null, manager: null, managerPending: false, ...over,
})
const detail = {
  identity: { id: 'u1', name: 'Pessoa Local', username: 'pessoa.local', email: 'pessoa@example.test', upn: null, authSource: 'local', entraOid: null, entraTid: null },
  organization: { area: 'Operações', department: null, userType: null, userCategory: 'supplier', manager: null, managerInfo: null, managerPending: false, directReports: 0 },
  account: { active: false, accountEnabled: null, disabledReason: null, disabledAt: null, createdAt: null, updatedAt: null, lastLoginAt: null, lastSeenAt: null, lockedUntil: null, directorySyncedAt: null, invitationPending: true },
  access: { roles: [], roleSources: { token: [], directoryMapping: [], manual: [] }, groups: [], groupSources: { entraToken: [], directoryMapping: [], manual: [] } },
  integration: { source: 'local', entra: null, local: { invitation: { status: 'pending', sentAt: null, expiresAt: '2026-10-21T12:00:00Z', acceptedAt: null, invitedBy: 'Ana', delivery: { state: 'skipped', sentAt: null, failedAt: null, errorCode: 'provider_disabled' } }, credential: { lastLoginAt: null, failedAttempts: 0, lockedUntil: null, passwordChangedAt: null }, sessions: { active: 0, lastUsedAt: null } } },
  activity: { data: [], meta: { currentPage: 1, lastPage: 1, total: 0 } },
}
const facets = { total: 1, source: { local: 1 }, active: { true: 0, false: 1 }, category: { supplier: 1, none: 0 }, attention: { invitation_pending: 1 }, areas: ['Operações'], roles: [{ key: 'Bpm.Admin', label: 'Admin' }] }

const stubMatchMedia = () => vi.stubGlobal('matchMedia', (q: string) => ({ matches: true, media: q, addEventListener: vi.fn(), removeEventListener: vi.fn(), addListener: vi.fn(), removeListener: vi.fn() }))

function setup() {
  stubMatchMedia()
  const calls: { method: string; url: string; body: any }[] = []
  vi.stubGlobal('fetch', vi.fn(async (url: string | URL, init?: RequestInit) => {
    const u = String(url)
    calls.push({ method: init?.method ?? 'GET', url: u, body: init?.body ? JSON.parse(String(init.body)) : null })
    const body = u.includes('/facets') ? { data: facets } : u.endsWith('/admin/managed-users/u1') ? { data: detail }
      : init?.method === 'POST' ? { message: 'Conta criada.' } : { data: [row()], meta: { currentPage: 1, lastPage: 1, perPage: 25, total: 1 } }
    return new Response(JSON.stringify(body), { status: init?.method === 'POST' ? 201 : 200 })
  }))
  const pinia = createPinia()
  setActivePinia(pinia)
  const session = useSession()
  session.me = { id: 'a', name: 'Ana', email: null, area: null, roles: [], groups: [], permissions: { settingsManage: true } } as never
  session.meta = { groups: [] } as never
  const w = mount(UsersWorkspace, { global: { plugins: [PrimeVue, ToastService, pinia], stubs: { RouterLink: true, Dialog: { props: ['visible'], template: '<div v-if="visible"><slot /></div>' }, Drawer: true } } })
  return { w, calls }
}

describe('UsersWorkspace', () => {
  beforeEach(() => { route.query = {}; replace.mockClear() })
  afterEach(() => { vi.unstubAllGlobals(); vi.useRealTimers() })

  it('carrega lista e filtros no servidor e aplica a busca com debounce', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    const { w, calls } = setup()
    await flushPromises()
    expect(calls.some((c) => c.url.includes('/admin/managed-users?') && c.url.includes('perPage=25'))).toBe(true)
    expect(calls.some((c) => c.url.includes('/admin/managed-users/facets'))).toBe(true)
    expect(w.text()).toContain('Pessoa Local')
    const before = calls.length
    await w.find('#uf-q').setValue('pessoa')
    vi.advanceTimersByTime(299)
    expect(calls.length).toBe(before)
    vi.advanceTimersByTime(2)
    await flushPromises()
    expect(calls.at(-1)!.url).toContain('q=pessoa')
  })

  it('seleciona uma linha, grava ?user na URL e mostra a integração da conta local', async () => {
    const { w, calls } = setup()
    await flushPromises()
    await w.find('.user-row').trigger('click')
    expect(replace).toHaveBeenCalledWith({ query: { user: 'u1' } })
    await flushPromises()
    expect(calls.some((c) => c.url.endsWith('/admin/managed-users/u1'))).toBe(true)
    const text = w.find('#user-detail').text()
    expect(text).toContain('Integração e origem')
    expect(text).toContain('Pendente até')
    expect(text).toContain('o serviço de e-mail está desabilitado')
    expect(text).not.toContain('203.0.113')
  })

  it('mostra área, departamento e grupos na lista', async () => {
    const { w } = setup()
    await flushPromises()
    const head = w.find('.list-head').text()
    expect(head).toContain('Área')
    expect(head).toContain('Departamento')
    expect(head).toContain('Grupos/raias')
    expect(w.find('.user-row .c-area').text()).toContain('Operações')
  })

  it('expande o perfil em modal e recolhe de volta ao painel lateral', async () => {
    route.query = { user: 'u1' }
    const { w } = setup()
    await flushPromises()
    expect(w.find('aside.detail-col #user-detail').exists()).toBe(true)
    await w.find('[aria-label="Expandir perfil em tela ampla"]').trigger('click')
    expect(w.find('aside.detail-col').exists()).toBe(false)
    expect(w.find('#user-detail.expanded').exists()).toBe(true)
    await w.find('[aria-label="Recolher perfil para o painel lateral"]').trigger('click')
    expect(w.find('#user-detail.expanded').exists()).toBe(false)
    expect(w.find('aside.detail-col #user-detail').exists()).toBe(true)
    expect(replace).not.toHaveBeenCalled()
  })

  it('exige o tipo de usuário e envia userCategory ao criar a conta', async () => {
    const { w, calls } = setup()
    await flushPromises()
    await w.findAll('button').find((b) => b.text() === 'Novo usuário')!.trigger('click')
    await w.find('#new-user-name').setValue('Nova Pessoa')
    await w.find('#new-user-email').setValue('nova@example.test')
    await w.find('#new-user-username').setValue('nova.pessoa')
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(w.text()).toContain('Selecione o tipo de usuário.')
    expect(calls.some((c) => c.method === 'POST')).toBe(false)

    await w.find('#new-user-category-customer').setValue(true)
    await w.find('form').trigger('submit')
    await flushPromises()
    const post = calls.find((c) => c.method === 'POST')!
    expect(post.url).toContain('/admin/managed-users')
    expect(post.body).toMatchObject({ name: 'Nova Pessoa', username: 'nova.pessoa', userCategory: 'customer' })
  })

  it('sem settings.manage não chama a API e explica a permissão', async () => {
    stubMatchMedia()
    vi.stubGlobal('fetch', vi.fn())
    const pinia = createPinia()
    setActivePinia(pinia)
    useSession().me = { id: 'a', name: 'Bia', email: null, area: null, roles: [], groups: [], permissions: { settingsManage: false } } as never
    const w = mount(UsersWorkspace, { global: { plugins: [PrimeVue, ToastService, pinia], stubs: { RouterLink: true } } })
    await flushPromises()
    expect(w.text()).toContain('settings.manage')
    expect(fetch).not.toHaveBeenCalled()
  })
})
