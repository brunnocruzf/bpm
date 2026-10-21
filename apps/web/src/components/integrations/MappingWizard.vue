<script setup lang="ts">
/**
 * Novo mapeamento (ou edição de um mapeamento personalizado) em etapas:
 * 1 Identificação · 2 Endpoint · 3 Entrada · 4 Saída · 5 Resumo.
 * O mapeamento cria uma operação do BPM ("map:<chave>"): consulta nos formulários (fonte do componente
 * Consulta AX) ou etapa automática do fluxo (integração executada). A definição sai do próprio de/para:
 * a entrada diz de onde vem cada parâmetro do endpoint; a saída diz quais dados voltam para o BPM.
 */
import { computed, reactive, ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import { api, ApiError } from '@/api/http'
import { useNotify } from '@/composables/notify'
import { useSession } from '@/stores/session'
import { METHOD_TONE, type BindingOp, type Endpoint, type EndpointRow } from './types'

const props = defineProps<{ op: BindingOp | null; endpoints: EndpointRow[] }>()
const emit = defineEmits<{ close: []; saved: [] }>()
const notify = useNotify()
const session = useSession()

type Kind = 'lookup' | 'task'
type Origin = '' | 'value' | 'field' | 'const'
interface OutRow { path: string; key: string; label: string; use: boolean; sensitive: boolean; manual: boolean }

const STEPS = ['Identificação', 'Endpoint', 'Entrada', 'Saída', 'Resumo']
const step = ref(0)
const saving = ref(false)
const loadingDetail = ref(false)
const errors = ref<Record<string, string[]>>({})
const detail = ref<Endpoint | null>(null)
const endpointFilter = ref('')
const newBodyPath = ref('')
const newOutPath = ref('')

const isNew = computed(() => !props.op)
const form = reactive({
  kind: (props.op?.kind === 'task' ? 'task' : 'lookup') as Kind,
  label: props.op?.label ?? '',
  key: props.op ? props.op.operation.replace(/^map:/, '') : '',
  description: props.op?.description ?? '',
  endpointId: props.op?.binding?.endpointId ?? '',
  enabled: props.op?.binding?.enabled ?? false,
  inputMap: { ...(props.op?.binding?.inputMap ?? {}) } as Record<string, string>,
  outputs: [] as OutRow[],
  extraTargets: [] as string[],
})
const keyTouched = ref(!isNew.value)

/** "Fornecedor por CNPJ" → "fornecedorPorCnpj"; "contato.email" → "contatoEmail" */
function camel(text: string, fallback: string, max: number) {
  const words = text.normalize('NFD').replace(/[̀-ͯ]/g, '').split(/[^A-Za-z0-9]+/).filter(Boolean)
  let out = words.map((w, i) => (i === 0 ? w.charAt(0).toLowerCase() + w.slice(1) : w.charAt(0).toUpperCase() + w.slice(1).toLowerCase())).join('')
  if (!/^[A-Za-z]/.test(out)) out = fallback + out
  return out.slice(0, max)
}
watch(() => form.label, (label) => {
  if (!keyTouched.value) form.key = camel(label, 'mapeamento', 41)
})
// trocar o tipo muda as origens possíveis da entrada (valor digitado × dados do processo)
watch(() => form.kind, () => {
  form.inputMap = {}
  if (form.kind === 'lookup' && detail.value && detail.value.method !== 'GET') pickEndpoint('')
})

const candidates = computed(() => {
  const q = endpointFilter.value.trim().toLowerCase()
  return props.endpoints
    .filter((e) => (form.kind === 'lookup' ? e.method === 'GET' : true))
    .filter((e) => !q || `${e.key} ${e.name} ${e.path} ${e.tag ?? ''}`.toLowerCase().includes(q) || e.id === form.endpointId)
})

async function pickEndpoint(id: string) {
  const changed = id !== form.endpointId
  form.endpointId = id
  if (changed) {
    form.inputMap = {}
    form.extraTargets = []
  }
  detail.value = null
  if (!id) {
    form.outputs = []
    return
  }
  loadingDetail.value = true
  try {
    detail.value = await api<Endpoint>(`/admin/integrations/endpoints/${id}`)
    buildOutputs()
  } catch (err) {
    notify.error(err)
  } finally {
    loadingDetail.value = false
  }
}

// saída: campos de resposta do endpoint + caminhos já mapeados (preserva o que foi escolhido)
function buildOutputs() {
  const previous = form.outputs.length
    ? form.outputs.filter((o) => o.use).map(({ key, label, path }) => ({ key, label, path }))
    : Object.entries(props.op?.binding?.outputMap ?? {}).map(([key, path]) => ({ key, path, label: props.op?.outputs[key] ?? key }))
  const byPath = new Map(previous.map((o) => [o.path, o]))
  const rows: OutRow[] = (detail.value?.responseFields ?? []).map((f) => {
    const was = byPath.get(f.path)
    return { path: f.path, key: was?.key ?? camel(f.path, 'campo', 63), label: was?.label ?? f.label, use: !!was, sensitive: f.sensitive, manual: false }
  })
  for (const was of previous) if (!rows.some((r) => r.path === was.path)) rows.push({ ...was, use: true, sensitive: false, manual: true })
  form.outputs = rows
}

if (form.endpointId) {
  const keep = { ...form.inputMap }
  pickEndpoint(form.endpointId).then(() => {
    form.inputMap = keep
    form.extraTargets = Object.keys(keep).filter((t) => t.startsWith('body:') && !detail.value?.bodyFields.some((f) => `body:${f.path}` === t))
  })
}

// ------------------------------------------------------------------ entrada
const WHERE: Record<string, string> = { path: 'no endereço', query: 'na consulta', header: 'no cabeçalho' }
const targets = computed(() => {
  const d = detail.value
  if (!d) return [] as { key: string; label: string; hint: string; required: boolean }[]
  return [
    ...d.params.filter((p) => p.mode === 'input').map((p) => ({ key: p.name, label: p.name, hint: `${WHERE[p.in] ?? p.in}${p.required ? ' · obrigatório' : ''}`, required: p.required })),
    ...d.bodyFields.map((f) => ({ key: `body:${f.path}`, label: f.path, hint: `corpo · ${f.type}${f.required ? ' · obrigatório' : ''}`, required: !!f.required })),
    ...form.extraTargets.map((t) => ({ key: t, label: t.slice(5), hint: 'corpo · incluído manualmente', required: false })),
  ]
})
function originOf(target: string): Origin {
  const v = form.inputMap[target]
  if (v === undefined) return ''
  if (v.startsWith('const:')) return 'const'
  return form.kind === 'lookup' && v === 'input:value' ? 'value' : 'field'
}
function setOrigin(target: string, origin: Origin) {
  if (!origin) delete form.inputMap[target]
  else form.inputMap[target] = origin === 'value' ? 'input:value' : origin === 'const' ? 'const:' : 'input:'
}
function valueOf(target: string) {
  const v = form.inputMap[target] ?? ''
  return v.startsWith('const:') || v.startsWith('input:') ? v.slice(6) : ''
}
function setValue(target: string, value: string) {
  form.inputMap[target] = (originOf(target) === 'const' ? 'const:' : 'input:') + value
}
function addBodyTarget() {
  const path = newBodyPath.value.trim()
  if (path && /^[A-Za-z0-9_.[\]-]{1,200}$/.test(path) && !form.extraTargets.includes(`body:${path}`)) form.extraTargets.push(`body:${path}`)
  newBodyPath.value = ''
}
const credentialLike = (n: string) => /^(authorization|cookie|proxy-authorization|x-api-key|api-key|apikey)$|pass|senha|secret|token/i.test(n)
const typedValueMapped = computed(() => Object.values(form.inputMap).includes('input:value'))

// ------------------------------------------------------------------ saída
function addOutput() {
  const path = newOutPath.value.trim()
  if (!path || !/^[A-Za-z0-9_.[\]$-]{1,200}$/.test(path)) return
  if (!form.outputs.some((o) => o.path === path)) form.outputs.push({ path, key: camel(path, 'campo', 63), label: path, use: true, sensitive: false, manual: true })
  newOutPath.value = ''
}
const usedOutputs = computed(() => form.outputs.filter((o) => o.use))
const selectedEndpoint = computed(() => props.endpoints.find((e) => e.id === form.endpointId) ?? null)
const isWriteEndpoint = computed(() => !!detail.value && detail.value.method !== 'GET')

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const body = {
      key: form.key.trim(), kind: form.kind, label: form.label.trim(), description: form.description.trim() || null, endpointId: form.endpointId || null, enabled: form.enabled,
      inputMap: Object.fromEntries(Object.entries(form.inputMap).filter(([, v]) => v !== '')),
      outputs: usedOutputs.value.map(({ key, label, path }) => ({ key: key.trim(), label: label.trim(), path })),
    }
    if (isNew.value) await api('/admin/integrations/bindings', { method: 'POST', body })
    else await api(`/admin/integrations/bindings/${encodeURIComponent(props.op!.operation)}`, { method: 'PUT', body })
    notify.success(form.enabled ? 'Mapeamento salvo e ativo: já pode ser usado.' : 'Mapeamento salvo, ainda inativo: ative-o quando estiver pronto.')
    await session.refreshMeta()
    emit('saved')
  } catch (err) {
    if (err instanceof ApiError) {
      errors.value = err.errors
      const keys = Object.keys(err.errors)
      step.value = keys.some((k) => ['key', 'kind', 'label', 'description'].includes(k)) ? 0 : keys.includes('endpointId') ? 1
        : keys.some((k) => k.startsWith('inputMap')) ? 2 : keys.some((k) => k.startsWith('outputs')) ? 3 : 4
    }
    notify.error(err)
  } finally {
    saving.value = false
  }
}
const err = (k: string) => errors.value[k]?.[0]
const outErr = (i: number) => {
  const idx = usedOutputs.value.indexOf(form.outputs[i])
  return idx >= 0 ? errors.value[`outputs.${idx}.key`]?.[0] ?? errors.value[`outputs.${idx}.path`]?.[0] : undefined
}
</script>

