import { reactive } from 'vue'

/**
 * Autenticação do SPA — métodos simultâneos (ADR-0015). Quais métodos existem é decisão do servidor
 * (/public/auth-config) e é o servidor quem aceita ou recusa cada token; o SPA só guarda a sessão do
 * método escolhido nesta aba (sessionStorage).
 *  - entra:      MSAL Browser (Authorization Code + PKCE). Access token do scope da API; nunca o ID token.
 *                Nenhum client secret no frontend.
 *  - demo:       visitante com usuários fictícios ("Bearer dev:<username>"). Só existe em builds de
 *                desenvolvimento/demonstração; em produção o link aponta para um ambiente separado.
 *  - superadmin: usuário e senha enviados uma única vez a POST /auth/superadmin/login; guarda apenas o
 *                token opaco devolvido (expira). A senha nunca é armazenada.
 * O frontend nunca é fonte de verdade de papéis: a API valida o token e devolve permissões em /me.
 */
export type AuthMethod = 'entra' | 'demo' | 'superadmin' | 'local'

export interface AuthMethods { entra: boolean; demo: boolean; demoUrl: string | null; superadmin: boolean }

export interface SuperAdminSession { token: string; expiresAt: string; name: string }

const METHOD_KEY = 'bpm.auth.method'
const DEV_KEY = 'bpm.devUser'
const SA_KEY = 'bpm.superadmin'
const LOCAL_KEY = 'bpm.local'

/** Visitante embutido somente em builds de desenvolvimento/demonstração (VITE_AUTH_MODE=dev). */
export const demoBuild = import.meta.env.VITE_AUTH_MODE === 'dev'

if (demoBuild && import.meta.env.MODE === 'production') {
  // Build de produção não pode carregar o acesso de demonstração.
  throw new Error('VITE_AUTH_MODE=dev é proibido em build de produção.')
}

type MsalModule = typeof import('./msal')
let msal: MsalModule | null = null
async function loadMsal(): Promise<MsalModule> {
  msal ??= await import('./msal')
  return msal
}

/** Estado público (reativo) para a tela de login. */
export const authState = reactive({
  methods: { entra: false, demo: false, demoUrl: null, superadmin: false } as AuthMethods,
  /** MSAL inicializado (botão Microsoft utilizável) */
  entraReady: false,
  entraError: null as string | null,
  configLoaded: false,
})

interface PublicAuthConfig { entra: import('./msal').SpaAuthConfig | null; methods?: Partial<AuthMethods> }

async function publicConfig(): Promise<PublicAuthConfig | null> {
  try {
    const base = (import.meta.env.VITE_API_BASE_URL || '/api/v1').replace(/\/$/, '')
    const res = await fetch(`${base}/public/auth-config`, { headers: { Accept: 'application/json' } })
    if (!res.ok) return null
    return (await res.json()) as PublicAuthConfig
  } catch {
    return null
  }
}

/** Há parâmetros para o login Entra (configuração ativada na API ou variáveis do build)? O MSAL só é carregado se houver. */
function hasEntraConfig(runtime: import('./msal').SpaAuthConfig | null): boolean {
  return !!runtime?.clientId || (!!import.meta.env.VITE_ENTRA_CLIENT_ID && !!import.meta.env.VITE_ENTRA_TENANT_ID)
}

function currentMethod(): AuthMethod | null {
  const m = sessionStorage.getItem(METHOD_KEY)
  return m === 'entra' || m === 'superadmin' || m === 'local' || (m === 'demo' && demoBuild) ? m : null
}

function readSuperAdmin(): SuperAdminSession | null {
  try {
    const s = JSON.parse(sessionStorage.getItem(SA_KEY) ?? 'null') as SuperAdminSession | null
    return s?.token && Date.parse(s.expiresAt) > Date.now() ? s : null
  } catch {
    return null
  }
}

/** Remove as sessões locais de todos os métodos (a conta Entra é tratada à parte). */
function clearLocal(): void {
  for (const k of [METHOD_KEY, DEV_KEY, SA_KEY, LOCAL_KEY]) sessionStorage.removeItem(k)
}

async function switchTo(method: AuthMethod): Promise<void> {
  clearLocal()
  // ao entrar por outro método, esquece a conta Entra desta aba (sem sair da Microsoft)
  if (method !== 'entra' && msal) await msal.clearLocalAccount().catch(() => undefined)
  sessionStorage.setItem(METHOD_KEY, method)
}

