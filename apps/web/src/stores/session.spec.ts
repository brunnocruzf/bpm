import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSession } from './session'

vi.mock('@/auth', () => ({ auth: { getToken: async () => 'dev:admin', isAuthenticated: () => false, logout: async () => {} } }))

const me = (photo: { version: string } | null) => ({ id: 'u1', name: 'Ana Admin', email: null, area: null, roles: [], groups: [], permissions: {}, counters: { openTasks: 0 }, photo, auth: { method: 'entra' } })

describe('session store — foto do Entra ID', () => {
  let photoCalls: string[]
  let current: ReturnType<typeof me>
  const created: string[] = []
  const revoked: string[] = []

  beforeEach(() => {
    setActivePinia(createPinia())
    photoCalls = []
    created.length = 0
    revoked.length = 0
    current = me({ version: 'v1' })
    Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => { const u = `blob:foto-${created.length + 1}`; created.push(u); return u }) })
    Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn((u: string) => { revoked.push(u) }) })
    vi.stubGlobal('fetch', vi.fn(async (url: string | URL) => {
      const u = String(url)
      if (u.includes('/me/photo')) { photoCalls.push(u); return new Response(new Blob([new Uint8Array([1, 2, 3])], { type: 'image/webp' }), { status: 200 }) }
      if (u.endsWith('/me')) return new Response(JSON.stringify(current), { status: 200 })
      return new Response(JSON.stringify({ groups: [], categories: [] }), { status: 200 })
    }))
  })
  afterEach(() => vi.unstubAllGlobals())

  it('baixa a foto no carregamento e só de novo quando a versão muda', async () => {
    const s = useSession()
    await s.load()
    expect(s.photoUrl).toBe('blob:foto-1')
    expect(photoCalls).toHaveLength(1)
    expect(photoCalls[0]).toContain('v=v1')

    await s.refreshCounters()
    expect(photoCalls).toHaveLength(1)

    current = me({ version: 'v2' })
    await s.refreshCounters()
    expect(photoCalls).toHaveLength(2)
    expect(s.photoUrl).toBe('blob:foto-2')
    expect(revoked).toContain('blob:foto-1')
  })

  it('sem foto no servidor volta às iniciais e revoga a URL', async () => {
    const s = useSession()
    await s.load()
    current = me(null)
    await s.refreshCounters()
    expect(s.photoUrl).toBeNull()
    expect(revoked).toContain('blob:foto-1')
    expect(s.initials).toBe('AA')
  })

  it('falha ao baixar a foto não quebra a sessão e a próxima leitura tenta de novo', async () => {
    let fail = true
    vi.stubGlobal('fetch', vi.fn(async (url: string | URL) => {
      const u = String(url)
      if (u.includes('/me/photo')) return fail ? new Response('{}', { status: 500 }) : new Response(new Blob([new Uint8Array([1])]), { status: 200 })
      if (u.endsWith('/me')) return new Response(JSON.stringify(current), { status: 200 })
      return new Response('{}', { status: 200 })
    }))
    const s = useSession()
    await s.load()
    expect(s.me?.name).toBe('Ana Admin')
    expect(s.photoUrl).toBeNull()
    fail = false
    await s.refreshCounters()
    expect(s.photoUrl).toBe('blob:foto-1')
  })

  it('logout revoga a foto', async () => {
    const s = useSession()
    await s.load()
    await s.logout()
    expect(s.photoUrl).toBeNull()
    expect(s.me).toBeNull()
    expect(revoked).toContain('blob:foto-1')
  })
})
