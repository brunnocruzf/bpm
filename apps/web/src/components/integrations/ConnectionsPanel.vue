<script setup lang="ts">
/**
 * Conexões com sistemas externos (ex.: API do Dynamics AX 2012 — apidax): URL base, ambiente,
 * autenticação (credencial somente escrita, no cofre), documento OpenAPI e liberação de escritas.
 */
import { computed, onMounted, reactive, ref } from 'vue'
import Dialog from 'primevue/dialog'
import { api, ApiError } from '@/api/http'
import { fmtDateTime } from '@/composables/format'
import { useNotify } from '@/composables/notify'
import OpenApiImportDialog from './OpenApiImportDialog.vue'
import { AUTH_HELP, AUTH_LABEL, ENV_LABEL, type Connection } from './types'

const emit = defineEmits<{ changed: [] }>()
const notify = useNotify()
const items = ref<Connection[]>([])
const allowedHosts = ref<string[]>([])
const editing = ref<Connection | null>(null)
const creating = ref(false)
const saving = ref(false)
const testing = ref<string | null>(null)
const errors = ref<Record<string, string[]>>({})
const importFor = ref<Connection | null>(null)

const blank = () => ({
  key: '', name: '', system: 'AX2012', baseUrl: '', environment: 'homologacao', enabled: false, timeoutSeconds: 15, authType: 'bearer_token',
  authConfig: {
    prefix: 'Bearer', testPath: '', loginPath: '/v1/User/login', userName: '', userField: 'userName', passwordField: 'password', tokenPath: '', tokenTtlMinutes: 50,
    headerName: 'X-API-Key', tokenUrl: '', clientId: '', scope: '',
  } as Record<string, any>,
  openapiUrl: '', allowWrites: false, writesApproval: '', secret: '', secretFileRef: '',
})
const form = reactive(blank())

async function load() {
  try {
    const res = await api<{ data: Connection[]; allowedHosts: string[] }>('/admin/integrations/connections')
    items.value = res.data
    allowedHosts.value = res.allowedHosts
  } catch (e) {
    notify.error(e)
  }
}

function openNew(preset = false) {
  Object.assign(form, blank())
  if (preset) {
    // sugestão para a API do AX 2012 (Swagger em https://apidax.tmsa.ind.br/swagger/index.html)
    // autenticação da apidax: Authorization: Bearer <token> (o token é colado no campo de credencial)
    Object.assign(form, { key: 'apidax', name: 'API Dynamics AX 2012 (apidax)', system: 'AX2012', baseUrl: 'https://apidax.tmsa.ind.br', openapiUrl: 'https://apidax.tmsa.ind.br/swagger/v1/swagger.json',
      authType: 'bearer_token', authConfig: { ...blank().authConfig, prefix: 'Bearer', testPath: '/v1/Financeiro/Moeda' } })
  }
  errors.value = {}
  editing.value = null
  creating.value = true
}
function openEdit(c: Connection) {
  Object.assign(form, blank(), {
    key: c.key, name: c.name, system: c.system, baseUrl: c.baseUrl, environment: c.environment, enabled: c.enabled, timeoutSeconds: c.timeoutSeconds,
    authType: c.authType, authConfig: { ...blank().authConfig, ...c.authConfig }, openapiUrl: c.openapiUrl ?? '', allowWrites: c.allowWrites, writesApproval: c.writesApproval ?? '',
  })
  errors.value = {}
  editing.value = c
  creating.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    // envia só os parâmetros do método escolhido
    const keys: Record<string, string[]> = {
      none: [], bearer_token: ['prefix'], basic: ['userName'], api_key_header: ['headerName', 'prefix'],
      login: ['loginPath', 'userName', 'userField', 'passwordField', 'tokenPath', 'tokenTtlMinutes'], oauth2_client_credentials: ['tokenUrl', 'clientId', 'scope'],
    }
    const authConfig = Object.fromEntries([...(keys[form.authType] ?? []), 'testPath'].map((k) => [k, form.authConfig[k]]))
    const body: Record<string, unknown> = { ...form, authConfig }
    if (!form.secret) delete body.secret
    if (!form.secretFileRef) delete body.secretFileRef
    if (editing.value) await api(`/admin/integrations/connections/${editing.value.id}`, { method: 'PUT', body })
    else await api('/admin/integrations/connections', { method: 'POST', body })
    notify.success('Conexão salva.')
    creating.value = false
    await load()
    emit('changed')
  } catch (e) {
    if (e instanceof ApiError) errors.value = e.errors
    notify.error(e)
  } finally {
    saving.value = false
  }
}

