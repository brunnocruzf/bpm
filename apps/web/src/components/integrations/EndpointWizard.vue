<script setup lang="ts">
/**
 * Cadastro de endpoint em etapas (inspirado na "fonte de dados REST" do SoftExpert):
 * 1 Dados do serviço · 2 Parâmetros de entrada · 3 Parâmetros de saída · 4 Segurança e uso · 5 Resumo.
 * Diferenças deliberadas: credencial NUNCA é parâmetro constante (fica na conexão/cofre); o exemplo de
 * resposta é guardado só como estrutura (sem valores reais); testes rodam no servidor.
 */
import { computed, reactive, ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import { api, ApiError } from '@/api/http'
import { useNotify } from '@/composables/notify'
import { AUTH_LABEL, METHOD_TONE, type Connection, type Endpoint, type Field, type Param } from './types'

const props = defineProps<{ endpointId: string | null; connections: Connection[] }>()
const emit = defineEmits<{ close: []; saved: [] }>()
const notify = useNotify()

const STEPS = ['Dados do serviço', 'Parâmetros de entrada', 'Parâmetros de saída', 'Segurança e uso', 'Resumo']
const step = ref(0)
const loading = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const current = ref<Endpoint | null>(null)
const sample = ref('')
const testInputs = reactive<Record<string, string>>({})
const testBody = ref('{}')
const confirmWrite = ref(false)
const testing = ref(false)
const testResult = ref<any>(null)

const form = reactive({
  connectionId: props.connections[0]?.id ?? '', key: '', name: '', enabled: false, method: 'GET', path: '/', tag: '', description: '', timeoutSeconds: null as number | null,
  params: [] as Param[], responseFields: [] as Field[], responseIsList: false, resultPath: '',
  lookup: { expose: false, label: '', inputParam: '', fields: [] as string[], allowSensitive: false },
  acknowledgeContract: false,
})
const isNew = computed(() => !props.endpointId)
const manual = computed(() => isNew.value || current.value?.source === 'manual')
const connection = computed(() => props.connections.find((c) => c.id === form.connectionId) ?? null)
const isWrite = computed(() => form.method !== 'GET')
const inputParams = computed(() => form.params.filter((p) => p.mode === 'input'))
const pathParams = computed(() => [...form.path.matchAll(/\{([^}]+)\}/g)].map((m) => m[1]))
const sensitiveSelected = computed(() => form.responseFields.filter((f) => f.sensitive && form.lookup.fields.includes(f.path)).map((f) => f.path))
const credentialLike = (n: string) => /^(authorization|cookie|proxy-authorization|x-api-key|api-key|apikey)$|pass|senha|secret|token/i.test(n)

async function load() {
  if (!props.endpointId) return
  loading.value = true
  try {
    const e = await api<Endpoint>(`/admin/integrations/endpoints/${props.endpointId}`)
    current.value = e
    Object.assign(form, {
      connectionId: e.connection.id, key: e.key, name: e.name, enabled: e.enabled, method: e.method, path: e.path, tag: e.tag ?? '', description: e.description ?? '',
      timeoutSeconds: e.timeoutSeconds, params: e.params.map((p) => ({ ...p })), responseFields: e.responseFields.map((f) => ({ ...f })), responseIsList: e.responseIsList,
      resultPath: e.resultPath ?? '', lookup: { expose: !!e.lookup?.expose, label: e.lookup?.label ?? '', inputParam: e.lookup?.inputParam ?? '', fields: [...(e.lookup?.fields ?? [])], allowSensitive: !!e.lookup?.allowSensitive },
    })
  } catch (err) {
    notify.error(err)
  } finally {
    loading.value = false
  }
}
load()

// parâmetros de caminho detectados no {..} do caminho (como o SoftExpert faz com a URL)
watch(() => form.path, () => {
  for (const name of pathParams.value) {
    if (!form.params.some((p) => p.name === name && p.in === 'path')) form.params.push({ name, in: 'path', type: 'string', required: true, mode: 'input', constant: null, description: null })
  }
})

