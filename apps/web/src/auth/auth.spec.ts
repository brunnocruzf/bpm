import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/** Métodos de login do SPA (ADR-0015): o servidor decide; o SPA guarda só a sessão do método escolhido. */
type AuthModule = typeof import('./index')

function mockConfig(methods: Record<string, unknown>, entra: unknown = null) {
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ driver: 'dev', source: 'environment', entra, methods }), { status: 200 })))
}

async function load(demoBuild: boolean): Promise<AuthModule> {
  vi.resetModules()
  vi.stubEnv('VITE_AUTH_MODE', demoBuild ? 'dev' : 'entra')
  vi.stubEnv('VITE_ENTRA_CLIENT_ID', '')
  vi.stubEnv('VITE_ENTRA_TENANT_ID', '')
  return import('./index')
}

describe('auth (métodos simultâneos)', () => {
  beforeEach(() => sessionStorage.clear())
  afterEach(() => {
    vi.unstubAllEnvs()
    vi.unstubAllGlobals()
  })

  it('reflete os métodos do servidor; visitante só existe em build de demonstração', async () => {
    mockConfig({ entra: false, demo: true, demoUrl: 'javascript:alert(1)', superadmin: true })
    let m = await load(false)
    await m.auth.init()
    expect(m.authState.methods).toEqual({ entra: false, demo: false, demoUrl: null, superadmin: true, local: false })
    expect(m.authState.entraReady).toBe(false)
    expect(m.authState.entraError).toMatch(/não configurado/)
    await expect(m.auth.loginDemo('admin')).rejects.toThrow()

    mockConfig({ entra: false, demo: true, demoUrl: 'https://demo.tmsa.example', superadmin: false })
    m = await load(true)
    await m.auth.init()
    expect(m.authState.methods.demo).toBe(true)
    expect(m.authState.methods.demoUrl).toBe('https://demo.tmsa.example')

    mockConfig({ entra: false, demo: false, superadmin: false, local: true })
    m = await load(false)
    await m.auth.init()
    expect(m.authState.methods.local).toBe(true)
  })

  it('visitante usa token dev:<usuário> e sai sem resíduos', async () => {
    mockConfig({ demo: true })
    const { auth } = await load(true)
    await auth.init()
    expect(auth.isAuthenticated()).toBe(false)
    await auth.loginDemo('cq')
    expect(auth.method()).toBe('demo')
    expect(await auth.getToken()).toBe('dev:cq')
    await auth.logout()
    expect(auth.isAuthenticated()).toBe(false)
    expect(sessionStorage.length).toBe(0)
  })

  it('SuperAdmin guarda somente o token opaco (nunca a senha) e expira', async () => {
    mockConfig({ superadmin: true })
    const { auth } = await load(false)
    await auth.init()
    const expiresAt = new Date(Date.now() + 60_000).toISOString()
    await auth.startSuperAdmin({ token: 'bpmsa_abc', expiresAt, user: { name: 'SuperAdmin' } })
    expect(auth.method()).toBe('superadmin')
    expect(auth.isAuthenticated()).toBe(true)
    expect(await auth.getToken()).toBe('bpmsa_abc')
    expect(auth.superAdminSession()).toEqual({ expiresAt, name: 'SuperAdmin' })
    const stored = Object.keys(sessionStorage).map((k) => sessionStorage.getItem(k)).join('|')
    expect(stored).not.toMatch(/password|senha/i)

    // 401 da API: a sessão local deixa de valer
    auth.expireLocal()
    expect(auth.isAuthenticated()).toBe(false)

    // expiração absoluta também é respeitada no cliente
    await auth.startSuperAdmin({ token: 'bpmsa_old', expiresAt: new Date(Date.now() - 1000).toISOString(), user: { name: 'SuperAdmin' } })
    expect(auth.isAuthenticated()).toBe(false)
    expect(await auth.getToken()).toBeNull()
  })

  it('trocar de método descarta a sessão anterior', async () => {
    mockConfig({ demo: true, superadmin: true })
    const { auth } = await load(true)
    await auth.init()
    await auth.loginDemo('admin')
    await auth.startSuperAdmin({ token: 'bpmsa_x', expiresAt: new Date(Date.now() + 60_000).toISOString(), user: { name: 'SA' } })
    expect(sessionStorage.getItem('bpm.devUser')).toBeNull()
    expect(await auth.getToken()).toBe('bpmsa_x')
  })
})