async function test(c: Connection) {
  testing.value = c.id
  try {
    await api(`/admin/integrations/connections/${c.id}/test`, { method: 'POST' })
    await load()
  } catch (e) {
    notify.error(e)
  } finally {
    testing.value = null
  }
}
const err = (k: string) => errors.value[k]?.[0]

const SECRET_LABEL: Record<string, string> = {
  bearer_token: 'Token', basic: 'Senha', api_key_header: 'Chave da API', login: 'Senha do usuário de serviço', oauth2_client_credentials: 'Client secret',
}
/** Como o cabeçalho vai sair (sem revelar a credencial). */
const headerPreview = computed(() => {
  const c = form.authConfig
  switch (form.authType) {
    case 'bearer_token': return `Authorization: ${(c.prefix || '').trim() ? c.prefix.trim() + ' ' : ''}••••••`
    case 'basic': return `Authorization: Basic base64(${c.userName || 'usuário'}:••••••)`
    case 'api_key_header': return `${c.headerName || 'X-API-Key'}: ${(c.prefix || '').trim() ? c.prefix.trim() + ' ' : ''}••••••`
    case 'login': case 'oauth2_client_credentials': return 'Authorization: Bearer <token obtido pelo servidor>'
    default: return '—'
  }
})
const testState = (c: Connection) => (!c.lastTest ? null : !c.lastTest.ok ? 'err' : c.lastTest.verified === false ? 'warn' : 'ok')
onMounted(load)
defineExpose({ load })
</script>

