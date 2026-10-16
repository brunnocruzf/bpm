import { flushPromises, mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import AttachmentStorageSettings from './AttachmentStorageSettings.vue'

vi.mock('@/auth', () => ({ auth: { getToken: async () => 'dev:admin' } }))

const base = (over: Record<string, unknown> = {}) => ({
  key: 'attachments.storage', label: 'Armazenamento de anexos', status: 'test_pending', statusDetail: null, version: 3, requiresActivation: true, activatedAt: null,
  updatedAt: '2026-10-16T12:00:00Z', updatedBy: 'Ana', lastValidation: null, lastValidatedAt: null,
  value: {
    provider: 'azure_blob',
    azure: { endpointMode: 'public', cloud: 'global', accountName: 'bpmanexos', container: 'anexos', pathPrefix: 'bpm', approvalReference: 'SEC-1', checklist: {}, credential: { configured: true, source: 'cofre da aplicação (criptografado)', readable: true } },
    providers: [{ key: 'platform', label: 'Armazenamento da plataforma' }, { key: 'azure_blob', label: 'Azure Blob Storage' }],
    emulatorAvailable: false, environment: { disk: 'attachments_s3', driver: 's3' },
    effective: { provider: 'platform', accountName: null, container: null, pathPrefix: '', emulator: false, activatedAt: null },
  },
  ...over,
})

describe('AttachmentStorageSettings', () => {
  let current: ReturnType<typeof base>
  let calls: { method: string; url: string; body: any }[]

  beforeEach(() => {
    current = base()
    calls = []
    vi.stubGlobal('confirm', vi.fn(() => true))
    vi.stubGlobal('matchMedia', (q: string) => ({ matches: false, media: q, addEventListener: vi.fn(), removeEventListener: vi.fn(), addListener: vi.fn(), removeListener: vi.fn() }))
    vi.stubGlobal('fetch', vi.fn(async (url: string | URL, init?: RequestInit) => {
      const u = String(url)
      calls.push({ method: init?.method ?? 'GET', url: u, body: init?.body ? JSON.parse(String(init.body)) : null })
      if (u.endsWith('/usage')) return new Response(JSON.stringify({ data: [{ disk: 'attachments_s3', files: 3, bytes: 2048, label: 'Plataforma (attachments_s3)', status: 'platform' }] }), { status: 200 })
      if (u.endsWith('/test')) { current = base({ status: 'configured', lastValidation: { ok: true, code: 'storage_ok', version: 3, checks: [], message: 'ok' } }) }
      return new Response(JSON.stringify(current), { status: 200 })
    }))
  })
  afterEach(() => vi.unstubAllGlobals())

  const mountIt = async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const w = mount(AttachmentStorageSettings, { global: { plugins: [PrimeVue, ToastService, pinia], stubs: { IntegrationLog: true } } })
    await flushPromises()
    return w
  }
  const btn = (w: ReturnType<typeof mount>, text: string) => w.findAll('button').find((b) => b.text().includes(text))!

  it('a chave é somente escrita: o campo vem vazio, indica que está definida e só é enviado quando preenchido', async () => {
    const w = await mountIt()
    const key = w.find('#a-key')
    expect((key.element as HTMLInputElement).value).toBe('')
    expect((key.element as HTMLInputElement).placeholder).toContain('definida')
    expect(w.html()).not.toContain('credentialRef')

    await btn(w, 'Salvar').trigger('click')
    await flushPromises()
    const first = calls.find((c) => c.method === 'PUT')!
    expect(first.body.azure.accountKey).toBeUndefined()

    await key.setValue('Ym9hLWNoYXZl')
    await btn(w, 'Salvar').trigger('click')
    await flushPromises()
    const second = calls.filter((c) => c.method === 'PUT')[1]
    expect(second.body.azure.accountKey).toBe('Ym9hLWNoYXZl')
    expect((w.find('#a-key').element as HTMLInputElement).value).toBe('')
  })

  it('só habilita "Ativar" depois de um teste aprovado nesta versão e confirma antes de ativar', async () => {
    const w = await mountIt()
    expect(btn(w, 'Ativar').attributes('disabled')).toBeDefined()
    await btn(w, 'Testar').trigger('click')
    await flushPromises()
    expect(calls.some((c) => c.method === 'POST' && c.url.endsWith('/attachments-storage/test'))).toBe(true)
    expect(btn(w, 'Ativar').attributes('disabled')).toBeUndefined()
    await btn(w, 'Ativar').trigger('click')
    await flushPromises()
    expect(calls.some((c) => c.method === 'POST' && c.url.endsWith('/attachments-storage/activate'))).toBe(true)
  })

  it('mostra o destino em vigor, onde estão os anexos e não oferece o emulador fora do desenvolvimento', async () => {
    const w = await mountIt()
    expect(w.text()).toContain('armazenamento da plataforma')
    expect(w.text()).toContain('Plataforma (attachments_s3)')
    expect(w.find('#a-mode').exists()).toBe(false)
  })
})
