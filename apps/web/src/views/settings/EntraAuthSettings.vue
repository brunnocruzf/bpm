<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api, errorMessage } from '@/api/http'
import { auth } from '@/auth'
import { useNotify } from '@/composables/notify'
import { useSetting } from '@/composables/useSetting'
import SettingHeader from '@/components/settings/SettingHeader.vue'
import IntegrationLog from '@/components/settings/IntegrationLog.vue'

const emit = defineEmits<{ changed: [] }>()
const { setting, saving, err, load, save, action } = useSetting<any>('entra/auth')
const notify = useNotify()
const source = ref<{ driver: string; source: string } | null>(null)
const testing = ref(false)
// recarrega o log depois de salvar/validar/testar/ativar
const logKey = ref(0)
const origin = window.location.origin

const form = reactive({
  tenantId: '', authorityHost: 'login.microsoftonline.com', spaClientId: '', redirectUris: '', postLogoutRedirectUri: '',
  allowedOrigins: '', apiAudience: '', apiScope: 'access_as_user', allowedTenants: '',
  checklist: { spaPlatform: false, redirectUris: false, apiExposed: false, scopeConsented: false, appRoles: false, tokenV2: false } as Record<string, boolean>,
})

const CHECKLIST: { key: string; label: string }[] = [
  { key: 'spaPlatform', label: 'App Registration da SPA criado com plataforma "Single-page application" (Authorization Code + PKCE; sem client secret).' },
  { key: 'redirectUris', label: 'Redirect URIs do ambiente cadastradas (local, homologação e/ou produção) e logout URI definida.' },
  { key: 'apiExposed', label: 'App Registration da API: "Expose an API" com Application ID URI (audience) e scope delegado (ex.: access_as_user).' },
  { key: 'scopeConsented', label: 'SPA autorizada a pedir o scope da API e consentimento concedido pelo administrador do tenant.' },
  { key: 'appRoles', label: 'App Roles Bpm.* criados na API e atribuídos a usuários/grupos no Enterprise Application.' },
  { key: 'tokenV2', label: 'accessTokenAcceptedVersion = 2 no manifesto da API (issuer v2.0).' },
]

const fullScope = computed(() => (form.apiAudience ? `${form.apiAudience.replace(/\/$/, '')}/${form.apiScope}` : ''))
const lines = (v: string) => v.split(/[\n,]/).map((s) => s.trim()).filter(Boolean)

function fill() {
  const v = setting.value?.value
  if (!v) return
  Object.assign(form, {
    tenantId: v.tenantId, authorityHost: v.authorityHost, spaClientId: v.spaClientId, redirectUris: (v.redirectUris ?? []).join('\n'),
    postLogoutRedirectUri: v.postLogoutRedirectUri, allowedOrigins: (v.allowedOrigins ?? []).join('\n'), apiAudience: v.apiAudience,
    apiScope: v.apiScope, allowedTenants: (v.allowedTenants ?? []).join('\n'), checklist: { ...form.checklist, ...(v.checklist ?? {}) },
  })
}
watch(setting, fill)
onMounted(async () => {
  await load()
  source.value = await api('/public/auth-config')
})

async function submit() {
  if (await save({
    tenantId: form.tenantId, authorityHost: form.authorityHost, spaClientId: form.spaClientId, redirectUris: lines(form.redirectUris),
    postLogoutRedirectUri: form.postLogoutRedirectUri, allowedOrigins: lines(form.allowedOrigins), apiAudience: form.apiAudience,
    apiScope: form.apiScope, allowedTenants: lines(form.allowedTenants), checklist: form.checklist,
  })) emit('changed')
  logKey.value++
}

async function discover() {
  if (await action('entra/auth/validate')) emit('changed')
  logKey.value++
}

async function tokenTest() {
  const v = setting.value?.value
  if (!v) return
  testing.value = true
  try {
    const accessToken = await auth.testLogin({ clientId: v.spaClientId, authority: `https://${v.authorityHost}/${v.tenantId}`, scopes: [`${String(v.apiAudience).replace(/\/$/, '')}/${v.apiScope}`] })
    await action('entra/auth/token-test', { accessToken })
    emit('changed')
  } catch (e) {
    notify.error(new Error(`Login de teste não concluído: ${errorMessage(e)}. Verifique se ${window.location.origin} está nas Redirect URIs da SPA.`))
  } finally {
    testing.value = false
    logKey.value++
  }
}

