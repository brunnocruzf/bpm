<script setup lang="ts">
/**
 * Mapeamentos: liga cada operação do BPM (consultas fixas do PO 159 e tarefas de integração) a um
 * endpoint do catálogo com de/para de entrada e saída. Sem vínculo ativo, vale o transporte
 * configurado (MOCK). Vínculo ativo com endpoint desligado falha — nunca volta ao MOCK em silêncio.
 */
import { computed, onMounted, reactive, ref } from 'vue'
import Dialog from 'primevue/dialog'
import { api, ApiError } from '@/api/http'
import { fmtDateTime } from '@/composables/format'
import { useNotify } from '@/composables/notify'
import type { BindingOp, Endpoint, EndpointRow } from './types'

const emit = defineEmits<{ changed: [] }>()
const notify = useNotify()
const ops = ref<BindingOp[]>([])
const endpoints = ref<EndpointRow[]>([])
const editing = ref<BindingOp | null>(null)
const detail = ref<Endpoint | null>(null)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const form = reactive({ endpointId: '' as string, enabled: false, inputMap: {} as Record<string, string>, outputMap: {} as Record<string, string>, extraTargets: [] as string[] })

async function load() {
  try {
    const [b, e] = await Promise.all([api<{ data: BindingOp[] }>('/admin/integrations/bindings'), api<{ data: EndpointRow[] }>('/admin/integrations/endpoints')])
    ops.value = b.data
    endpoints.value = e.data
  } catch (err) {
    notify.error(err)
  }
}
onMounted(load)

const candidates = computed(() => endpoints.value.filter((e) => (editing.value?.kind === 'lookup' ? e.method === 'GET' : true)))

async function pickEndpoint(id: string) {
  form.endpointId = id
  detail.value = id ? await api<Endpoint>(`/admin/integrations/endpoints/${id}`) : null
}

async function open(op: BindingOp) {
  editing.value = op
  errors.value = {}
  Object.assign(form, { endpointId: '', enabled: op.binding?.enabled ?? false, inputMap: { ...(op.binding?.inputMap ?? {}) }, outputMap: { ...(op.binding?.outputMap ?? {}) }, extraTargets: [] })
  detail.value = null
  if (op.binding?.endpointId) await pickEndpoint(op.binding.endpointId)
  form.extraTargets = Object.keys(form.inputMap).filter((t) => t.startsWith('body:') && !detail.value?.bodyFields.some((f) => `body:${f.path}` === t))
}

// destinos possíveis da entrada: parâmetros do endpoint + campos do corpo (escritas)
const targets = computed(() => {
  const d = detail.value
  if (!d) return [] as { key: string; label: string }[]
  return [
    ...d.params.filter((p) => p.mode === 'input').map((p) => ({ key: p.name, label: `${p.name} (${p.in}${p.required ? ', obrigatório' : ''})` })),
    ...d.bodyFields.map((f) => ({ key: `body:${f.path}`, label: `corpo: ${f.path} (${f.type}${f.required ? ', obrigatório' : ''})` })),
    ...form.extraTargets.map((t) => ({ key: t, label: `corpo: ${t.slice(5)} (manual)` })),
  ]
})
const sourceOptions = computed(() => Object.entries(editing.value?.inputs ?? {}).map(([k, l]) => ({ value: `input:${k}`, label: l === k ? k : `${l}` })))

function setSource(target: string, value: string) {
  if (!value) delete form.inputMap[target]
  else form.inputMap[target] = value
}
function constOf(target: string) {
  const v = form.inputMap[target] ?? ''
  return v.startsWith('const:') ? v.slice(6) : ''
}
const newBodyPath = ref('')
function addBodyTarget() {
  const path = newBodyPath.value.trim()
  if (path && /^[A-Za-z0-9_.[\]-]{1,200}$/.test(path) && !form.extraTargets.includes(`body:${path}`)) form.extraTargets.push(`body:${path}`)
  newBodyPath.value = ''
}

