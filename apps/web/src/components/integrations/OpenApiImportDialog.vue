<script setup lang="ts">
/**
 * Importação assistida do OpenAPI/Swagger: baixa o swagger.json pelo SERVIDOR (ou lê o arquivo
 * enviado), mostra a prévia por grupo (novo | alterado | inalterado | removido) com alertas e
 * importa só o que for selecionado. Endpoints novos entram desabilitados.
 */
import { computed, ref } from 'vue'
import Dialog from 'primevue/dialog'
import { api, ApiError, errorMessage } from '@/api/http'
import { useNotify } from '@/composables/notify'
import { METHOD_TONE, type Connection, type Preview, type PreviewOperation } from './types'

const props = defineProps<{ connection: Connection }>()
const emit = defineEmits<{ close: []; imported: [] }>()
const notify = useNotify()

const url = ref(props.connection.openapiUrl ?? `${props.connection.baseUrl}/swagger/v1/swagger.json`)
const file = ref<File | null>(null)
const preview = ref<Preview | null>(null)
const loading = ref(false)
const applying = ref(false)
const error = ref('')
const explain = ref<{ title: string; cause: string; action: string } | null>(null)
const selected = ref<Set<string>>(new Set())
const keys = ref<Record<string, string>>({})
const filter = ref({ q: '', tag: '', status: '', kind: '' })
const open = ref<string | null>(null)

const id = (o: PreviewOperation) => `${o.method} ${o.path}`
const STATUS_LABEL: Record<string, string> = { new: 'Novo', changed: 'Contrato alterado', unchanged: 'Inalterado' }
const STATUS_TONE: Record<string, string> = { new: 'blue', changed: 'amber', unchanged: 'neutral' }

async function load() {
  loading.value = true
  error.value = ''
  explain.value = null
  try {
    let body: FormData | { url: string }
    if (file.value) {
      body = new FormData()
      body.append('file', file.value)
    } else {
      body = { url: url.value.trim() }
    }
    const res = await api<Preview>(`/admin/integrations/connections/${props.connection.id}/openapi/preview`, { method: 'POST', body })
    preview.value = res
    // sugestão: tudo que é novo/alterado de LEITURA; escritas e endpoints de autenticação ficam para decisão explícita
    selected.value = new Set(res.operations.filter((o) => o.status !== 'unchanged' && o.method === 'GET' && !o.flags.includes('credential_in_query')).map(id))
    keys.value = Object.fromEntries(res.operations.map((o) => [id(o), o.suggestedKey]))
  } catch (e) {
    error.value = errorMessage(e)
    if (e instanceof ApiError) explain.value = (e.problem as any).explain ?? null
  } finally {
    loading.value = false
  }
}

const visible = computed(() => (preview.value?.operations ?? []).filter((o) => {
  const q = filter.value.q.trim().toLowerCase()
  return (!q || `${o.method} ${o.path} ${o.name} ${o.operationId ?? ''}`.toLowerCase().includes(q))
    && (!filter.value.tag || o.tag === filter.value.tag)
    && (!filter.value.status || o.status === filter.value.status)
    && (!filter.value.kind || (filter.value.kind === 'read' ? o.method === 'GET' : o.method !== 'GET'))
}))
const groups = computed(() => {
  const map = new Map<string, PreviewOperation[]>()
  for (const o of visible.value) map.set(o.tag, [...(map.get(o.tag) ?? []), o])
  return [...map.entries()].sort((a, b) => a[0].localeCompare(b[0]))
})
const counts = computed(() => {
  const ops = preview.value?.operations ?? []
  return { new: ops.filter((o) => o.status === 'new').length, changed: ops.filter((o) => o.status === 'changed').length, unchanged: ops.filter((o) => o.status === 'unchanged').length }
})

function toggle(o: PreviewOperation) {
  const s = new Set(selected.value)
  if (s.has(id(o))) s.delete(id(o))
  else s.add(id(o))
  selected.value = s
}
function toggleGroup(ops: PreviewOperation[], on: boolean) {
  const s = new Set(selected.value)
  for (const o of ops) on ? s.add(id(o)) : s.delete(id(o))
  selected.value = s
}

async function apply() {
  if (!preview.value) return
  applying.value = true
  try {
    const res = await api<{ created: number; updated: number; unchanged: number; removed: number }>(`/admin/integrations/connections/${props.connection.id}/openapi/apply`, {
      method: 'POST', body: { previewId: preview.value.previewId, operations: [...selected.value], keys: keys.value },
    })
    notify.success(`Importação concluída: ${res.created} novo(s), ${res.updated} atualizado(s), ${res.unchanged} inalterado(s)${res.removed ? `, ${res.removed} marcado(s) como removido(s)` : ''}. Novos endpoints entram desabilitados.`)
    emit('imported')
  } catch (e) {
    notify.error(e)
  } finally {
    applying.value = false
  }
}
</script>