<template>
  <Dialog :visible="true" modal :header="isNew ? 'Novo mapeamento' : `Mapeamento — ${op?.label}`" :style="{ width: 'min(1100px, 97vw)' }" @update:visible="(v) => !v && emit('close')">
    <div class="wiz">
      <nav class="steps" aria-label="Etapas">
        <button v-for="(s, i) in STEPS" :key="s" type="button" class="step" :class="{ on: step === i, done: i < step }" :aria-current="step === i ? 'step' : undefined" @click="step = i">
          <span class="n">{{ i + 1 }}</span> {{ s }}
        </button>
      </nav>

      <div class="body">
        <!-- 1 · Identificação -->
        <template v-if="step === 0">
          <h2>O que este mapeamento faz?</h2>
          <div class="kinds" role="radiogroup" aria-label="Tipo do mapeamento">
            <label class="kind" :class="{ on: form.kind === 'lookup', locked: !isNew }">
              <input v-model="form.kind" type="radio" value="lookup" :disabled="!isNew" />
              <i class="pi pi-search" aria-hidden="true" />
              <span><strong>Consulta no formulário</strong><small>O usuário digita um valor, clica em <em>Consultar</em> e o formulário é preenchido. Aparece no componente <em>Consulta AX</em>.</small></span>
            </label>
            <label class="kind" :class="{ on: form.kind === 'task', locked: !isNew }">
              <input v-model="form.kind" type="radio" value="task" :disabled="!isNew" />
              <i class="pi pi-cog" aria-hidden="true" />
              <span><strong>Etapa automática do fluxo</strong><small>O processo envia dados ao sistema externo e guarda o resultado. Aparece na etapa do tipo <em>Integração</em>.</small></span>
            </label>
          </div>
          <div v-if="err('kind')" class="tm-hint err">{{ err('kind') }}</div>
          <div class="g2">
            <div class="tm-field"><label for="m-label">Nome *</label><input id="m-label" v-model="form.label" maxlength="120" placeholder="Ex.: Fornecedor por CNPJ" /><div v-if="err('label')" class="tm-hint err">{{ err('label') }}</div></div>
            <div class="tm-field"><label for="m-key">Identificador *</label><input id="m-key" v-model="form.key" :disabled="!isNew" maxlength="41" placeholder="fornecedorPorCnpj" @input="keyTouched = true" />
              <div class="tm-hint">Chave usada pelo BPM: <code>map:{{ form.key || '…' }}</code>{{ isNew ? ' (não pode ser alterada depois)' : '' }}.</div>
              <div v-if="err('key')" class="tm-hint err">{{ err('key') }}</div></div>
          </div>
          <div class="tm-field"><label for="m-desc">Descrição (opcional)</label><input id="m-desc" v-model="form.description" maxlength="400" placeholder="Para que serve e quem usa" /></div>
        </template>

        <!-- 2 · Endpoint -->
        <template v-else-if="step === 1">
          <h2>Qual serviço do sistema externo será chamado?</h2>
          <p class="tm-hint" style="margin-top:0">{{ form.kind === 'lookup' ? 'Consultas aceitam apenas endpoints de leitura (GET).' : 'A etapa automática pode ler (GET) ou gravar (POST/PUT) no sistema externo.' }} A lista mostra os endpoints cadastrados na aba <em>Endpoints</em>.</p>
          <div class="g-ep">
            <div class="tm-field"><label for="m-ep-q">Filtrar</label><input id="m-ep-q" v-model="endpointFilter" placeholder="Nome, identificador ou caminho" /></div>
            <div class="tm-field"><label for="m-ep">Endpoint *</label>
              <select id="m-ep" :value="form.endpointId" @change="pickEndpoint(($event.target as HTMLSelectElement).value)">
                <option value="">Selecione…</option>
                <option v-for="e in candidates" :key="e.id" :value="e.id">{{ e.method }} {{ e.path }} — {{ e.name }} ({{ e.connection.name }}){{ e.enabled ? '' : ' · desabilitado' }}</option>
              </select>
              <div v-if="err('endpointId')" class="tm-hint err">{{ err('endpointId') }}</div></div>
          </div>
          <p v-if="loadingDetail" class="tm-hint" role="status">Carregando o endpoint…</p>
          <template v-if="detail && selectedEndpoint">
            <dl class="tm-kv">
              <dt>Serviço</dt><dd><span class="tm-pill" :class="METHOD_TONE[detail.method]">{{ detail.method }}</span> <code>{{ detail.url }}</code></dd>
              <dt>Conexão</dt><dd>{{ selectedEndpoint.connection.name }} · {{ selectedEndpoint.connection.enabled ? 'habilitada' : 'desabilitada' }}</dd>
              <dt>Endpoint</dt><dd>{{ detail.enabled ? 'habilitado' : 'desabilitado' }} · {{ detail.params.filter((p) => p.mode === 'input').length }} parâmetro(s) de entrada · {{ detail.responseFields.length }} campo(s) de saída</dd>
            </dl>
            <div v-if="!detail.enabled || !selectedEndpoint.connection.enabled" class="tm-note warn"><i class="pi pi-exclamation-triangle" aria-hidden="true" /><span>Endpoint ou conexão desligado: com o mapeamento ativo, a {{ form.kind === 'lookup' ? 'consulta' : 'etapa' }} vai falhar até você ligá-los.</span></div>
            <div v-if="isWriteEndpoint && !selectedEndpoint.connection.allowWrites" class="tm-note warn"><i class="pi pi-shield" aria-hidden="true" /><span>Este endpoint <strong>grava</strong> no sistema externo e a conexão ainda não está liberada para escritas: a etapa vai falhar até liberar (aba <em>Conexões</em>, com o número da aprovação).</span></div>
            <div v-if="!detail.responseFields.length" class="tm-note warn"><i class="pi pi-info-circle" aria-hidden="true" /><span>Este endpoint ainda não tem campos de saída. Configure-os em <em>Endpoints → Parâmetros de saída</em> (exemplo ou teste) ou digite os caminhos na etapa <strong>Saída</strong>.</span></div>
          </template>
        </template>

        <!-- 3 · Entrada -->
        <template v-else-if="step === 2">
          <h2>De onde vem cada informação enviada?</h2>
          <p class="tm-hint" style="margin-top:0">
            <template v-if="form.kind === 'lookup'">Escolha qual parâmetro recebe o <strong>valor digitado no formulário</strong>. Os demais podem receber um valor fixo ou não ser enviados.</template>
            <template v-else>Para cada informação, escolha um <strong>dado do processo</strong> (a chave de um campo preenchido numa etapa anterior) ou um <strong>valor fixo</strong>.</template>
          </p>
          <p v-if="!detail" class="tm-empty">Escolha o endpoint na etapa anterior.</p>
          <template v-else>
            <table class="tm-table" style="min-width:0">
              <thead><tr><th>Informação no endpoint</th><th>Origem</th><th>{{ form.kind === 'lookup' ? 'Valor fixo' : 'Chave do dado / valor fixo' }}</th></tr></thead>
              <tbody>
                <tr v-for="t in targets" :key="t.key">
                  <td><code>{{ t.label }}</code><div class="tm-hint">{{ t.hint }}</div></td>
                  <td>
                    <select class="tm-select" :value="originOf(t.key)" :aria-label="`Origem de ${t.label}`" @change="setOrigin(t.key, ($event.target as HTMLSelectElement).value as Origin)">
                      <option value="">— não enviar —</option>
                      <option v-if="form.kind === 'lookup'" value="value">Valor digitado no formulário</option>
                      <option v-else value="field">Dado do processo</option>
                      <option value="const" :disabled="credentialLike(t.label)">Valor fixo</option>
                    </select>
                  </td>
                  <td>
                    <input v-if="originOf(t.key) === 'const' || originOf(t.key) === 'field'" class="tm-input" :value="valueOf(t.key)" :aria-label="`Valor de ${t.label}`"
                      :placeholder="originOf(t.key) === 'field' ? 'ex.: numeroPedido' : 'valor fixo'" @input="setValue(t.key, ($event.target as HTMLInputElement).value)" />
                    <span v-else-if="t.required && !originOf(t.key)" class="tm-hint err">obrigatório</span>
                  </td>
                </tr>
                <tr v-if="!targets.length"><td colspan="3" class="tm-empty">Este endpoint não recebe parâmetros.</td></tr>
              </tbody>
            </table>
            <div v-if="isWriteEndpoint" class="addbody">
              <input v-model="newBodyPath" class="tm-input" placeholder="campo do corpo não documentado (ex.: lines[].itemId)" aria-label="Novo campo do corpo" @keyup.enter="addBodyTarget" />
              <button class="tm-btn small" @click="addBodyTarget"><i class="pi pi-plus" aria-hidden="true" /> Adicionar</button>
            </div>
            <div v-if="form.kind === 'lookup' && !typedValueMapped" class="tm-note warn"><i class="pi pi-info-circle" aria-hidden="true" /><span>Nenhum parâmetro recebe o valor digitado: escolha um para que a consulta funcione.</span></div>
            <div v-if="errors.inputMap" class="tm-hint err">{{ errors.inputMap.join(' ') }}</div>
            <div class="tm-note"><i class="pi pi-shield" aria-hidden="true" /><span>Senhas e tokens não entram aqui: ficam na conexão e são enviados pelo servidor.</span></div>
          </template>
        </template>

        <!-- 4 · Saída -->
        <template v-else-if="step === 3">
          <h2>Quais informações voltam para o BPM?</h2>
          <p class="tm-hint" style="margin-top:0">
            <template v-if="form.kind === 'lookup'">Marque o que poderá preencher o formulário. A chave e o rótulo aparecem em <em>Dado do AX</em> no componente <em>Consulta AX</em>.</template>
            <template v-else>Marque o que será gravado nos dados do processo. Para exibir numa etapa seguinte, crie no formulário um campo somente leitura com a mesma chave.</template>
          </p>
          <p v-if="!detail" class="tm-empty">Escolha o endpoint na etapa Endpoint.</p>
          <template v-else>
            <table class="tm-table" style="min-width:0">
              <thead><tr><th style="width:52px">Usar</th><th>Caminho na resposta</th><th>Chave no BPM</th><th>Rótulo</th></tr></thead>
              <tbody>
                <tr v-for="(o, i) in form.outputs" :key="o.path">
                  <td style="text-align:center"><input v-model="o.use" type="checkbox" :aria-label="`Usar ${o.path}`" /></td>
                  <td><code>{{ o.path }}</code><span v-if="o.sensitive" class="tm-pill red" style="margin-left:4px">sensível</span><span v-if="o.manual" class="tm-hint"> · manual</span></td>
                  <td><input v-model="o.key" class="tm-input" :disabled="!o.use" :aria-label="`Chave de ${o.path}`" /><div v-if="o.use && outErr(i)" class="tm-hint err">{{ outErr(i) }}</div></td>
                  <td><input v-model="o.label" class="tm-input" :disabled="!o.use" :aria-label="`Rótulo de ${o.path}`" /></td>
                </tr>
                <tr v-if="!form.outputs.length"><td colspan="4" class="tm-empty">O endpoint não tem campos de saída cadastrados: digite abaixo o caminho de cada informação na resposta.</td></tr>
              </tbody>
            </table>
            <div class="addbody">
              <input v-model="newOutPath" class="tm-input" placeholder="caminho na resposta (ex.: nome ou contato.email)" aria-label="Novo caminho de saída" @keyup.enter="addOutput" />
              <button class="tm-btn small" @click="addOutput"><i class="pi pi-plus" aria-hidden="true" /> Adicionar campo</button>
            </div>
            <div v-if="err('outputs')" class="tm-hint err">{{ err('outputs') }}</div>
          </template>
        </template>

        <!-- 5 · Resumo -->
        <template v-else>
          <h2>Resumo</h2>
          <dl class="tm-kv">
            <dt>Tipo</dt><dd>{{ form.kind === 'lookup' ? 'Consulta no formulário' : 'Etapa automática do fluxo' }}</dd>
            <dt>Nome</dt><dd>{{ form.label || '—' }} · <code>map:{{ form.key || '…' }}</code></dd>
            <dt>Endpoint</dt><dd><template v-if="detail"><span class="tm-pill" :class="METHOD_TONE[detail.method]">{{ detail.method }}</span> {{ detail.name }} ({{ selectedEndpoint?.connection.name }})</template><template v-else>—</template></dd>
            <dt>Entrada</dt><dd><span v-for="(v, t) in form.inputMap" :key="t" class="pair"><code>{{ String(t).replace(/^body:/, '') }}</code> ⇐ {{ v === 'input:value' ? 'valor digitado' : v.startsWith('const:') ? `fixo “${v.slice(6)}”` : `dado ${v.slice(6)}` }}</span><template v-if="!Object.keys(form.inputMap).length">—</template></dd>
            <dt>Saída</dt><dd><span v-for="o in usedOutputs" :key="o.path" class="pair"><code>{{ o.key }}</code> ({{ o.label }}) ⇐ {{ o.path }}</span><template v-if="!usedOutputs.length">—</template></dd>
            <dt>Onde usar</dt><dd>{{ form.kind === 'lookup' ? 'Editor de formulários → componente Consulta AX → Fonte da consulta.' : 'Editor do fluxo → etapa do tipo Integração → Integração executada.' }}</dd>
          </dl>
          <label class="check activate"><input v-model="form.enabled" type="checkbox" /> <span><strong>Ativar mapeamento</strong> — passa a chamar o endpoint real. Inativo, a {{ form.kind === 'lookup' ? 'consulta' : 'etapa' }} não funciona (mapeamentos novos não têm dados simulados).</span></label>
          <div v-if="Object.keys(errors).length" class="tm-note err"><i class="pi pi-times-circle" aria-hidden="true" /><span><span v-for="(m, k) in errors" :key="k" style="display:block">{{ m[0] }}</span></span></div>
        </template>
      </div>
    </div>

    <template #footer>
      <button class="tm-btn" @click="emit('close')"><i class="pi pi-times" aria-hidden="true" /> Fechar</button>
      <span style="flex:1" />
      <button class="tm-btn" :disabled="step === 0" @click="step--"><i class="pi pi-angle-left" aria-hidden="true" /> Anterior</button>
      <button class="tm-btn" :disabled="step === STEPS.length - 1" @click="step++">Próximo <i class="pi pi-angle-right" aria-hidden="true" /></button>
      <button class="tm-btn primary" :disabled="saving" @click="save"><i class="pi pi-check" aria-hidden="true" /> {{ saving ? 'Salvando...' : 'Salvar' }}</button>
    </template>
  </Dialog>
