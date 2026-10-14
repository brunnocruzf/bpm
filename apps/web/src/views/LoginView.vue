<script setup lang="ts">
/**
 * Tela de acesso (protótipo .claude/login): login corporativo Microsoft Entra ID e dois acessos
 * alternativos — visitante (ambiente de demonstração, usuários fictícios) e SuperAdmin (usuário e senha,
 * emergência). Quais métodos aparecem é decisão do servidor (/public/auth-config); cores, logo e links
 * vêm da identidade visual configurada.
 */
import '@fontsource-variable/inter'
import '@fontsource-variable/manrope'
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError, errorMessage } from '@/api/http'
import { auth, authState } from '@/auth'
import { useSession } from '@/stores/session'
import { useBranding } from '@/stores/branding'
import { contrastRatio, lighten } from '@/composables/contrast'

interface DevUser { username: string; name: string; area: string | null; roles: string[]; groups: string[] }
interface SuperAdminLoginResponse { token: string; expiresAt: string; idleMinutes: number; user: { name: string; username: string } }

const props = defineProps<{ mode: 'entra' | 'demo' | 'superadmin' | 'local' | 'activate' | 'reset' }>()

const route = useRoute()
const router = useRouter()
const session = useSession()
const brand = useBranding()

const error = ref('')
const loading = ref(false)
const year = new Date().getFullYear()

// somente caminhos internos (evita redirecionamento aberto)
const redirect = computed(() => {
  const r = route.query.redirect
  return typeof r === 'string' && r.startsWith('/') && !r.startsWith('//') && !r.startsWith('/login') ? r : '/'
})
const keepQuery = computed(() => (redirect.value !== '/' ? { redirect: redirect.value } : {}))

const methods = computed(() => authState.methods)
const showDemo = computed(() => methods.value.demo || !!methods.value.demoUrl)
const hasAlternatives = computed(() => showDemo.value || methods.value.superadmin || methods.value.local)
const links = computed(() => brand.branding.links ?? { support: null, privacy: null, help: null })

/** Destaque do título sobre a foto: tom claro da marca com contraste garantido (fallback: pêssego do protótipo). */
const heroHighlight = computed(() => {
  const { primary, accent, secondary } = brand.branding.colors
  for (const c of [primary, accent, secondary]) {
    const tint = lighten(c, 0.45)
    const ch = [1, 3, 5].map((i) => parseInt(tint.slice(i, i + 2), 16))
    if (Math.max(...ch) - Math.min(...ch) >= 48 && contrastRatio(tint, '#0f2328') >= 4.5) return tint
  }
  return '#ffc08e'
})

async function finish() {
  await session.load()
  await router.replace(redirect.value)
}

// ---------------------------------------------------------------- Microsoft Entra ID
async function enterEntra() {
  error.value = ''
  loading.value = true
  try {
    await auth.loginEntra() // redireciona para a Microsoft
  } catch (e) {
    error.value = errorMessage(e)
    loading.value = false
  }
}

// ---------------------------------------------------------------- Visitante (demonstração)
const demoUsers = ref<DevUser[]>([])
const demoGroups = computed(() => [
  { title: 'Administração e gestão', users: demoUsers.value.filter((u) => !u.groups.length) },
  { title: 'Raias do PO 159', users: demoUsers.value.filter((u) => u.groups.length) },
].filter((g) => g.users.length))
const roleLabel = (r: string) => r.replace('Bpm.', '')

async function loadDemoUsers() {
  if (!methods.value.demo || demoUsers.value.length) return
  try {
    demoUsers.value = await api<DevUser[]>('/dev/users')
  } catch (e) {
    error.value = 'Não foi possível carregar os perfis de demonstração. ' + errorMessage(e)
  }
}

async function enterDemo(username: string) {
  error.value = ''
  loading.value = true
  try {
    await auth.loginDemo(username)
    await finish()
  } catch (e) {
    error.value = errorMessage(e)
    await auth.reset()
  } finally {
    loading.value = false
  }
}

// ---------------------------------------------------------------- SuperAdmin (emergência)
const saUser = ref('')
const saPassword = ref('')
const showPassword = ref(false)
const capsLock = ref(false)
const fieldErrors = ref<{ username?: string; password?: string }>({})
const userInput = ref<HTMLInputElement>()

function trackCaps(e: KeyboardEvent) {
  capsLock.value = e.getModifierState?.('CapsLock') ?? false
}

async function enterSuperAdmin() {
  error.value = ''
  fieldErrors.value = {
    username: saUser.value.trim() ? undefined : 'Informe o usuário.',
    password: saPassword.value ? undefined : 'Informe a senha.',
  }
  if (fieldErrors.value.username || fieldErrors.value.password) return
  loading.value = true
  const password = saPassword.value
  saPassword.value = '' // a senha não permanece na tela nem é armazenada
  try {
    await auth.reset()
    const res = await api<SuperAdminLoginResponse>('/auth/superadmin/login', { method: 'POST', body: { username: saUser.value.trim(), password } })
    await auth.startSuperAdmin(res)
    await finish()
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) error.value = 'Acesso SuperAdmin indisponível neste ambiente ou a partir desta rede.'
    else error.value = errorMessage(e)
    await auth.reset()
  } finally {
    loading.value = false
  }
}

