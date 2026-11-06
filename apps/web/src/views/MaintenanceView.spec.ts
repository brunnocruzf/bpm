import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '@/api/http'
import MaintenanceView from './MaintenanceView.vue'

const replace = vi.hoisted(() => vi.fn())
vi.mock('vue-router', () => ({ useRouter: () => ({ replace }) }))
vi.mock('@/api/http', async (importOriginal) => ({
  ...await importOriginal<typeof import('@/api/http')>(),
  api: vi.fn(),
}))

async function render(state: Record<string, unknown>) {
  vi.mocked(api).mockResolvedValue({ stage: null, percent: null, failed: null, ...state } as never)
  const wrapper = mount(MaintenanceView, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } })
  await flushPromises()
  return wrapper
}

describe('página de manutenção', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    vi.useFakeTimers()
  })
  afterEach(() => vi.useRealTimers())

  it('mostra em que etapa a restauração está', async () => {
    const wrapper = await render({ mode: 'maintenance', stage: 'swapping' })
    expect(wrapper.text()).toContain('Sistema em manutenção')
    expect(wrapper.text()).toMatch(/Parada dos serviços\s*Concluída/)
    expect(wrapper.text()).toMatch(/Troca dos dados\s*Em andamento/)
    expect(wrapper.text()).toMatch(/Reinício dos serviços\s*Aguardando/)
  })

  it('no modo seguro explica que um administrador está conferindo', async () => {
    const wrapper = await render({ mode: 'safe' })
    expect(wrapper.text()).toContain('Sistema em revisão')
    expect(wrapper.text()).toContain('revisar a restauração')
    expect(wrapper.text()).not.toContain('Troca dos dados')
  })

  it('mostra o aviso quando a restauração precisa de atenção', async () => {
    const wrapper = await render({ mode: 'maintenance', failed: 'Siga o runbook.' })
    expect(wrapper.text()).toContain('Siga o runbook.')
  })

  it('volta ao sistema quando a manutenção acaba', async () => {
    await render({ mode: 'normal' })
    expect(replace).toHaveBeenCalledWith({ path: '/' })
  })

  it('continua tentando enquanto a API reinicia', async () => {
    const wrapper = await render({ mode: 'maintenance', stage: 'starting' })
    vi.mocked(api).mockRejectedValueOnce(new Error('sem conexão'))
    await vi.advanceTimersByTimeAsync(3000)
    expect(wrapper.text()).toContain('Reconectando')

    vi.mocked(api).mockResolvedValue({ mode: 'normal', stage: null, percent: null, failed: null } as never)
    await vi.advanceTimersByTimeAsync(3000)
    expect(replace).toHaveBeenCalledWith({ path: '/' })
  })
})