<template>
  <Dialog :visible="true" modal :header="`Importar do Swagger — ${connection.name}`" :style="{ width: 'min(1180px, 97vw)' }" @update:visible="(v) => !v && emit('close')">
    <div class="src">
      <div class="tm-field" style="flex:1;margin:0"><label for="oa-url">URL do swagger.json</label><input id="oa-url" v-model="url" :disabled="!!file" placeholder="https://apidax.tmsa.ind.br/swagger/v1/swagger.json" /></div>
      <div class="tm-field" style="margin:0"><label for="oa-file">ou arquivo .json</label><input id="oa-file" type="file" accept="application/json,.json" @change="(e) => (file = ((e.target as HTMLInputElement).files ?? [])[0] ?? null)" /></div>
      <button class="tm-btn primary" :disabled="loading || (!url && !file)" @click="load"><i class="pi pi-search" aria-hidden="true" /> {{ loading ? 'Lendo...' : 'Gerar prévia' }}</button>
    </div>
    <p class="tm-hint">O documento é baixado pelo servidor (respeitando a política de rede) e nada é gravado até você confirmar. Versões: troque <code>v1</code> por <code>v2</code> na URL para importar a outra versão da API.</p>

    <div v-if="error" class="tm-note err" role="alert"><i class="pi pi-times-circle" aria-hidden="true" /><span>{{ error }}<template v-if="explain"><br /><strong>O que fazer:</strong> {{ explain.action }}</template></span></div>

    <template v-if="preview">
      <div class="meta">
        <div><strong>{{ preview.meta.title }}</strong> <span class="tm-muted">· versão {{ preview.meta.version }} · OpenAPI {{ preview.meta.openapi }} · {{ Math.round(preview.bytes / 1024) }} KB</span></div>
        <div class="pills">
          <span class="tm-pill neutral">{{ preview.meta.operations }} operações</span>
          <span class="tm-pill blue">{{ preview.meta.reads }} leitura(s)</span>
          <span class="tm-pill amber">{{ preview.meta.writes }} escrita(s)</span>
          <span class="tm-pill blue">{{ counts.new }} novo(s)</span>
          <span v-if="counts.changed" class="tm-pill amber">{{ counts.changed }} alterado(s)</span>
          <span class="tm-pill neutral">{{ counts.unchanged }} inalterado(s)</span>
          <span v-if="preview.removed.length" class="tm-pill red">{{ preview.removed.length }} removido(s) do documento</span>
          <span v-for="s in preview.meta.security" :key="s.name" class="tm-pill neutral">segurança: {{ s.type }}{{ s.header ? ` (${s.header} em ${s.in})` : '' }}</span>
        </div>
        <div v-if="preview.sameAsImported" class="tm-hint">Documento idêntico ao último importado.</div>
      </div>
      <div v-for="w in preview.warnings" :key="w" class="tm-note warn" style="margin-top:6px"><i class="pi pi-exclamation-triangle" aria-hidden="true" /><span>{{ w }}</span></div>

      <div class="filters">
        <input v-model="filter.q" class="tm-input" placeholder="Buscar por caminho, nome ou operationId..." aria-label="Buscar operações" />
        <select v-model="filter.tag" class="tm-select" aria-label="Grupo"><option value="">Todos os grupos</option><option v-for="t in preview.meta.tags" :key="t" :value="t">{{ t }}</option></select>
        <select v-model="filter.status" class="tm-select" aria-label="Situação"><option value="">Qualquer situação</option><option value="new">Novos</option><option value="changed">Alterados</option><option value="unchanged">Inalterados</option></select>
        <select v-model="filter.kind" class="tm-select" aria-label="Tipo"><option value="">Leitura e escrita</option><option value="read">Somente leitura (GET)</option><option value="write">Somente escrita</option></select>
      </div>

      <div class="ops">
        <section v-for="[tag, ops] in groups" :key="tag" class="group">
          <header>
            <strong>{{ tag }}</strong> <span class="tm-muted">({{ ops.length }})</span>
            <button class="tm-link-btn" @click="toggleGroup(ops, true)">marcar</button> · <button class="tm-link-btn" @click="toggleGroup(ops, false)">desmarcar</button>
          </header>
          <table class="tm-table" style="min-width:0">
            <tbody>
              <template v-for="o in ops" :key="id(o)">
                <tr :class="{ sel: selected.has(id(o)) }">
                  <td style="width:28px"><input type="checkbox" :checked="selected.has(id(o))" :aria-label="`Importar ${id(o)}`" @change="toggle(o)" /></td>
                  <td style="width:70px"><span class="tm-pill" :class="METHOD_TONE[o.method]">{{ o.method }}</span></td>
                  <td><code>{{ o.path }}</code><div class="tm-hint">{{ o.name }}</div></td>
                  <td style="width:230px">
                    <input v-if="o.status === 'new'" v-model="keys[id(o)]" class="tm-input" style="width:100%" :aria-label="`Identificador de ${id(o)}`" />
                    <code v-else>{{ o.suggestedKey }}</code>
                  </td>
                  <td style="width:150px"><span class="tm-pill" :class="STATUS_TONE[o.status]">{{ STATUS_LABEL[o.status] }}</span></td>
                  <td style="width:120px" class="tm-hint">{{ o.params.length }} parâm. · {{ o.responseFields.length ? `${o.responseFields.length} campos saída` : 'saída não doc.' }}</td>
                  <td style="width:90px"><button class="tm-link-btn" @click="open = open === id(o) ? null : id(o)">{{ open === id(o) ? 'fechar' : 'detalhes' }}</button>
                    <i v-if="o.flags.includes('credential_in_query')" class="pi pi-exclamation-triangle" style="color:var(--red);margin-left:4px" title="Recebe senha/token na URL" aria-label="Recebe senha/token na URL" /></td>
                </tr>
                <tr v-if="open === id(o)" class="det">
                  <td colspan="7">
                    <div v-for="w in o.warnings" :key="w" class="tm-hint" style="color:#7d5418">⚠ {{ w }}</div>
                    <div v-if="o.description" class="tm-hint">{{ o.description }}</div>
                    <div class="cols">
                      <div><strong>Parâmetros</strong>
                        <ul><li v-for="p in o.params" :key="p.in + p.name"><code>{{ p.name }}</code> · {{ p.in.toUpperCase() }} · {{ p.type }}{{ p.required ? ' · obrigatório' : '' }}</li><li v-if="!o.params.length" class="tm-muted">nenhum</li></ul></div>
                      <div><strong>Corpo (JSON)</strong>
                        <ul><li v-for="f in o.bodyFields.slice(0, 40)" :key="f.path"><code>{{ f.path }}</code> · {{ f.type }}</li><li v-if="!o.bodyFields.length" class="tm-muted">sem corpo</li></ul></div>
                      <div><strong>Resposta</strong>
                        <ul><li v-for="f in o.responseFields.slice(0, 40)" :key="f.path"><code>{{ f.path }}</code> · {{ f.type }}</li><li v-if="!o.responseFields.length" class="tm-muted">não documentada — defina pelos Parâmetros de saída (exemplo ou teste)</li></ul></div>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </section>
        <p v-if="!groups.length" class="tm-empty">Nenhuma operação para os filtros.</p>
      </div>
      <div v-if="preview.removed.length" class="tm-note warn"><i class="pi pi-info-circle" aria-hidden="true" /><span>Não estão mais no documento (serão marcados como “removido”, sem apagar): {{ preview.removed.map((r) => r.key).join(', ') }}.</span></div>
    </template>

    <template #footer>
      <span class="tm-muted" style="margin-right:auto">{{ selected.size }} selecionada(s)</span>
      <button class="tm-btn" @click="emit('close')">Cancelar</button>
      <button class="tm-btn primary" :disabled="!preview || applying || (!selected.size && !preview.removed.length)" @click="apply">{{ applying ? 'Importando...' : 'Importar selecionadas' }}</button>
    </template>
  </Dialog>
</template>

<style scoped>
.src { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
.meta { margin: 10px 0 6px; display: flex; flex-direction: column; gap: 6px; font-size: 12px; }
.pills { display: flex; flex-wrap: wrap; gap: 5px; }
.filters { display: flex; gap: 8px; margin: 12px 0 8px; flex-wrap: wrap; }
.filters .tm-input { flex: 1; min-width: 240px; }
.ops { max-height: 52vh; overflow: auto; border: 1px solid var(--line); border-radius: 8px; }
.group header { position: sticky; top: 0; z-index: 1; display: flex; gap: 8px; align-items: center; padding: 7px 10px; background: #f6f8fa; border-bottom: 1px solid var(--line); font-size: 12px; }
tr.sel td { background: color-mix(in srgb, var(--brand) 4%, #fff); }
tr.det td { background: #fbfcfd; font-size: 12px; }
.cols { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 6px; }
.cols ul { margin: 4px 0 0; padding-left: 16px; }
code { font-size: 11px; background: #eef2f5; padding: 1px 4px; border-radius: 4px; overflow-wrap: anywhere; }
</style>
