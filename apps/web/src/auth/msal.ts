import { InteractionRequiredAuthError, PublicClientApplication, type AccountInfo } from '@azure/msal-browser'

/** Parâmetros públicos do login (nenhum segredo). Vêm da configuração ativada na API ou do build. */
export interface SpaAuthConfig {
  clientId: string
  authority: string
  scopes: string[]
  postLogoutRedirectUri?: string | null
}

let pca: PublicClientApplication | null = null
let pcaClientId: string | null = null
let pcaAuthority: string | null = null
let account: AccountInfo | null = null
let scopeList: string[] = []
let testLoginInProgress = false

function buildConfig(runtime: SpaAuthConfig | null): SpaAuthConfig {
  if (runtime?.clientId) return runtime
  const clientId = import.meta.env.VITE_ENTRA_CLIENT_ID
  const tenant = import.meta.env.VITE_ENTRA_TENANT_ID
  if (!clientId || !tenant) {
    throw new Error('Login Entra ID não configurado: ative a configuração em Configurações → Entra ID ou defina VITE_ENTRA_CLIENT_ID/VITE_ENTRA_TENANT_ID.')
  }
  return {
    clientId,
    authority: `https://login.microsoftonline.com/${tenant}`,
    scopes: [import.meta.env.VITE_ENTRA_API_SCOPE || 'api://bpm-tmsa-api/access_as_user'],
  }
}

/** Inicializa o MSAL e trata o retorno do redirect. Retorna true quando um login acabou de ser concluído. */
export async function initMsal(runtime: SpaAuthConfig | null): Promise<boolean> {
  const cfg = buildConfig(runtime)
  scopeList = cfg.scopes
  pca = new PublicClientApplication({
    auth: {
      clientId: cfg.clientId,
      authority: cfg.authority,
      redirectUri: window.location.origin,
      postLogoutRedirectUri: cfg.postLogoutRedirectUri || window.location.origin,
    },
    cache: { cacheLocation: 'sessionStorage' },
  })
  pcaClientId = cfg.clientId
  pcaAuthority = cfg.authority
  await pca.initialize()
  const result = await pca.handleRedirectPromise()
  account = result?.account ?? pca.getAllAccounts()[0] ?? null
  if (account) pca.setActiveAccount(account)
  return !!result?.account
}

/** Esquece a conta Entra só neste navegador (ao entrar por outro método), sem sair da Microsoft. */
export async function clearLocalAccount(): Promise<void> {
  if (!pca || !account) return
  await pca.clearCache()
  account = null
}

export function hasAccount(): boolean {
  return !!account
}

export async function acquireApiToken(): Promise<string | null> {
  if (!pca || !account) return null
  try {
    // access token para o scope da API (nunca o ID token)
    const result = await pca.acquireTokenSilent({ scopes: scopeList, account })
    return result.accessToken
  } catch (e) {
    if (e instanceof InteractionRequiredAuthError) {
      await pca.acquireTokenRedirect({ scopes: scopeList, account })
    }
    return null
  }
}

export async function loginRedirect(): Promise<void> {
  await pca?.loginRedirect({ scopes: scopeList })
}

export async function logoutRedirect(): Promise<void> {
  await pca?.logoutRedirect({ account: account ?? undefined })
}

/**
 * Teste de login com a configuração CANDIDATA (antes de ativar): instância MSAL separada, popup,
 * access token do scope da API. O token é enviado só ao endpoint de teste e não é armazenado.
 */
export async function testLogin(candidate: SpaAuthConfig): Promise<string> {
  if (testLoginInProgress) {
    throw new Error('Já existe uma tentativa de login em andamento. Aguarde a janela de autenticação fechar e tente novamente.')
  }

  testLoginInProgress = true
  try {
    // Reutilize a instância da aplicação quando ela já usa o mesmo registro. Duas instâncias
    // MSAL com o mesmo client ID compartilham o estado temporário de interação no navegador.
    const sameApplication = pca && pcaClientId === candidate.clientId && pcaAuthority === candidate.authority
    const test = sameApplication ? pca! : new PublicClientApplication({
      auth: { clientId: candidate.clientId, authority: candidate.authority, redirectUri: `${window.location.origin}/redirect.html` },
      cache: { cacheLocation: 'memoryStorage' },
    })
    if (!sameApplication) {
      await test.initialize()
      // Resolve qualquer resposta pendente antes de iniciar uma nova interação MSAL.
      await test.handleRedirectPromise()
    }
    const login = await test.loginPopup({ scopes: candidate.scopes, prompt: 'select_account', redirectUri: `${window.location.origin}/redirect.html` })
    if (login.accessToken) return login.accessToken
    const token = await test.acquireTokenSilent({ scopes: candidate.scopes, account: login.account })
    return token.accessToken
  } finally {
    testLoginInProgress = false
  }
}