const localUser = ref('')
const localPassword = ref('')
const localPasswordConfirm = ref('')
const localMessage = ref('')
const forgotLocal = ref(false)
async function enterLocal() {
  error.value = ''; loading.value = true
  try {
    await api('/auth/local/login', { method: 'POST', body: { username: localUser.value.trim(), password: localPassword.value } })
    localPassword.value = ''; await auth.startLocal(); await finish()
  } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
async function requestLocalReset() {
  error.value = ''; localMessage.value = ''; loading.value = true
  try {
    const res = await api<{ message: string }>('/auth/local/forgot-password', { method: 'POST', body: { username: localUser.value.trim() } })
    localMessage.value = res.message
  } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
async function submitLocalCredentialLink() {
  error.value = ''; localMessage.value = ''
  if (localPassword.value !== localPasswordConfirm.value) { error.value = 'As senhas não coincidem.'; return }
  loading.value = true
  try {
    const token = typeof route.query.token === 'string' ? route.query.token : ''
    const path = props.mode === 'activate' ? '/auth/local/activate' : '/auth/local/reset-password'
    const res = await api<{ message: string }>(path, { method: 'POST', body: { token, password: localPassword.value } })
    localPassword.value = ''; localPasswordConfirm.value = ''; localMessage.value = res.message
    await router.replace({ name: 'login-local', query: keepQuery.value })
  } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}

watch(() => props.mode, async (mode) => {
  error.value = ''
  if (mode === 'demo') await loadDemoUsers()
  if (mode === 'superadmin') {
    await nextTick()
    userInput.value?.focus()
  }
})

onMounted(async () => {
  if (props.mode === 'entra' && auth.isAuthenticated()) {
    try {
      await finish()
      return
    } catch (e) {
      error.value = errorMessage(e)
      await auth.reset()
    }
  }
  if (props.mode === 'demo') await loadDemoUsers()
  if (props.mode === 'superadmin') userInput.value?.focus()
})
</script>

<template>
  <div class="lg-root" :style="{ '--lg-highlight': heroHighlight }">
    <a class="lg-skip" href="#lg-conteudo">Pular para o acesso</a>
    <main class="lg-layout" aria-label="Acesso ao sistema">
      <section class="lg-panel" aria-labelledby="lg-title">
        <div class="lg-brand">
          <img v-if="brand.branding.assets.main" :src="brand.branding.assets.main" :alt="`${brand.branding.orgName} — ${brand.branding.appName}`" />
          <div v-else class="lg-brand-fallback">
            <img v-if="brand.branding.assets.compact" :src="brand.branding.assets.compact" alt="" class="lg-brand-compact" />
            <span v-else class="lg-brand-mark" aria-hidden="true">{{ brand.initials }}</span>
            <span class="lg-brand-name">{{ brand.branding.appName }}</span>
          </div>
          <span v-if="brand.branding.tagline" class="lg-brand-tagline">{{ brand.branding.tagline }}</span>
        </div>

        <div id="lg-conteudo" class="lg-content" :class="{ wide: mode === 'demo' }">
          <!-- ======================== Login corporativo (Microsoft Entra ID) ======================== -->
          <template v-if="mode === 'local'">
            <RouterLink class="lg-back" :to="{ name: 'login', query: keepQuery }"><i class="pi pi-arrow-left" aria-hidden="true" /> Voltar ao login corporativo</RouterLink>
            <p class="lg-eyebrow">Conta local</p><h1 id="lg-title">Entrar com usuário local</h1>
            <p class="lg-intro">Conta fornecida pelo administrador. Não há cadastro público.</p>
            <form class="lg-form" @submit.prevent="forgotLocal ? requestLocalReset() : enterLocal()">
              <label for="local-user">Usuário ou e-mail</label><input id="local-user" v-model="localUser" class="lg-input" autocomplete="username" required />
              <template v-if="!forgotLocal"><label for="local-password">Senha</label><div class="lg-pass"><input id="local-password" v-model="localPassword" class="lg-input" :type="showPassword ? 'text' : 'password'" autocomplete="current-password" required /><button type="button" class="lg-pass-toggle" :aria-pressed="showPassword" :aria-label="showPassword ? 'Ocultar senha' : 'Mostrar senha'" @click="showPassword = !showPassword"><i :class="showPassword ? 'pi pi-eye-slash' : 'pi pi-eye'" aria-hidden="true" /></button></div></template>
              <button class="lg-submit" type="submit" :disabled="loading">{{ loading ? 'Aguarde…' : forgotLocal ? 'Enviar instruções' : 'Entrar' }}</button>
            </form>
            <p v-if="localMessage" class="lg-secure" role="status">{{ localMessage }}</p>
            <button v-if="!forgotLocal" type="button" class="lg-link-button" @click="forgotLocal = true">Esqueci minha senha</button><button v-else type="button" class="lg-link-button" @click="forgotLocal = false">Voltar ao login local</button>
          </template>
          <template v-else-if="mode === 'activate' || mode === 'reset'">
            <RouterLink class="lg-back" :to="{ name: 'login-local', query: keepQuery }"><i class="pi pi-arrow-left" aria-hidden="true" /> Voltar ao login local</RouterLink>
            <p class="lg-eyebrow">{{ mode === 'activate' ? 'Ativação segura' : 'Recuperação de acesso' }}</p><h1 id="lg-title">{{ mode === 'activate' ? 'Defina sua senha' : 'Redefina sua senha' }}</h1>
            <p class="lg-intro">Use uma senha forte com pelo menos 14 caracteres.</p>
            <form class="lg-form" @submit.prevent="submitLocalCredentialLink"><label for="credential-password">Nova senha</label><input id="credential-password" v-model="localPassword" class="lg-input" type="password" autocomplete="new-password" minlength="14" required /><label for="credential-confirm">Confirme a senha</label><input id="credential-confirm" v-model="localPasswordConfirm" class="lg-input" type="password" autocomplete="new-password" minlength="14" required /><button class="lg-submit" type="submit" :disabled="loading">{{ loading ? 'Salvando…' : 'Salvar senha' }}</button></form>
            <p v-if="localMessage" class="lg-secure" role="status">{{ localMessage }}</p>
          </template>
          <template v-else-if="mode === 'entra'">
            <p class="lg-eyebrow">Plataforma de processos</p>
            <h1 id="lg-title">Bem-vindo ao {{ brand.branding.appName }}</h1>
            <p class="lg-intro">Acesse seus fluxos, etapas e formulários em um só lugar.</p>

            <button class="lg-ms-btn" type="button" :disabled="loading || !authState.entraReady" aria-describedby="lg-secure" @click="enterEntra">
              <span class="lg-ms-mark" aria-hidden="true"><span /><span /><span /><span /></span>
              <span>{{ loading ? 'Redirecionando para a Microsoft…' : 'Entrar com Microsoft' }}</span>
            </button>
            <p v-if="!authState.entraReady" class="lg-unavailable" role="note">
              <i class="pi pi-info-circle" aria-hidden="true" />
              <span>{{ authState.entraError || 'Login Microsoft indisponível.' }}<template v-if="methods.superadmin"> Um administrador pode configurá-lo pelo acesso SuperAdmin.</template></span>
            </p>
            <p id="lg-secure" class="lg-secure">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M7 10V7a5 5 0 0 1 10 0v3M6.5 10h11A1.5 1.5 0 0 1 19 11.5v8a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 19.5v-8A1.5 1.5 0 0 1 6.5 10Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                <path d="M12 14v3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
              </svg>
              <span>Acesso protegido pela conta corporativa da sua organização. Sua senha é validada pela Microsoft.</span>
            </p>

            <nav v-if="hasAlternatives" class="lg-alt" aria-label="Outras formas de acesso">
              <div class="lg-divider"><span>Outras formas de acesso</span></div>
              <RouterLink v-if="methods.demo" class="lg-alt-link" :to="{ name: 'login-demo', query: keepQuery }">
                <i class="pi pi-users" aria-hidden="true" />
                <span><strong>Acessar como visitante</strong><small>Ambiente de demonstração com usuários e dados fictícios</small></span>
                <i class="pi pi-angle-right lg-alt-go" aria-hidden="true" />
              </RouterLink>
              <a v-else-if="methods.demoUrl" class="lg-alt-link" :href="methods.demoUrl" rel="noopener">
                <i class="pi pi-users" aria-hidden="true" />
                <span><strong>Acessar como visitante</strong><small>Abre o ambiente de demonstração (dados fictícios)</small></span>
                <i class="pi pi-external-link lg-alt-go" aria-hidden="true" />
              </a>
              <RouterLink v-if="methods.superadmin" class="lg-alt-link" :to="{ name: 'login-superadmin', query: keepQuery }">
                <i class="pi pi-key" aria-hidden="true" />
                <span><strong>Acesso SuperAdmin</strong><small>Usuário e senha — somente para emergências</small></span>
                <i class="pi pi-angle-right lg-alt-go" aria-hidden="true" />
              </RouterLink>
              <RouterLink v-if="methods.local" class="lg-alt-link" :to="{ name: 'login-local', query: keepQuery }"><i class="pi pi-user" aria-hidden="true" /><span><strong>Entrar com usuário local</strong><small>Use uma conta fornecida pelo administrador</small></span><i class="pi pi-angle-right lg-alt-go" aria-hidden="true" /></RouterLink>
            </nav>

            <p v-if="links.support" class="lg-help">Não consegue entrar? <a :href="links.support" rel="noopener" target="_blank">Fale com o suporte</a></p>
          </template>

          <!-- ======================== Visitante (ambiente de demonstração) ======================== -->
          <template v-else-if="mode === 'demo'">
            <RouterLink class="lg-back" :to="{ name: 'login', query: keepQuery }"><i class="pi pi-arrow-left" aria-hidden="true" /> Voltar ao login</RouterLink>
            <p class="lg-eyebrow">Ambiente de demonstração</p>
            <h1 id="lg-title">Acesso de visitante</h1>
            <template v-if="methods.demo">
              <p class="lg-intro">Escolha um perfil fictício. Cada raia do PO 159 tem um usuário próprio para executar as etapas do fluxo.</p>
              <p class="lg-warn" role="note"><i class="pi pi-exclamation-triangle" aria-hidden="true" /><span><strong>Usuários e dados fictícios.</strong> Não insira informações reais. Em produção o acesso é exclusivamente pelo Microsoft Entra ID.</span></p>
              <div v-for="g in demoGroups" :key="g.title" class="lg-demo-group">
                <h2>{{ g.title }}</h2>
                <ul class="lg-demo-list">
                  <li v-for="u in g.users" :key="u.username">
                    <button type="button" class="lg-demo-card" :disabled="loading" @click="enterDemo(u.username)">
                      <span class="lg-demo-name">{{ u.name }}</span>
                      <span class="lg-demo-area">{{ u.area }}</span>
                      <span class="lg-demo-pills">
                        <span v-for="gr in u.groups" :key="gr" class="tm-pill brand">{{ gr }}</span>
                        <span v-for="r in u.roles" :key="r" class="tm-pill neutral">{{ roleLabel(r) }}</span>
                      </span>
                    </button>
                  </li>
                </ul>
              </div>
              <p v-if="!demoUsers.length && !error" class="lg-intro">Carregando perfis…</p>
            </template>
            <template v-else>
              <p class="lg-intro">O acesso de visitante não está habilitado neste ambiente.</p>
              <a v-if="methods.demoUrl" class="lg-ms-btn" :href="methods.demoUrl" rel="noopener"><i class="pi pi-external-link" aria-hidden="true" /> Abrir o ambiente de demonstração</a>
            </template>
          </template>

          <!-- ======================== SuperAdmin (usuário e senha, emergência) ======================== -->
          <template v-else>
            <RouterLink class="lg-back" :to="{ name: 'login', query: keepQuery }"><i class="pi pi-arrow-left" aria-hidden="true" /> Voltar ao login</RouterLink>
            <p class="lg-eyebrow">Acesso de emergência</p>
            <h1 id="lg-title">SuperAdmin</h1>
            <template v-if="methods.superadmin">
              <p class="lg-intro">Use somente quando o login corporativo estiver indisponível ou para configurá-lo.</p>
              <form class="lg-form" novalidate @submit.prevent="enterSuperAdmin">
                <label for="lg-user">Usuário</label>
                <input id="lg-user" ref="userInput" v-model="saUser" class="lg-input" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="120"
                  :aria-invalid="!!fieldErrors.username" :aria-describedby="fieldErrors.username ? 'lg-user-err' : undefined" />
                <span v-if="fieldErrors.username" id="lg-user-err" class="lg-field-err">{{ fieldErrors.username }}</span>

                <label for="lg-pass">Senha</label>
                <div class="lg-pass">
                  <input id="lg-pass" v-model="saPassword" class="lg-input" :type="showPassword ? 'text' : 'password'" name="password" autocomplete="current-password" maxlength="256"
                    :aria-invalid="!!fieldErrors.password" :aria-describedby="[fieldErrors.password ? 'lg-pass-err' : '', capsLock ? 'lg-caps' : ''].filter(Boolean).join(' ') || undefined"
                    @keyup="trackCaps" @keydown="trackCaps" />
                  <button type="button" class="lg-pass-toggle" :aria-pressed="showPassword" :aria-label="showPassword ? 'Ocultar senha' : 'Mostrar senha'" @click="showPassword = !showPassword">
                    <i :class="showPassword ? 'pi pi-eye-slash' : 'pi pi-eye'" aria-hidden="true" />
                  </button>
                </div>
                <span v-if="fieldErrors.password" id="lg-pass-err" class="lg-field-err">{{ fieldErrors.password }}</span>
                <span v-if="capsLock" id="lg-caps" class="lg-caps"><i class="pi pi-exclamation-circle" aria-hidden="true" /> Caps Lock ativado</span>

                <button class="lg-submit" type="submit" :disabled="loading">{{ loading ? 'Entrando…' : 'Entrar' }}</button>
              </form>
              <p class="lg-secure">
                <i class="pi pi-shield" aria-hidden="true" />
                <span>Tentativas são limitadas e auditadas; após falhas consecutivas o acesso é bloqueado temporariamente. A sessão expira por inatividade.</span>
              </p>
            </template>
            <p v-else class="lg-intro">O acesso SuperAdmin está desabilitado neste ambiente ou não é permitido a partir desta rede.</p>
          </template>

          <p v-if="error" class="lg-error" role="alert"><i class="pi pi-times-circle" aria-hidden="true" /><span>{{ error }}</span></p>
        </div>

        <footer class="lg-footer">
          <span>© {{ year }} {{ brand.branding.orgName }}</span>
          <nav v-if="links.privacy || links.help" class="lg-footer-links" aria-label="Links institucionais">
            <a v-if="links.privacy" :href="links.privacy" rel="noopener" target="_blank">Privacidade</a>
            <a v-if="links.help" :href="links.help" rel="noopener" target="_blank">Ajuda</a>
          </nav>
        </footer>
      </section>

      <section class="lg-hero" aria-label="Colaboração e gestão de processos">
        <div class="lg-hero-content">
          <div class="lg-hero-label"><i aria-hidden="true" /> {{ brand.branding.appName }} · Processos conectados</div>
          <h2>Processos claros.<br /><span>Trabalho em movimento.</span></h2>
          <p class="lg-hero-desc">Da solicitação à conclusão, cada etapa tem seu lugar, responsável e informação — para o trabalho avançar com mais clareza.</p>
          <div class="lg-process" aria-label="Exemplo de um processo dividido em etapas">
            <span class="lg-process-icon" aria-hidden="true">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                <rect x="3.5" y="4" width="7" height="6" rx="1.4" stroke="currentColor" stroke-width="1.6" />
                <rect x="13.5" y="14" width="7" height="6" rx="1.4" stroke="currentColor" stroke-width="1.6" />
                <path d="M10.5 7h2.2a2 2 0 0 1 2 2v5M13.5 17h-2.2a2 2 0 0 1-2-2v-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
              </svg>
            </span>
            <span>
              <span class="lg-process-title">Um fluxo, etapas conectadas</span>
              <span class="lg-process-flow"><span>Solicitação</span><b>→</b><span>Análise</span><b>→</b><span>Conclusão</span></span>
            </span>
          </div>
        </div>
        <a class="lg-credit" href="https://unsplash.com/photos/diverse-team-collaborating-in-modern-office-xsQPWxhp2tc" target="_blank" rel="noreferrer">Foto: Vitaly Gariev / Unsplash</a>
      </section>
    </main>
  </div>
</template>

<style scoped>
.lg-root {
  --lg-ink: #191a1d;
  --lg-muted: #686d75;
  --lg-line: #e6e7e9;
  --lg-body: 'Inter Variable', Inter, 'Segoe UI', Arial, sans-serif;
  --lg-display: 'Manrope Variable', 'Inter Variable', 'Segoe UI', sans-serif;
  min-height: 100vh;
  min-height: 100svh;
  background: #f7f7f6;
  color: var(--lg-ink);
  font-family: var(--lg-body);
  -webkit-font-smoothing: antialiased;
}
.lg-root :focus-visible { outline: 3px solid var(--focus-ring); outline-offset: 3px; }
.lg-skip {
  position: fixed; z-index: 10; top: 12px; left: 12px; transform: translateY(-160%);
  padding: 10px 14px; border-radius: 8px; background: #fff; color: var(--lg-ink); box-shadow: 0 4px 18px #0002;
}
.lg-skip:focus { transform: translateY(0); }

.lg-layout { display: grid; grid-template-columns: minmax(440px, 43%) minmax(0, 1fr); min-height: 100vh; min-height: 100svh; }

/* ------------------------------------------------------------ painel de acesso */
.lg-panel {
  display: flex; flex-direction: column; align-items: center; justify-content: space-between;
  padding: clamp(28px, 5.5vh, 64px) clamp(28px, 5.3vw, 84px) 24px;
  background: radial-gradient(ellipse at 0% 0%, color-mix(in srgb, var(--brand) 6%, transparent), transparent 34%), #fff;
}
.lg-brand { display: flex; width: 100%; flex-direction: column; align-items: center; justify-content: center; min-height: 58px; gap: 4px; }
.lg-brand > img { display: block; width: min(100%, 330px); height: auto; max-height: 76px; object-fit: contain; }
.lg-brand-fallback { display: flex; align-items: center; gap: 12px; }
.lg-brand-compact { width: 48px; height: 48px; object-fit: contain; }
.lg-brand-mark {
  display: grid; width: 48px; height: 48px; place-items: center; border-radius: 12px;
  background: var(--brand); color: var(--on-brand); font: 800 15px/1 var(--lg-display); letter-spacing: -0.02em;
}
.lg-brand-name { font: 700 28px/1.1 var(--lg-display); letter-spacing: -0.04em; color: var(--lg-ink); }
.lg-brand-tagline { color: var(--brand-text); font-size: 9px; font-weight: 700; letter-spacing: 0.25em; line-height: 1.2; text-transform: uppercase; }

.lg-content { width: min(100%, 390px); margin: auto 0; padding: 52px 0 46px; }
.lg-content.wide { width: min(100%, 540px); }
.lg-eyebrow {
  display: inline-flex; align-items: center; gap: 8px; margin: 0 0 18px;
  color: var(--brand-text); font-size: 11px; font-weight: 700; letter-spacing: 0.13em; line-height: 1.4; text-transform: uppercase;
}
.lg-eyebrow::before {
  width: 7px; height: 7px; border-radius: 50%; background: var(--brand); content: '';
  box-shadow: 0 0 0 4px color-mix(in srgb, var(--brand) 14%, transparent);
}
.lg-content h1 { margin: 0; font-family: var(--lg-display); font-size: clamp(30px, 3vw, 38px); font-weight: 700; letter-spacing: -0.045em; line-height: 1.15; }
.lg-intro { margin: 15px 0 28px; color: var(--lg-muted); font-size: 15px; line-height: 1.7; }

.lg-ms-btn {
  display: flex; width: 100%; min-height: 56px; align-items: center; justify-content: center; gap: 12px; padding: 0 18px;
  border: 1px solid #d9dbde; border-radius: 10px; background: #fff; color: #202124; cursor: pointer;
  font: 600 14px/1.2 var(--lg-body); text-decoration: none; box-shadow: 0 2px 4px rgba(24, 31, 39, 0.035);
  transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease, background 0.18s ease;
}
.lg-ms-btn:hover:not(:disabled) { border-color: #b9bdc2; background: #fcfcfc; box-shadow: 0 8px 22px rgba(24, 31, 39, 0.09); transform: translateY(-1px); }
.lg-ms-btn:disabled { cursor: not-allowed; opacity: 0.6; }
.lg-ms-mark { display: grid; width: 19px; height: 19px; flex: 0 0 auto; grid-template-columns: repeat(2, 1fr); grid-template-rows: repeat(2, 1fr); gap: 2px; }
.lg-ms-mark span:nth-child(1) { background: #f35325; }
.lg-ms-mark span:nth-child(2) { background: #81bc06; }
.lg-ms-mark span:nth-child(3) { background: #05a6f0; }
.lg-ms-mark span:nth-child(4) { background: #ffba08; }

.lg-secure { display: flex; align-items: flex-start; gap: 10px; margin: 17px 2px 0; color: #6f747b; font-size: 12px; line-height: 1.55; }
.lg-secure svg, .lg-secure .pi { flex: 0 0 auto; margin-top: 2px; color: #6a747c; font-size: 14px; }
.lg-unavailable { display: flex; gap: 8px; align-items: flex-start; margin: 12px 2px 0; color: #7d5418; font-size: 12px; line-height: 1.5; }
.lg-unavailable .pi { margin-top: 2px; }

.lg-alt { margin-top: 28px; }
.lg-divider { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; color: #8a8e94; font-size: 11px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; }
.lg-divider::before, .lg-divider::after { flex: 1; height: 1px; background: var(--lg-line); content: ''; }
.lg-alt-link {
  display: flex; align-items: center; gap: 12px; padding: 11px 12px; margin-top: 8px;
  border: 1px solid var(--lg-line); border-radius: 10px; color: var(--lg-ink); text-decoration: none; background: #fff;
  transition: border-color 0.15s ease, background 0.15s ease;
}
.lg-alt-link:hover { border-color: color-mix(in srgb, var(--brand) 35%, #fff); background: color-mix(in srgb, var(--brand) 4%, #fff); }
.lg-alt-link > .pi:first-child {
  display: grid; width: 32px; height: 32px; flex: 0 0 auto; place-items: center; border-radius: 8px;
  background: color-mix(in srgb, var(--brand) 10%, #fff); color: var(--brand-text); font-size: 14px;
}
.lg-alt-link strong { display: block; font-size: 13px; font-weight: 600; }
.lg-alt-link small { display: block; margin-top: 1px; color: var(--lg-muted); font-size: 12px; line-height: 1.4; }
.lg-alt-go { margin-left: auto; color: #9aa0a6; font-size: 13px; }

.lg-help { margin: 26px 0 0; color: var(--lg-muted); font-size: 13px; line-height: 1.6; }
.lg-help a { color: var(--brand-text); font-weight: 600; text-decoration: underline; text-decoration-color: color-mix(in srgb, var(--brand-text) 35%, transparent); text-underline-offset: 3px; }
.lg-link-button { display: block; width: fit-content; margin: 14px auto 0; padding: 4px; border: 0; background: none; color: var(--brand-text); cursor: pointer; font: 600 13px var(--lg-body); text-decoration: underline; text-underline-offset: 3px; }
.lg-link-button:focus-visible { outline: 3px solid var(--focus-ring); outline-offset: 3px; }

.lg-back { display: flex; width: fit-content; align-items: center; gap: 6px; margin-bottom: 26px; color: var(--lg-muted); font-size: 13px; font-weight: 600; text-decoration: none; }
.lg-back:hover { color: var(--brand-text); }

.lg-warn, .lg-error { display: flex; gap: 10px; align-items: flex-start; margin: 0 0 18px; padding: 11px 13px; border-radius: 9px; font-size: 12px; line-height: 1.5; }
.lg-warn { background: var(--amber-soft); color: #6b4512; }
.lg-error { margin: 18px 0 0; background: var(--red-soft); color: #87413a; }
.lg-warn .pi, .lg-error .pi { margin-top: 2px; }

.lg-demo-group h2 { margin: 18px 0 8px; color: var(--lg-muted); font-size: 11px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; }
.lg-demo-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 8px; margin: 0; padding: 0; list-style: none; }
.lg-demo-card {
  display: flex; width: 100%; height: 100%; flex-direction: column; align-items: flex-start; gap: 2px; padding: 10px 12px;
  border: 1px solid var(--lg-line); border-radius: 10px; background: #fff; color: var(--lg-ink); cursor: pointer; text-align: left; font-family: inherit;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.lg-demo-card:hover:not(:disabled) { border-color: color-mix(in srgb, var(--brand) 40%, #fff); box-shadow: 0 6px 16px rgba(24, 31, 39, 0.07); }
.lg-demo-name { font-size: 13px; font-weight: 600; }
.lg-demo-area { color: var(--lg-muted); font-size: 11px; }
.lg-demo-pills { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }

.lg-form { display: flex; flex-direction: column; }
.lg-form label { margin: 14px 0 6px; font-size: 13px; font-weight: 600; }
.lg-form label:first-child { margin-top: 0; }
.lg-input {
  width: 100%; height: 46px; padding: 0 14px; border: 1px solid #cfd3d8; border-radius: 9px; background: #fff;
  color: var(--lg-ink); font: 400 14px var(--lg-body);
}
.lg-input:focus { border-color: var(--accent); outline: none; box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 22%, transparent); }
.lg-input[aria-invalid='true'] { border-color: var(--red); }
.lg-pass { position: relative; }
.lg-pass .lg-input { padding-right: 46px; }
.lg-pass-toggle { position: absolute; top: 5px; right: 5px; width: 36px; height: 36px; border: 0; border-radius: 7px; background: none; color: #5f656d; cursor: pointer; }
.lg-pass-toggle:hover { background: #f1f2f4; }
.lg-field-err { margin-top: 5px; color: var(--red); font-size: 12px; }
.lg-caps { display: inline-flex; gap: 6px; align-items: center; margin-top: 6px; color: #7d5418; font-size: 12px; }
.lg-submit {
  min-height: 48px; margin-top: 22px; border: 0; border-radius: 10px; background: var(--brand); color: var(--on-brand);
  cursor: pointer; font: 600 14px var(--lg-body); transition: background 0.15s ease;
}
.lg-submit:hover:not(:disabled) { background: var(--brand-hover); }
.lg-submit:disabled { cursor: progress; opacity: 0.7; }

.lg-footer { display: flex; width: 100%; align-items: center; justify-content: space-between; gap: 16px; color: #80858b; font-size: 11px; line-height: 1.5; }
.lg-footer-links { display: flex; gap: 16px; }
.lg-footer-links a { color: inherit; text-decoration: none; }
.lg-footer-links a:hover { color: #444a50; text-decoration: underline; }

/* ------------------------------------------------------------ painel visual (foto) */
.lg-hero {
  /* fixo na rolagem: a lista de perfis do visitante não estica a foto */
  position: sticky; top: 0; display: flex; height: 100vh; height: 100svh; align-self: start; align-items: flex-end; overflow: hidden;
  padding: clamp(40px, 7vw, 104px); isolation: isolate;
  background-color: #263c3e;
  background-image:
    linear-gradient(90deg, rgba(15, 35, 40, 0.69) 0%, rgba(15, 35, 40, 0.34) 47%, rgba(15, 35, 40, 0.08) 100%),
    linear-gradient(0deg, rgba(9, 22, 25, 0.62) 0%, rgba(9, 22, 25, 0.04) 58%),
    url('../assets/login/equipe-colaboracao-1920.jpg');
  background-position: center; background-size: cover;
}
.lg-hero::after {
  position: absolute; z-index: -1; inset: 0; pointer-events: none; content: '';
  background: linear-gradient(135deg, color-mix(in srgb, var(--brand) 14%, transparent), transparent 36%);
}
.lg-hero-content { width: min(100%, 720px); color: #fff; }
.lg-hero-label {
  display: inline-flex; align-items: center; gap: 9px; margin-bottom: 21px; padding: 9px 12px;
  border: 1px solid rgba(255, 255, 255, 0.29); border-radius: 999px; background: rgba(13, 29, 32, 0.34);
  color: rgba(255, 255, 255, 0.94); font-size: 10px; font-weight: 700; letter-spacing: 0.13em; text-transform: uppercase; backdrop-filter: blur(8px);
}
.lg-hero-label i { width: 7px; height: 7px; border-radius: 50%; background: var(--lg-highlight); box-shadow: 0 0 0 4px color-mix(in srgb, var(--lg-highlight) 18%, transparent); }
.lg-hero h2 { max-width: 660px; margin: 0; font-family: var(--lg-display); font-size: clamp(36px, 5vw, 64px); font-weight: 700; letter-spacing: -0.055em; line-height: 1.07; text-wrap: balance; }
.lg-hero h2 span { color: var(--lg-highlight); }
.lg-hero-desc { max-width: 540px; margin: 20px 0 28px; color: rgba(255, 255, 255, 0.85); font-size: 15px; line-height: 1.72; }
.lg-process {
  display: flex; width: min(100%, 460px); align-items: center; gap: 12px; padding: 14px 16px;
  border: 1px solid rgba(255, 255, 255, 0.23); border-radius: 12px; background: rgba(17, 35, 38, 0.45);
  backdrop-filter: blur(12px); box-shadow: 0 12px 40px rgba(0, 0, 0, 0.12);
}
.lg-process-icon { display: grid; width: 38px; height: 38px; flex: 0 0 auto; place-items: center; border-radius: 10px; background: color-mix(in srgb, var(--lg-highlight) 22%, transparent); color: #fff; }
.lg-process-title { display: block; margin-bottom: 3px; color: rgba(255, 255, 255, 0.72); font-size: 10px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; }
.lg-process-flow { display: flex; flex-wrap: wrap; align-items: center; gap: 7px; color: #fff; font-size: 12px; font-weight: 600; }
.lg-process-flow b { color: var(--lg-highlight); font-weight: 500; }
.lg-credit { position: absolute; right: 22px; bottom: 15px; color: rgba(255, 255, 255, 0.72); font-size: 10px; text-decoration: none; }
.lg-credit:hover { color: #fff; text-decoration: underline; }

@media (max-width: 980px) {
  .lg-layout { grid-template-columns: minmax(370px, 46%) minmax(0, 1fr); }
  .lg-panel { padding-inline: clamp(24px, 4vw, 48px); }
  .lg-hero { padding: 48px; }
  .lg-hero h2 { font-size: clamp(34px, 5vw, 50px); }
}
@media (max-width: 760px) {
  .lg-layout { display: flex; flex-direction: column; }
  .lg-hero {
    position: relative; align-self: stretch; order: 0; min-height: 220px; height: 30vh; max-height: 310px; padding: 26px 26px 28px; background-position: center 38%;
    background-image:
      linear-gradient(90deg, rgba(15, 35, 40, 0.69) 0%, rgba(15, 35, 40, 0.34) 47%, rgba(15, 35, 40, 0.08) 100%),
      linear-gradient(0deg, rgba(9, 22, 25, 0.62) 0%, rgba(9, 22, 25, 0.04) 58%),
      url('../assets/login/equipe-colaboracao-1000.jpg');
  }
  .lg-hero-label { margin-bottom: 9px; padding: 6px 9px; font-size: 9px; }
  .lg-hero h2 { max-width: 560px; font-size: clamp(25px, 6vw, 38px); }
  .lg-hero-desc, .lg-process { display: none; }
  .lg-credit { right: 12px; bottom: 7px; font-size: 8px; }
  .lg-panel { order: 1; flex: 1; min-height: 550px; padding: 25px 28px 19px; }
  .lg-brand { min-height: 48px; }
  .lg-brand > img { width: min(74vw, 278px); max-height: 58px; }
  .lg-content { padding: 32px 0 40px; }
  .lg-eyebrow { margin-bottom: 12px; font-size: 10px; }
  .lg-content h1 { font-size: clamp(28px, 7vw, 35px); }
  .lg-intro { margin: 12px 0 22px; font-size: 14px; }
  .lg-footer { font-size: 10px; }
  .lg-footer-links { gap: 11px; }
}
@media (max-width: 390px) {
  .lg-panel { padding-inline: 20px; }
  .lg-footer { flex-direction: column; align-items: flex-start; gap: 7px; }
  .lg-hero { min-height: 190px; }
}
@media (prefers-reduced-motion: reduce) {
  .lg-root *, .lg-root *::before, .lg-root *::after { transition-duration: 0.01ms !important; }
}
</style>