function addParam() {
  form.params.push({ name: '', in: 'query', type: 'string', required: false, mode: 'input', constant: null, description: null })
}

// exemplo colado sem clicar em "Inferir": infere ao sair da etapa 3 (a etapa 4 depende desses campos)
watch(step, (now, before) => {
  if (before === 2 && now !== 2 && sample.value.trim() && !form.responseFields.length) infer()
})

async function infer() {
  try {
    const res = await api<{ inferred: { isList: boolean; fields: Field[] }; sample: unknown }>('/admin/integrations/endpoints/infer', { method: 'POST', body: { sample: sample.value, resultPath: form.resultPath || undefined } })
    mergeFields(res.inferred.fields, res.inferred.isList)
    notify.success(`${res.inferred.fields.length} campo(s) inferido(s). Revise rótulos e marque o que é sensível.`)
  } catch (err) {
    notify.error(err)
  }
}
function mergeFields(fields: Field[], isList: boolean) {
  const known = new Map(form.responseFields.map((f) => [f.path, f]))
  form.responseFields = fields.map((f) => ({ ...f, label: known.get(f.path)?.label ?? f.label, sensitive: known.get(f.path)?.sensitive ?? f.sensitive }))
  form.responseIsList = isList
}

async function test() {
  if (!props.endpointId) {
    notify.info('Salve o endpoint antes de testar.')
    return
  }
  testing.value = true
  testResult.value = null
  try {
    testResult.value = await api(`/admin/integrations/endpoints/${props.endpointId}/test`, { method: 'POST', body: { inputs: testInputs, body: isWrite.value ? testBody.value : undefined, confirmWrite: confirmWrite.value } })
  } catch (err) {
    notify.error(err)
  } finally {
    testing.value = false
  }
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const body: Record<string, unknown> = {
      connectionId: form.connectionId, key: form.key, name: form.name, enabled: form.enabled, tag: form.tag, description: form.description, timeoutSeconds: form.timeoutSeconds,
      params: form.params, responseFields: form.responseFields, responseIsList: form.responseIsList, resultPath: form.resultPath, lookup: form.lookup,
    }
    if (manual.value) Object.assign(body, { method: form.method, path: form.path })
    if (sample.value.trim()) body.sample = sample.value
    if (form.acknowledgeContract) body.acknowledgeContract = true
    if (isNew.value) await api('/admin/integrations/endpoints', { method: 'POST', body })
    else await api(`/admin/integrations/endpoints/${props.endpointId}`, { method: 'PUT', body })
    notify.success('Endpoint salvo.')
    emit('saved')
  } catch (err) {
    if (err instanceof ApiError) {
      errors.value = err.errors
      const keys = Object.keys(err.errors)
      step.value = keys.some((k) => k.startsWith('lookup')) ? 3 : keys.some((k) => k.startsWith('params')) ? 1 : keys.some((k) => ['sample', 'resultPath', 'responseFields'].includes(k)) ? 2 : 0
    }
    notify.error(err)
  } finally {
    saving.value = false
  }
}
const err = (k: string) => errors.value[k]?.[0]
const paramErr = (i: number) => errors.value[`params.${i}`]?.[0]
</script>