async function activate() {
  if (await action('entra/auth/activate', {}, 'Configuração de login ativada. Novos logins usam estes parâmetros.')) {
    source.value = await api('/public/auth-config')
    emit('changed')
  }
  logKey.value++
}
async function deactivate() {
  if (await action('entra/auth/deactivate', {}, 'Voltou a valer a configuração do ambiente.')) {
    source.value = await api('/public/auth-config')
    emit('changed')
  }
  logKey.value++
}
const canActivate = computed(() => setting.value?.status === 'configured' && setting.value?.lastValidation?.code === 'token_ok' && !setting.value?.activatedAt)
</script>

<template>
  <SettingHeader :setting="setting" title="Microsoft Entra ID — login da aplicação" subtitle="A SPA autentica com MSAL (Authorization Code + PKCE) e a API valida o access token (issuer, audience, tenant, scope). Somente parâmetros públicos: nenhum segredo é usado no login." />

  <div class="tm-note" style="margin-top:0"><i class="pi pi-info-circle" aria-hidden="true" /><span>
    Configuração em vigor: <strong>{{ source?.source === 'settings' ? 'esta tela (ativada)' : 'variáveis do ambiente' }}</strong> · modo de autenticação da plataforma: <code>{{ source?.driver }}</code>.
    O produto não cria nem altera App Registrations; siga o checklist com o administrador do Entra. Esta integração é independente da importação de usuários (Graph).
  </span></div>

  <div class="grid">
    <section class="tm-panel">
      <div class="tm-panel-head"><div class="tm-panel-title">Parâmetros (não secretos)</div></div>
      <div class="tm-panel-body">
        <div class="two">
          <div class="tm-field"><label for="e-tenant">Tenant ID <span class="tm-req">*</span></label><input id="e-tenant" v-model="form.tenantId" placeholder="00000000-0000-0000-0000-000000000000" /><div v-if="err('tenantId')" class="tm-hint err">{{ err('tenantId') }}</div></div>
          <div class="tm-field"><label for="e-host">Host de identidade</label>
            <select id="e-host" v-model="form.authorityHost"><option value="login.microsoftonline.com">login.microsoftonline.com (global)</option><option value="login.microsoftonline.us">login.microsoftonline.us (US Gov)</option><option value="login.partner.microsoftonline.cn">login.partner.microsoftonline.cn (China)</option></select>
          </div>
        </div>
        <div class="tm-field"><label for="e-client">Client ID da SPA <span class="tm-req">*</span></label><input id="e-client" v-model="form.spaClientId" /><div v-if="err('spaClientId')" class="tm-hint err">{{ err('spaClientId') }}</div></div>
        <div class="tm-field"><label for="e-redirect">Redirect URIs (uma por linha) <span class="tm-req">*</span></label><textarea id="e-redirect" v-model="form.redirectUris" :placeholder="`${'https://bpm.tmsa.example'}\nhttp://localhost:5173`" /><div class="tm-hint">https obrigatório (http apenas localhost). Endereço atual: <code>{{ origin }}</code></div><div v-if="err('redirectUris')" class="tm-hint err">{{ err('redirectUris') }}</div></div>
        <div class="two">
          <div class="tm-field"><label for="e-logout">Logout URI</label><input id="e-logout" v-model="form.postLogoutRedirectUri" /></div>
          <div class="tm-field"><label for="e-origins">Origens autorizadas (uma por linha)</label><textarea id="e-origins" v-model="form.allowedOrigins" style="min-height:34px" /></div>
        </div>
        <div class="two">
          <div class="tm-field"><label for="e-aud">Audience da API (Application ID URI) <span class="tm-req">*</span></label><input id="e-aud" v-model="form.apiAudience" placeholder="api://bpm-tmsa-api" /><div v-if="err('apiAudience')" class="tm-hint err">{{ err('apiAudience') }}</div></div>
          <div class="tm-field"><label for="e-scope">Scope delegado <span class="tm-req">*</span></label><input id="e-scope" v-model="form.apiScope" /><div class="tm-hint">Pedido pela SPA: <code>{{ fullScope || '—' }}</code></div></div>
        </div>
        <div class="tm-field"><label for="e-tenants">Tenants aceitos pela API (GUIDs; vazio = somente o tenant acima)</label><textarea id="e-tenants" v-model="form.allowedTenants" style="min-height:34px" /></div>
        <p class="tm-hint">A API valida: assinatura (JWKS do tenant), issuer <code>https://{{ form.authorityHost }}/&lt;tenant&gt;/v2.0</code>, audience, tenant permitido, expiração e scope. ID token nunca é aceito como bearer.</p>
        <div class="tm-actions"><button class="tm-btn primary" :disabled="saving" @click="submit">Salvar</button></div>
      </div>
    </section>

    <section class="tm-panel">
      <div class="tm-panel-head"><div class="tm-panel-title">Checklist do App Registration</div></div>
      <div class="tm-panel-body">
        <label v-for="c in CHECKLIST" :key="c.key" class="check"><input v-model="form.checklist[c.key]" type="checkbox" /> <span>{{ c.label }}</span></label>
        <p class="tm-hint">O checklist é um registro do que o administrador do Entra fez; a ativação depende das validações reais abaixo.</p>
      </div>
      <div class="tm-panel-head" style="border-top:1px solid #edf1f4"><div class="tm-panel-title">Validação e ativação</div></div>
      <div class="tm-panel-body steps">
        <div class="step"><strong>1. Descoberta OIDC</strong><span class="tm-muted">Consulta o documento OpenID do tenant (issuer, JWKS, PKCE).</span><button class="tm-btn small" :disabled="saving || setting?.status === 'incomplete'" @click="discover">Validar descoberta</button></div>
        <div class="step"><strong>2. Teste de login/token</strong><span class="tm-muted">Abre o login Microsoft (popup) com esta configuração e valida o access token na API. O token não é armazenado.</span><button class="tm-btn small" :disabled="testing || saving || !setting?.lastValidation?.tenantGuid" @click="tokenTest">{{ testing ? 'Aguardando login...' : 'Testar login' }}</button></div>
        <div class="step"><strong>3. Ativar</strong><span class="tm-muted">Somente após 1 e 2 nesta versão. Alterações posteriores exigem nova validação.</span>
          <div class="tm-actions"><button class="tm-btn small primary" :disabled="!canActivate || saving" @click="activate">Ativar configuração</button><button v-if="setting?.activatedAt" class="tm-btn small ghost" :disabled="saving" @click="deactivate">Desativar (voltar ao ambiente)</button></div>
        </div>
      </div>
    </section>
  </div>

  <IntegrationLog
    area="entra"
    title="Log do Entra ID"
    caption="Descobertas OIDC e testes de login/token (com cada verificação), ativações, alterações da configuração, logins Microsoft e tokens recusados pela API — com o motivo (audience, issuer, scope, tenant, expiração...), as claims públicas do token recusado e o que fazer. Mude a origem para ver também a importação de usuários e grupos."
    :kind-labels="{ test: 'Testes', access: 'Logins', config: 'Configuração', sync: 'Importações', mapping: 'Mapeamentos', user: 'Usuários' }"
    subsystem="login"
    show-subsystem
    :refresh-key="logKey"
  />
</template>

<style scoped>
.grid { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 16px; align-items: start; }
@media (max-width: 1300px) { .grid { grid-template-columns: 1fr; } }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.check { display: flex; gap: 8px; align-items: flex-start; font-size: 12px; margin-bottom: 9px; color: #43586a; }
.check input { margin-top: 2px; }
.steps { display: flex; flex-direction: column; gap: 12px; }
.step { display: flex; flex-direction: column; gap: 5px; font-size: 12px; }
.step .tm-btn { align-self: flex-start; }
.err { color: var(--red); }
code { font-size: 11px; background: #eef2f5; padding: 1px 4px; border-radius: 4px; }
</style>