<template>
  <section class="tm-panel">
    <div class="tm-panel-head">
      <div><div class="tm-panel-title">Conexões</div><div class="tm-panel-caption">Sistema, URL base, ambiente e autenticação. A credencial fica no cofre (somente escrita) e é usada apenas pelo servidor.</div></div>
      <div class="tm-actions">
        <button v-if="!items.some((c) => c.key === 'apidax')" class="tm-btn small" @click="openNew(true)"><i class="pi pi-bolt" aria-hidden="true" /> Conexão da API do AX (apidax)</button>
        <button class="tm-btn small primary" @click="openNew()"><i class="pi pi-plus" aria-hidden="true" /> Nova conexão</button>
      </div>
    </div>
    <div class="tm-panel-body">
      <p v-if="!items.length" class="tm-empty">Nenhuma conexão. Comece pela “Conexão da API do AX (apidax)” e importe os endpoints do Swagger.</p>
      <div class="cards">
        <article v-for="c in items" :key="c.id" class="card" :class="{ off: !c.enabled }">
          <header>
            <div><strong>{{ c.name }}</strong><div class="tm-hint"><code>{{ c.key }}</code> · {{ c.system }} · {{ ENV_LABEL[c.environment] }}</div></div>
            <span class="tm-pill" :class="c.enabled ? 'green' : 'neutral'">{{ c.enabled ? 'Habilitada' : 'Desabilitada' }}</span>
          </header>
          <dl class="tm-kv">
            <dt>URL base</dt><dd><code>{{ c.baseUrl }}</code></dd>
            <dt>Autenticação</dt><dd>{{ AUTH_LABEL[c.authType] }}<template v-if="c.authType !== 'none'"> · credencial {{ c.credential.configured ? 'definida' : 'não definida' }}</template></dd>
            <dt>Política de rede</dt><dd :class="c.policy.ok ? 'ok' : 'bad'">{{ c.policy.ok ? (c.hostDeclared ? 'host declarado pela plataforma' : 'permitido') : c.policy.message }}</dd>
            <dt>OpenAPI</dt><dd>{{ c.openapiTitle ? `${c.openapiTitle} ${c.openapiVersion ?? ''}` : '—' }}<template v-if="c.openapiImportedAt"> · importado {{ fmtDateTime(c.openapiImportedAt) }}</template></dd>
            <dt>Endpoints</dt><dd>{{ c.enabledEndpointsCount }} habilitado(s) de {{ c.endpointsCount }}<span v-if="c.changedEndpoints" class="tm-pill amber" style="margin-left:6px">{{ c.changedEndpoints }} com contrato alterado</span></dd>
            <dt>Escritas</dt><dd>{{ c.allowWrites ? `liberadas (${c.writesApproval})` : 'bloqueadas (somente leitura)' }}</dd>
          </dl>
          <div v-if="c.lastTest" class="tm-note" :class="{ err: testState(c) === 'err', warn: testState(c) === 'warn' }" style="margin-top:8px">
            <i class="pi" :class="testState(c) === 'ok' ? 'pi-check-circle' : testState(c) === 'warn' ? 'pi-exclamation-triangle' : 'pi-times-circle'" aria-hidden="true" />
            <span><strong>Último teste: {{ testState(c) === 'ok' ? 'verificado' : testState(c) === 'warn' ? 'credencial não verificada' : 'falhou' }}</strong> · {{ fmtDateTime(c.lastTestedAt) }} · {{ c.lastTest.durationMs }} ms<template v-if="c.lastTest.by"> · {{ c.lastTest.by }}</template>
              <br />{{ c.lastTest.message }}
              <template v-if="c.lastTestExplain"><br /><strong>O que fazer:</strong> {{ c.lastTestExplain.action }}</template>
            </span>
          </div>
          <footer class="tm-actions">
            <button class="tm-btn small" @click="openEdit(c)"><i class="pi pi-pencil" aria-hidden="true" /> Editar</button>
            <button class="tm-btn small" :disabled="testing === c.id" @click="test(c)"><i class="pi pi-bolt" aria-hidden="true" /> {{ testing === c.id ? 'Testando...' : 'Testar conexão' }}</button>
            <button class="tm-btn small" @click="importFor = c"><i class="pi pi-cloud-download" aria-hidden="true" /> Importar do Swagger</button>
          </footer>
        </article>
      </div>
      <p class="tm-hint" style="margin-top:10px">
        Hosts declarados pela plataforma: <code>{{ allowedHosts.length ? allowedHosts.join(', ') : 'nenhum' }}</code>.
        Hosts que resolvem para a rede interna (como <code>apidax.tmsa.ind.br</code>) e, em produção, todos os hosts precisam estar em <code>BPM_INTEGRATION_ALLOWED_HOSTS</code>.
      </p>
    </div>
  </section>

  <Dialog :visible="creating" modal :header="editing ? `Editar conexão — ${editing.name}` : 'Nova conexão'" :style="{ width: 'min(860px, 96vw)' }" @update:visible="(v) => (creating = v)">
    <div class="grid2">
      <div class="tm-field"><label for="c-key">Identificador</label><input id="c-key" v-model="form.key" :disabled="!!editing" placeholder="apidax" /><div v-if="err('key')" class="tm-hint err">{{ err('key') }}</div></div>
      <div class="tm-field"><label for="c-name">Nome</label><input id="c-name" v-model="form.name" /><div v-if="err('name')" class="tm-hint err">{{ err('name') }}</div></div>
      <div class="tm-field"><label for="c-sys">Sistema</label><input id="c-sys" v-model="form.system" placeholder="AX2012, Senior, ..." /></div>
      <div class="tm-field"><label for="c-env">Ambiente</label><select id="c-env" v-model="form.environment"><option v-for="(l, k) in ENV_LABEL" :key="k" :value="k">{{ l }}</option></select></div>
    </div>
    <div class="tm-field"><label for="c-url">URL base</label><input id="c-url" v-model="form.baseUrl" placeholder="https://apidax.tmsa.ind.br" /><div v-if="err('baseUrl')" class="tm-hint err">{{ err('baseUrl') }}</div></div>
    <div class="tm-field"><label for="c-oa">URL do documento OpenAPI (swagger.json)</label><input id="c-oa" v-model="form.openapiUrl" placeholder="https://apidax.tmsa.ind.br/swagger/v1/swagger.json" />
      <div class="tm-hint">Use o JSON (ex.: <code>/swagger/v1/swagger.json</code>), não a página <code>/swagger/index.html</code>.</div></div>
    <div class="grid2">
      <div class="tm-field"><label for="c-auth">Método de autenticação</label><select id="c-auth" v-model="form.authType"><option v-for="(l, k) in AUTH_LABEL" :key="k" :value="k">{{ l }}</option></select></div>
      <div class="tm-field"><label for="c-to">Tempo limite (s)</label><input id="c-to" v-model.number="form.timeoutSeconds" type="number" min="2" max="120" /></div>
    </div>
    <div class="tm-note" style="margin-top:-4px"><i class="pi pi-info-circle" aria-hidden="true" /><span>{{ AUTH_HELP[form.authType] }}<br />Cabeçalho enviado pelo servidor: <code>{{ headerPreview }}</code></span></div>

    <div v-if="form.authType === 'bearer_token'" class="grid2">
      <div class="tm-field"><label for="c-pf">Prefixo do valor</label><input id="c-pf" v-model="form.authConfig.prefix" placeholder="Bearer" /><div v-if="err('authConfig.prefix')" class="tm-hint err">{{ err('authConfig.prefix') }}</div></div>
    </div>
    <div v-if="form.authType === 'basic'" class="grid2">
      <div class="tm-field"><label for="c-bu">Usuário</label><input id="c-bu" v-model="form.authConfig.userName" autocomplete="off" /><div v-if="err('authConfig.userName')" class="tm-hint err">{{ err('authConfig.userName') }}</div></div>
    </div>
    <div v-if="form.authType === 'api_key_header'" class="grid2">
      <div class="tm-field"><label for="c-h">Nome do cabeçalho</label><input id="c-h" v-model="form.authConfig.headerName" placeholder="X-API-Key" /><div v-if="err('authConfig.headerName')" class="tm-hint err">{{ err('authConfig.headerName') }}</div></div>
      <div class="tm-field"><label for="c-hp">Prefixo (opcional)</label><input id="c-hp" v-model="form.authConfig.prefix" placeholder="ex.: Token" /></div>
    </div>
    <template v-if="form.authType === 'login'">
      <div class="grid2">
        <div class="tm-field"><label for="c-lp">Caminho do login</label><input id="c-lp" v-model="form.authConfig.loginPath" /><div v-if="err('authConfig.loginPath')" class="tm-hint err">{{ err('authConfig.loginPath') }}</div></div>
        <div class="tm-field"><label for="c-u">Usuário de serviço</label><input id="c-u" v-model="form.authConfig.userName" autocomplete="off" /></div>
        <div class="tm-field"><label for="c-tp">Caminho do token na resposta (vazio = detectar)</label><input id="c-tp" v-model="form.authConfig.tokenPath" placeholder="token" /></div>
        <div class="tm-field"><label for="c-ttl">Validade do token (min)</label><input id="c-ttl" v-model.number="form.authConfig.tokenTtlMinutes" type="number" min="1" max="1440" /></div>
      </div>
      <div class="tm-hint">O servidor faz <code>POST {{ form.authConfig.loginPath }}</code> com <code>{"{{ form.authConfig.userField }}": usuário, "{{ form.authConfig.passwordField }}": senha}</code> no corpo (nunca na URL).</div>
    </template>
    <template v-if="form.authType === 'oauth2_client_credentials'">
      <div class="tm-field"><label for="c-tu">URL do endpoint de token (https)</label><input id="c-tu" v-model="form.authConfig.tokenUrl" placeholder="https://auth.exemplo.com.br/oauth2/token" /><div v-if="err('authConfig.tokenUrl')" class="tm-hint err">{{ err('authConfig.tokenUrl') }}</div></div>
      <div class="grid2">
        <div class="tm-field"><label for="c-ci">Client ID</label><input id="c-ci" v-model="form.authConfig.clientId" autocomplete="off" /><div v-if="err('authConfig.clientId')" class="tm-hint err">{{ err('authConfig.clientId') }}</div></div>
        <div class="tm-field"><label for="c-sc">Scope (opcional)</label><input id="c-sc" v-model="form.authConfig.scope" placeholder="api://<api>/.default" /></div>
      </div>
    </template>
    <div v-if="form.authType !== 'none'" class="grid2">
      <div class="tm-field"><label for="c-s">{{ SECRET_LABEL[form.authType] }} — somente escrita</label>
        <input id="c-s" v-model="form.secret" type="password" autocomplete="new-password" :placeholder="editing?.credential.configured ? '•••••• (definida — preencha para substituir)' : form.authType === 'bearer_token' ? 'cole o token (com ou sem “Bearer ”)' : ''" />
        <div class="tm-hint">Guardado criptografado no cofre; nunca volta para a tela nem para os logs.</div></div>
      <div class="tm-field"><label for="c-sf">Ou segredo montado</label><input id="c-sf" v-model="form.secretFileRef" placeholder="file:apidax_token" /><div v-if="err('secretFileRef')" class="tm-hint err">{{ err('secretFileRef') }}</div></div>
    </div>
    <div class="tm-field"><label for="c-tpath">Caminho de verificação (GET leve usado no “Testar conexão”)</label>
      <input id="c-tpath" v-model="form.authConfig.testPath" placeholder="/v1/Financeiro/Moeda" />
      <div class="tm-hint">Sem ele, o teste só confere se a credencial existe (fica “não verificada”). Com ele, o servidor faz uma chamada real e detecta token inválido (401/403).</div>
      <div v-if="err('authConfig.testPath')" class="tm-hint err">{{ err('authConfig.testPath') }}</div></div>
    <div class="grid2">
      <label class="check"><input v-model="form.enabled" type="checkbox" /> <span>Conexão habilitada (chamadas reais pelo servidor)</span></label>
      <label class="check"><input v-model="form.allowWrites" type="checkbox" /> <span>Liberar escritas (POST/PUT/DELETE) — exige aprovação</span></label>
    </div>
    <div v-if="form.allowWrites" class="tm-field"><label for="c-ap">Referência da aprovação (chamado/ata)</label><input id="c-ap" v-model="form.writesApproval" /><div v-if="err('writesApproval')" class="tm-hint err">{{ err('writesApproval') }}</div></div>
    <template #footer>
      <button class="tm-btn" @click="creating = false">Cancelar</button>
      <button class="tm-btn primary" :disabled="saving" @click="save">{{ saving ? 'Salvando...' : 'Salvar' }}</button>
    </template>
  </Dialog>

  <OpenApiImportDialog v-if="importFor" :connection="importFor" @close="importFor = null" @imported="() => { importFor = null; load(); emit('changed') }" />
</template>

<style scoped>
.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); gap: 12px; }
.card { border: 1px solid var(--line); border-radius: 10px; padding: 12px; background: #fff; }
.card.off { background: #fbfcfd; }
.card header { display: flex; justify-content: space-between; gap: 10px; align-items: flex-start; margin-bottom: 8px; }
.card footer { margin-top: 10px; }
.ok { color: var(--green) !important; }
.bad { color: var(--red) !important; }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.check { display: flex; gap: 8px; align-items: flex-start; font-size: 12px; margin-bottom: 10px; color: #43586a; }
.err { color: var(--red); }
code { font-size: 11px; background: #eef2f5; padding: 1px 4px; border-radius: 4px; overflow-wrap: anywhere; }
</style>