<template>
  <Dialog :visible="true" modal :header="isNew ? 'Cadastro de endpoint (REST)' : `Endpoint — ${form.key}`" :style="{ width: 'min(1100px, 97vw)' }" @update:visible="(v) => !v && emit('close')">
    <div class="wiz">
      <nav class="steps" aria-label="Etapas">
        <button v-for="(s, i) in STEPS" :key="s" type="button" class="step" :class="{ on: step === i, done: i < step }" :aria-current="step === i ? 'step' : undefined" @click="step = i">
          <span class="n">{{ i + 1 }}</span> {{ s }}
        </button>
      </nav>

      <div class="body" :aria-busy="loading">
        <!-- 1 · Dados do serviço -->
        <template v-if="step === 0">
          <h2>Dados do serviço REST</h2>
          <div v-if="current?.contractStatus && current.contractStatus !== 'ok'" class="tm-note warn"><i class="pi pi-exclamation-triangle" aria-hidden="true" />
            <span><strong>Contrato {{ current.contractStatus === 'changed' ? 'alterado' : 'removido' }} no Swagger.</strong> Reimporte pela conexão para atualizar parâmetros/corpo, ou marque como revisado.
              <label class="check" style="margin:6px 0 0"><input v-model="form.acknowledgeContract" type="checkbox" /> <span>Revisei: manter como está</span></label></span></div>
          <div class="g3">
            <div class="tm-field"><label for="w-key">Identificador *</label><input id="w-key" v-model="form.key" :disabled="!isNew" placeholder="Fornecedores.PorCnpj" /><div v-if="err('key')" class="tm-hint err">{{ err('key') }}</div></div>
            <div class="tm-field" style="grid-column: span 2"><label for="w-name">Nome *</label><input id="w-name" v-model="form.name" /><div v-if="err('name')" class="tm-hint err">{{ err('name') }}</div></div>
          </div>
          <div class="g3">
            <div class="tm-field"><label for="w-conn">Conexão</label><select id="w-conn" v-model="form.connectionId" :disabled="!isNew"><option v-for="c in connections" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
            <div class="tm-field"><label for="w-tag">Grupo (tag)</label><input id="w-tag" v-model="form.tag" placeholder="Fornecedores" /></div>
            <label class="check toggle"><input v-model="form.enabled" type="checkbox" /> <span>Habilitado</span></label>
          </div>
          <div class="g-url">
            <div class="tm-field"><label for="w-m">Método</label><select id="w-m" v-model="form.method" :disabled="!manual"><option v-for="m in ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']" :key="m">{{ m }}</option></select></div>
            <div class="tm-field"><label for="w-p">Caminho (URL do serviço = URL base da conexão + caminho) *</label><input id="w-p" v-model="form.path" :disabled="!manual" placeholder="/v1/Fornecedores/{CPF_CNPJ}" /><div v-if="err('path')" class="tm-hint err">{{ err('path') }}</div></div>
          </div>
          <div class="tm-note" style="margin-top:0"><i class="pi pi-info-circle" aria-hidden="true" /><span>
            URL completa: <code>{{ (connection?.baseUrl ?? '') + form.path }}</code>. Parâmetros entre chaves (ex.: <code>{filter}</code>) viram parâmetros de caminho automaticamente.
            <template v-if="!manual"> Método e caminho vêm do Swagger (operationId <code>{{ current?.operationId ?? '—' }}</code>); para mudar, reimporte.</template></span></div>
          <div class="g3">
            <div class="tm-field"><label for="w-to">Tempo de espera (s) — vazio = da conexão ({{ connection?.timeoutSeconds ?? 15 }} s)</label><input id="w-to" v-model.number="form.timeoutSeconds" type="number" min="2" max="120" /></div>
            <div class="tm-field" style="grid-column: span 2"><label for="w-d">Descrição</label><input id="w-d" v-model="form.description" /></div>
          </div>
          <div v-if="current?.warnings?.length" class="tm-note warn"><i class="pi pi-exclamation-triangle" aria-hidden="true" /><span><span v-for="w in current.warnings" :key="w" style="display:block">{{ w }}</span></span></div>
        </template>

        <!-- 2 · Parâmetros de entrada -->
        <template v-else-if="step === 1">
          <h2>Parâmetros de entrada <span class="tm-pill" :class="METHOD_TONE[form.method]" style="margin-left:6px">{{ form.method }}</span></h2>
          <table class="tm-table" style="min-width:0">
            <thead><tr><th>Identificador</th><th>Parâmetro</th><th>Tipo</th><th>Obrig.</th><th>Origem do valor</th><th>Valor constante</th><th /></tr></thead>
            <tbody>
              <tr v-for="(p, i) in form.params" :key="i">
                <td><input v-model="p.name" class="tm-input" :aria-label="`Identificador do parâmetro ${i + 1}`" /><div v-if="paramErr(i)" class="tm-hint err">{{ paramErr(i) }}</div></td>
                <td><select v-model="p.in" class="tm-select" :aria-label="`Local do parâmetro ${i + 1}`"><option value="path">PATH</option><option value="query">QUERY</option><option value="header">HEADER</option></select></td>
                <td><select v-model="p.type" class="tm-select" :aria-label="`Tipo do parâmetro ${i + 1}`"><option v-for="t in ['string', 'integer', 'number', 'boolean', 'date']" :key="t" :value="t">{{ t.toUpperCase() }}</option></select></td>
                <td style="text-align:center"><input v-model="p.required" type="checkbox" :disabled="p.in === 'path'" :aria-label="`Obrigatório ${i + 1}`" /></td>
                <td><select v-model="p.mode" class="tm-select" :aria-label="`Origem do valor ${i + 1}`"><option value="input">Entrada (formulário/processo)</option><option value="constant" :disabled="credentialLike(p.name)">Constante</option></select></td>
                <td><input v-model="p.constant" class="tm-input" :disabled="p.mode !== 'constant'" :placeholder="credentialLike(p.name) ? 'credencial: use a conexão' : ''" :aria-label="`Valor constante ${i + 1}`" /></td>
                <td><button class="tm-link-btn" style="color:var(--red)" @click="form.params.splice(i, 1)">remover</button></td>
              </tr>
              <tr v-if="!form.params.length"><td colspan="7" class="tm-empty">Sem parâmetros.</td></tr>
            </tbody>
          </table>
          <div v-if="err('params')" class="tm-hint err">{{ err('params') }}</div>
          <button class="tm-btn small" style="margin-top:8px" @click="addParam"><i class="pi pi-plus" aria-hidden="true" /> Adicionar parâmetro</button>
          <div class="tm-note warn"><i class="pi pi-shield" aria-hidden="true" /><span><strong>Credenciais não são parâmetros.</strong> Token/senha/Authorization ficam na autenticação da conexão ({{ AUTH_LABEL[connection?.authType ?? 'none'] }}) e são enviados em cabeçalho pelo servidor — nunca como constante ou na URL.</span></div>
          <template v-if="current?.bodyFields?.length">
            <h2 style="margin-top:14px">Corpo da requisição (do Swagger)</h2>
            <p class="tm-hint" style="margin-top:0">O preenchimento do corpo é feito no vínculo com a operação do BPM (Mapeamentos), campo a campo.</p>
            <div class="fields-ro"><span v-for="f in current.bodyFields" :key="f.path"><code>{{ f.path }}</code> {{ f.type }}{{ f.required ? '*' : '' }}</span></div>
          </template>
        </template>

        <!-- 3 · Parâmetros de saída -->
        <template v-else-if="step === 2">
          <h2>Parâmetros de saída</h2>
          <p class="tm-hint" style="margin-top:0">Como no SoftExpert, os tipos podem vir de um exemplo de resposta. Aqui o exemplo é guardado só como estrutura (tipos e formatos), sem os valores reais.</p>
          <div class="g3">
            <div class="tm-field"><label for="w-rp">Caminho do resultado (vazio = raiz)</label><input id="w-rp" v-model="form.resultPath" placeholder="data ou result.items" /><div v-if="err('resultPath')" class="tm-hint err">{{ err('resultPath') }}</div></div>
            <label class="check toggle"><input v-model="form.responseIsList" type="checkbox" /> <span>A resposta é uma lista (usa o primeiro item nas consultas)</span></label>
          </div>
          <div class="out">
            <div>
              <div class="tm-field"><label for="w-sample">Exemplo de resposta (JSON)</label><textarea id="w-sample" v-model="sample" spellcheck="false" placeholder='[{ "codigo": "F-1001", "nome": "...", "contato": { "email": "..." } }]' style="min-height:170px;font-family:ui-monospace,monospace;font-size:11px" /></div>
              <button class="tm-btn small" :disabled="!sample.trim()" @click="infer"><i class="pi pi-sparkles" aria-hidden="true" /> Inferir campos do exemplo</button>
              <div class="test">
                <strong>Testar no servidor</strong>
                <p class="tm-hint" style="margin:2px 0 6px">Chamada real pela conexão ({{ connection?.environment }}). Use em homologação; a resposta volta mascarada.</p>
                <div v-for="p in inputParams" :key="p.in + p.name" class="tm-field" style="margin-bottom:6px"><label :for="`t-${p.name}`">{{ p.name }} <span class="tm-muted">({{ p.in }})</span></label><input :id="`t-${p.name}`" v-model="testInputs[p.name]" /></div>
                <template v-if="isWrite">
                  <div class="tm-field"><label for="t-body">Corpo (JSON)</label><textarea id="t-body" v-model="testBody" spellcheck="false" style="font-family:ui-monospace,monospace;font-size:11px" /></div>
                  <label class="check"><input v-model="confirmWrite" type="checkbox" /> <span>Entendo que este teste <strong>grava</strong> no sistema externo (bloqueado em produção).</span></label>
                </template>
                <button class="tm-btn small" :disabled="testing || isNew" @click="test"><i class="pi pi-play" aria-hidden="true" /> {{ testing ? 'Chamando...' : 'Testar endpoint' }}</button>
                <div v-if="testResult" class="tm-note" :class="testResult.ok ? '' : 'err'">
                  <span><strong>{{ testResult.ok ? `HTTP ${testResult.status}` : testResult.code }}</strong> · {{ testResult.durationMs }} ms<template v-if="testResult.message"> · {{ testResult.message }}</template>
                    <template v-if="testResult.explain"><br /><strong>O que fazer:</strong> {{ testResult.explain.action }}</template>
                    <template v-if="testResult.ok && testResult.inferred?.fields?.length"><br /><button class="tm-link-btn" @click="mergeFields(testResult.inferred.fields, testResult.inferred.isList)">Usar os {{ testResult.inferred.fields.length }} campo(s) desta resposta</button></template>
                  </span>
                </div>
                <pre v-if="testResult?.sample" class="sample">{{ JSON.stringify(testResult.sample, null, 2) }}</pre>
              </div>
            </div>
            <div>
              <table class="tm-table" style="min-width:0">
                <thead><tr><th>Caminho</th><th>Rótulo</th><th>Tipo</th><th>Sensível</th><th /></tr></thead>
                <tbody>
                  <tr v-for="(f, i) in form.responseFields" :key="f.path">
                    <td><code>{{ f.path }}</code><div v-if="f.hint" class="tm-hint">{{ f.hint }}</div></td>
                    <td><input v-model="f.label" class="tm-input" :aria-label="`Rótulo de ${f.path}`" /></td>
                    <td>{{ f.type }}</td>
                    <td style="text-align:center"><input v-model="f.sensitive" type="checkbox" :aria-label="`Sensível ${f.path}`" /></td>
                    <td><button class="tm-link-btn" style="color:var(--red)" @click="form.responseFields.splice(i, 1)">remover</button></td>
                  </tr>
                  <tr v-if="!form.responseFields.length"><td colspan="5" class="tm-empty">Sem campos de saída. Cole um exemplo ou teste o endpoint.</td></tr>
                </tbody>
              </table>
              <pre v-if="current?.responseSample" class="sample" title="Estrutura guardada (sem valores reais)">{{ JSON.stringify(current.responseSample, null, 2) }}</pre>
            </div>
          </div>
        </template>

        <!-- 4 · Segurança e uso -->
        <template v-else-if="step === 3">
          <h2>Segurança</h2>
          <dl class="tm-kv">
            <dt>Autenticação</dt><dd>{{ AUTH_LABEL[connection?.authType ?? 'none'] }} — credencial {{ connection?.credential.configured ? `definida (${connection?.credential.source})` : 'não definida' }}</dd>
            <dt>Política de rede</dt><dd>{{ connection?.policy.ok ? 'destino permitido' : connection?.policy.message }}</dd>
            <dt>Tipo</dt><dd>{{ isWrite ? 'Escrita' : 'Leitura' }}<template v-if="isWrite"> — {{ connection?.allowWrites ? `escritas liberadas na conexão (${connection?.writesApproval})` : 'escritas BLOQUEADAS na conexão (somente leitura)' }}</template></dd>
            <dt>Registro</dt><dd>Cada chamada fica em Execuções com pedido/resposta mascarados (CPF, CNPJ, e-mail, telefone e campos marcados como sensíveis).</dd>
          </dl>
          <h2 style="margin-top:16px">Consulta nos formulários</h2>
          <template v-if="!isWrite">
            <label class="check"><input v-model="form.lookup.expose" type="checkbox" /> <span>Disponibilizar como fonte de consulta no editor de formulários (componente “Consulta AX/API”)</span></label>
            <template v-if="form.lookup.expose">
              <div class="g3">
                <div class="tm-field"><label for="l-label">Rótulo no editor</label><input id="l-label" v-model="form.lookup.label" :placeholder="form.name" /></div>
                <div class="tm-field"><label for="l-in">Parâmetro que recebe o valor digitado</label>
                  <select id="l-in" v-model="form.lookup.inputParam"><option value="">Selecione...</option><option v-for="p in inputParams" :key="p.name" :value="p.name">{{ p.name }} ({{ p.in }})</option></select>
                  <div v-if="err('lookup.inputParam')" class="tm-hint err">{{ err('lookup.inputParam') }}</div></div>
              </div>
              <div class="tm-field"><label>Campos que podem preencher o formulário</label>
                <div v-if="form.responseFields.length" class="fieldpick">
                  <label v-for="f in form.responseFields" :key="f.path" class="check"><input v-model="form.lookup.fields" type="checkbox" :value="f.path" /> <span>{{ f.label }} <code>{{ f.path }}</code><span v-if="f.sensitive" class="tm-pill red" style="margin-left:4px">sensível</span></span></label>
                </div>
                <!-- endpoints do AX chegam sem saída: o Swagger não documenta as respostas -->
                <div v-else class="tm-note warn" style="margin-top:0" role="note">
                  <i class="pi pi-exclamation-triangle" aria-hidden="true" />
                  <span><strong>Este endpoint ainda não tem campos de saída</strong> — o Swagger da API não descreve a resposta, então não há o que escolher aqui.
                    Defina-os em <strong>Parâmetros de saída</strong>: cole um exemplo da resposta e clique em <em>Inferir campos</em>, ou use <em>Testar endpoint</em>.
                    <br /><button type="button" class="tm-btn small" style="margin-top:6px" @click="step = 2"><i class="pi pi-arrow-left" aria-hidden="true" /> Ir para Parâmetros de saída</button></span>
                </div>
                <div v-if="err('lookup.fields') && form.responseFields.length" class="tm-hint err">{{ err('lookup.fields') }}</div>
              </div>
              <label v-if="sensitiveSelected.length" class="check"><input v-model="form.lookup.allowSensitive" type="checkbox" /> <span>Confirmo que o formulário pode exibir dados sensíveis ({{ sensitiveSelected.join(', ') }}).</span></label>
              <p class="tm-hint">Chave da fonte no editor: <code>{{ current?.lookupSourceKey ?? `api:${form.key}` }}</code>. O servidor refaz a consulta ao salvar o formulário.</p>
            </template>
          </template>
          <p v-else class="tm-hint">Escritas não podem ser consulta; use-as no vínculo de uma tarefa de integração (Mapeamentos).</p>
          <p v-if="current" class="tm-hint">Usado em {{ current.usedInForms }} formulário(s) · vínculos: {{ current.bindings.length ? current.bindings.map((b) => `${b.operation}${b.enabled ? '' : ' (inativo)'}`).join(', ') : 'nenhum' }}.</p>
        </template>

        <!-- 5 · Resumo -->
        <template v-else>
          <h2>Resumo</h2>
          <dl class="tm-kv">
            <dt>Identificador</dt><dd><code>{{ form.key }}</code> — {{ form.name }}</dd>
            <dt>Serviço</dt><dd><span class="tm-pill" :class="METHOD_TONE[form.method]">{{ form.method }}</span> <code>{{ (connection?.baseUrl ?? '') + form.path }}</code></dd>
            <dt>Situação</dt><dd>{{ form.enabled ? 'Habilitado' : 'Desabilitado' }}<template v-if="!connection?.enabled"> (conexão desabilitada)</template></dd>
            <dt>Entrada</dt><dd>{{ form.params.map((p) => `${p.name} (${p.in}${p.mode === 'constant' ? ' = constante' : ''})`).join(', ') || '—' }}</dd>
            <dt>Saída</dt><dd>{{ form.responseFields.length }} campo(s){{ form.responseIsList ? ' · lista' : '' }}{{ form.resultPath ? ` · em ${form.resultPath}` : '' }} · {{ form.responseFields.filter((f) => f.sensitive).length }} sensível(is)</dd>
            <dt>Formulários</dt><dd>{{ form.lookup.expose ? `consulta “${form.lookup.label || form.name}” (entrada ${form.lookup.inputParam}; ${form.lookup.fields.length} campo(s))` : 'não publicado' }}</dd>
          </dl>
          <div v-if="Object.keys(errors).length" class="tm-note err"><i class="pi pi-times-circle" aria-hidden="true" /><span><span v-for="(m, k) in errors" :key="k" style="display:block">{{ m[0] }}</span></span></div>
        </template>
      </div>
    </div>

    <template #footer>
      <button class="tm-btn" @click="emit('close')"><i class="pi pi-times" aria-hidden="true" /> Fechar</button>
      <span style="flex:1" />
      <button class="tm-btn" :disabled="step === 0" @click="step--"><i class="pi pi-angle-left" aria-hidden="true" /> Anterior</button>
      <button class="tm-btn" :disabled="step === STEPS.length - 1" @click="step++">Próximo <i class="pi pi-angle-right" aria-hidden="true" /></button>
      <button class="tm-btn primary" :disabled="saving" @click="save"><i class="pi pi-check" aria-hidden="true" /> {{ saving ? 'Salvando...' : 'Finalizar' }}</button>
    </template>
  </Dialog>