async function save() {
  if (!editing.value) return
  saving.value = true
  errors.value = {}
  try {
    await api(`/admin/integrations/bindings/${editing.value.operation}`, { method: 'PUT', body: { endpointId: form.endpointId || null, enabled: form.enabled, inputMap: form.inputMap, outputMap: form.outputMap } })
    notify.success(form.enabled ? 'Vínculo ativo: a operação passa a chamar o endpoint real.' : 'Vínculo salvo (inativo: continua no transporte configurado).')
    editing.value = null
    await load()
    emit('changed')
  } catch (err) {
    if (err instanceof ApiError) errors.value = err.errors
    notify.error(err)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="tm-panel">
    <div class="tm-panel-head"><div><div class="tm-panel-title">Mapeamentos das operações do BPM</div><div class="tm-panel-caption">Ligue as consultas dos formulários e as tarefas de integração aos endpoints reais, com de/para de entrada e saída. Sem vínculo ativo vale o transporte configurado (MOCK).</div></div></div>
    <table class="tm-table" style="min-width:0">
      <thead><tr><th>Operação do BPM</th><th>Transporte efetivo</th><th>Endpoint</th><th>De/para</th><th>Atualizado</th><th /></tr></thead>
      <tbody>
        <tr v-for="op in ops" :key="op.operation">
          <td><strong>{{ op.label }}</strong><div class="tm-hint"><code>{{ op.operation }}</code> · {{ op.kind === 'lookup' ? 'leitura' : 'escrita' }}</div></td>
          <td>
            <span v-if="op.binding?.enabled" class="tm-pill" :class="op.binding.usable ? 'green' : 'red'">{{ op.binding.usable ? 'REST (real)' : 'REST — endpoint/conexão desligado' }}</span>
            <span v-else class="tm-pill amber">{{ op.configuredTransport }}</span>
          </td>
          <td>{{ op.binding?.endpointKey ? `${op.binding.endpointName} (${op.binding.connection})` : '—' }}<div v-if="op.binding?.endpointKey" class="tm-hint"><code>{{ op.binding.endpointKey }}</code></div></td>
          <td class="tm-hint">{{ op.binding ? `${Object.keys(op.binding.inputMap).length} entrada(s) · ${Object.keys(op.binding.outputMap).length}/${Object.keys(op.outputs).length} saída(s)` : '—' }}</td>
          <td class="tm-hint">{{ fmtDateTime(op.binding?.updatedAt) }}</td>
          <td><button class="tm-btn small" @click="open(op)"><i class="pi pi-sitemap" aria-hidden="true" /> Mapear</button></td>
        </tr>
      </tbody>
    </table>
  </section>

  <Dialog :visible="!!editing" modal :header="editing ? `Mapear — ${editing.label}` : ''" :style="{ width: 'min(980px, 96vw)' }" @update:visible="(v) => !v && (editing = null)">
    <template v-if="editing">
      <div class="tm-field"><label for="b-ep">Endpoint {{ editing.kind === 'lookup' ? '(leitura)' : '' }}</label>
        <select id="b-ep" :value="form.endpointId" @change="pickEndpoint(($event.target as HTMLSelectElement).value)">
          <option value="">Nenhum (usar transporte configurado)</option>
          <option v-for="e in candidates" :key="e.id" :value="e.id">{{ e.method }} {{ e.path }} — {{ e.name }} ({{ e.connection.name }}){{ e.enabled ? '' : ' · desabilitado' }}</option>
        </select>
        <div v-if="errors.endpointId" class="tm-hint err">{{ errors.endpointId[0] }}</div>
      </div>

      <template v-if="detail">
        <div v-if="!detail.enabled || !detail.connection.enabled" class="tm-note warn"><i class="pi pi-exclamation-triangle" aria-hidden="true" /><span>Endpoint ou conexão desabilitado: com o vínculo ativo, a operação vai falhar até habilitar (não volta ao MOCK).</span></div>
        <h3>Entrada — destino no endpoint ⇐ origem no BPM</h3>
        <table class="tm-table" style="min-width:0">
          <thead><tr><th>Destino no endpoint</th><th>Origem</th><th>Constante</th></tr></thead>
          <tbody>
            <tr v-for="t in targets" :key="t.key">
              <td><code>{{ t.label }}</code></td>
              <td>
                <select class="tm-select" :value="form.inputMap[t.key]?.startsWith('const:') ? 'const' : (form.inputMap[t.key] ?? '')" :aria-label="`Origem de ${t.key}`"
                  @change="(e) => { const v = (e.target as HTMLSelectElement).value; setSource(t.key, v === 'const' ? 'const:' : v) }">
                  <option value="">— não enviar —</option>
                  <option v-for="s in sourceOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
                  <option value="const">Valor constante</option>
                </select>
              </td>
              <td><input v-if="form.inputMap[t.key]?.startsWith('const:')" class="tm-input" :value="constOf(t.key)" :aria-label="`Constante de ${t.key}`" @input="(e) => (form.inputMap[t.key] = 'const:' + (e.target as HTMLInputElement).value)" /></td>
            </tr>
          </tbody>
        </table>
        <div v-if="editing.kind === 'write'" class="addbody">
          <input v-model="newBodyPath" class="tm-input" placeholder="campo do corpo não documentado (ex.: lines[].itemId)" aria-label="Novo campo do corpo" @keyup.enter="addBodyTarget" />
          <button class="tm-btn small" @click="addBodyTarget">Adicionar</button>
        </div>
        <div v-if="errors.inputMap" class="tm-hint err">{{ errors.inputMap.join(' ') }}</div>

        <h3>Saída — campo do BPM ⇐ caminho na resposta</h3>
        <datalist id="resp-paths"><option v-for="f in detail.responseFields" :key="f.path" :value="f.path">{{ f.label }}</option></datalist>
        <table class="tm-table" style="min-width:0">
          <thead><tr><th>Campo do BPM</th><th>Caminho na resposta</th></tr></thead>
          <tbody>
            <tr v-for="(label, field) in editing.outputs" :key="field">
              <td>{{ label }} <code>{{ field }}</code></td>
              <td><input v-model="form.outputMap[field]" class="tm-input" list="resp-paths" placeholder="ex.: nome ou contato.email" :aria-label="`Caminho para ${field}`" style="width:100%" /></td>
            </tr>
          </tbody>
        </table>
        <p v-if="!detail.responseFields.length" class="tm-hint">O endpoint ainda não tem campos de saída: defina-os na etapa “Parâmetros de saída” (exemplo ou teste) para escolher da lista.</p>
        <div v-if="errors.outputMap" class="tm-hint err">{{ errors.outputMap.join(' ') }}</div>
      </template>

      <label class="check" style="margin-top:12px"><input v-model="form.enabled" type="checkbox" /> <span><strong>Ativar vínculo</strong> — a operação passa a chamar o endpoint real{{ editing.kind === 'write' ? ' (escrita idempotente: nunca repete um envio concluído; resultado incerto exige reconciliação)' : '' }}.</span></label>
      <div v-if="errors.enabled" class="tm-hint err">{{ errors.enabled[0] }}</div>
    </template>
    <template #footer>
      <button class="tm-btn" @click="editing = null">Cancelar</button>
      <button class="tm-btn primary" :disabled="saving" @click="save">{{ saving ? 'Salvando...' : 'Salvar' }}</button>
    </template>
  </Dialog>
</template>

<style scoped>
h3 { margin: 14px 0 6px; font-size: 12px; color: #4a5e70; }
.addbody { display: flex; gap: 6px; margin-top: 6px; }
.addbody .tm-input { flex: 1; }
.check { display: flex; gap: 8px; align-items: flex-start; font-size: 12px; color: #43586a; }
.err { color: var(--red); }
code { font-size: 11px; background: #eef2f5; padding: 1px 4px; border-radius: 4px; overflow-wrap: anywhere; }
</style>