export const auth = {
  async init(): Promise<void> {
    const cfg = await publicConfig()
    const m = cfg?.methods ?? {}
    authState.methods = {
      entra: m.entra ?? false,
      demo: demoBuild && (m.demo ?? false),
      demoUrl: typeof m.demoUrl === 'string' && /^https?:\/\//i.test(m.demoUrl) ? m.demoUrl : null,
      superadmin: m.superadmin ?? false,
    }
    authState.configLoaded = cfg !== null
    const runtime = cfg?.entra ?? null
    if (!hasEntraConfig(runtime)) {
      authState.entraError = authState.methods.entra
        ? 'Login Microsoft sem parâmetros do aplicativo (client ID/tenant). Ative a configuração em Configurações → Entra ID.'
        : 'Login Microsoft ainda não configurado neste ambiente.'
      return
    }
    try {
      // configuração pública ativada em Configurações → Entra ID (fallback: variáveis do build)
      if (await (await loadMsal()).initMsal(runtime)) {
        clearLocal()
        sessionStorage.setItem(METHOD_KEY, 'entra')
      }
      authState.entraReady = true
      authState.methods.entra = true
    } catch (e) {
      authState.entraError = 'Não foi possível iniciar o login Microsoft: ' + String((e as Error)?.message ?? e)
    }
  },

  /** Método do login desta aba (null = não autenticado). */
  method(): AuthMethod | null {
    const m = currentMethod()
    if (m) return m
    return msal?.hasAccount() ? 'entra' : null
  },

  isAuthenticated(): boolean {
    switch (this.method()) {
      case 'demo': return !!sessionStorage.getItem(DEV_KEY)
      case 'superadmin': return readSuperAdmin() !== null
      case 'entra': return msal?.hasAccount() ?? false
      default: return false
    }
  },

  async getToken(): Promise<string | null> {
    switch (this.method()) {
      case 'demo': {
        const user = sessionStorage.getItem(DEV_KEY)
        return user ? `dev:${user}` : null
      }
      case 'superadmin': return readSuperAdmin()?.token ?? null
      case 'local': return sessionStorage.getItem(LOCAL_KEY)
      case 'entra': return (await loadMsal()).acquireApiToken()
      default: return null
    }
  },

  async loginEntra(): Promise<void> {
    if (!authState.entraReady) throw new Error(authState.entraError ?? 'Login Microsoft indisponível.')
    clearLocal()
    sessionStorage.setItem(METHOD_KEY, 'entra')
    await (await loadMsal()).loginRedirect()
  },

  async loginDemo(username: string): Promise<void> {
    if (!demoBuild) throw new Error('Acesso de visitante indisponível nesta versão.')
    await switchTo('demo')
    sessionStorage.setItem(DEV_KEY, username)
  },

  /** Guarda a sessão devolvida pela API após o login SuperAdmin (somente o token opaco). */
  async startSuperAdmin(session: { token: string; expiresAt: string; user: { name: string } }): Promise<void> {
    await switchTo('superadmin')
    sessionStorage.setItem(SA_KEY, JSON.stringify({ token: session.token, expiresAt: session.expiresAt, name: session.user.name } satisfies SuperAdminSession))
  },
  async startLocal(session: { token: string }): Promise<void> {
    await switchTo('local')
    sessionStorage.setItem(LOCAL_KEY, session.token)
  },

  superAdminSession(): Omit<SuperAdminSession, 'token'> | null {
    const s = this.method() === 'superadmin' ? readSuperAdmin() : null
    return s ? { expiresAt: s.expiresAt, name: s.name } : null
  },

  /** 401 da API: sessões locais (visitante/SuperAdmin) deixam de valer; o Entra renova pelo MSAL. */
  expireLocal(): void {
    const m = currentMethod()
    if (m === 'demo' || m === 'superadmin') clearLocal()
  },

  /** Limpa a sessão local sem redirecionar (antes de um novo login). */
  async reset(): Promise<void> {
    clearLocal()
  },

  /** Teste de login da configuração candidata (Configurações → Entra ID). Funciona em qualquer método. */
  async testLogin(candidate: import('./msal').SpaAuthConfig): Promise<string> {
    return (await loadMsal()).testLogin(candidate)
  },

  async logout(): Promise<void> {
    const m = this.method()
    clearLocal()
    if (m === 'entra') await (await loadMsal()).logoutRedirect()
  },
}