</template>

<style scoped>
.wiz { display: grid; grid-template-columns: 210px minmax(0, 1fr); gap: 18px; min-height: 480px; }
.steps { display: flex; flex-direction: column; gap: 4px; border-right: 1px solid var(--line); padding-right: 12px; }
.step { display: flex; align-items: center; gap: 8px; padding: 8px; border: 0; border-radius: 8px; background: none; color: #7a8a99; font: inherit; font-size: 12px; font-weight: 650; text-align: left; cursor: pointer; }
.step .n { display: grid; place-items: center; width: 22px; height: 22px; border-radius: 50%; border: 1px solid #c9d3dc; font-size: 11px; }
.step.on { color: var(--brand-text); background: var(--brand-softer); }
.step.on .n { background: var(--brand); border-color: var(--brand); color: var(--on-brand); }
.step.done .n { border-color: var(--green); color: var(--green); }
.body h2 { margin: 0 0 10px; font-size: 14px; }
.g3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; align-items: end; }
.g-url { display: grid; grid-template-columns: 120px minmax(0, 1fr); gap: 10px; }
.check { display: flex; gap: 8px; align-items: flex-start; font-size: 12px; color: #43586a; margin-bottom: 8px; }
.check.toggle { align-self: center; }
.out { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr); gap: 14px; }
.test { margin-top: 12px; padding: 10px; border: 1px dashed var(--line-strong); border-radius: 8px; }
.sample { max-height: 220px; overflow: auto; margin: 8px 0 0; padding: 8px; background: #f6f8fa; border-radius: 6px; font-size: 10.5px; }
.fields-ro { display: flex; flex-wrap: wrap; gap: 6px 12px; font-size: 11px; color: #5d6f80; }
.fieldpick { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 2px 12px; max-height: 240px; overflow: auto; border: 1px solid var(--line); border-radius: 8px; padding: 8px; }
.err { color: var(--red); }
code { font-size: 11px; background: #eef2f5; padding: 1px 4px; border-radius: 4px; overflow-wrap: anywhere; }
</style>