</template>

<style scoped>
.wiz { display: grid; grid-template-columns: 190px minmax(0, 1fr); gap: 18px; min-height: 440px; }
.steps { display: flex; flex-direction: column; gap: 4px; border-right: 1px solid var(--line); padding-right: 12px; }
.step { display: flex; align-items: center; gap: 8px; padding: 8px; border: 0; border-radius: 8px; background: none; color: #7a8a99; font: inherit; font-size: 12px; font-weight: 650; text-align: left; cursor: pointer; }
.step .n { display: grid; place-items: center; width: 22px; height: 22px; border-radius: 50%; border: 1px solid #c9d3dc; font-size: 11px; }
.step.on { color: var(--brand-text); background: var(--brand-softer); }
.step.on .n { background: var(--brand); border-color: var(--brand); color: var(--on-brand); }
.step.done .n { border-color: var(--green); color: var(--green); }
.body h2 { margin: 0 0 10px; font-size: 14px; }
.kinds { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; }
.kind { display: grid; grid-template-columns: auto auto minmax(0, 1fr); gap: 10px; align-items: start; padding: 12px; border: 1px solid var(--line-strong, #d5dde6); border-radius: 10px; cursor: pointer; font-size: 12px; color: #43586a; }
.kind > i { font-size: 18px; color: #6c7f91; margin-top: 1px; }
.kind strong { display: block; color: #26384a; font-size: 13px; margin-bottom: 3px; }
.kind small { display: block; line-height: 1.45; }
.kind.on { border-color: var(--brand); background: var(--brand-softer); }
.kind.on > i { color: var(--brand-text); }
.kind.locked { cursor: default; }
.kind.locked:not(.on) { opacity: .55; }
.g2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.g-ep { display: grid; grid-template-columns: minmax(180px, .6fr) minmax(0, 1.4fr); gap: 10px; }
.addbody { display: flex; gap: 6px; margin-top: 8px; }
.addbody .tm-input { flex: 1; }
.check { display: flex; gap: 8px; align-items: flex-start; font-size: 12px; color: #43586a; }
.activate { margin-top: 14px; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; background: #fbfcfd; }
.pair { display: block; }
.err { color: var(--red); }
code { font-size: 11px; background: #eef2f5; padding: 1px 4px; border-radius: 4px; overflow-wrap: anywhere; }
@media (max-width: 760px) { .wiz { grid-template-columns: 1fr; } .steps { flex-direction: row; overflow-x: auto; border-right: 0; padding-right: 0; } .kinds, .g2, .g-ep { grid-template-columns: 1fr; } }
</style>
