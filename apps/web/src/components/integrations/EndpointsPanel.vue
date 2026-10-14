<script setup lang="ts">
/**
 * Endpoints ("fontes de dados REST", no SoftExpert): filtros de pesquisa + grade com situação,
 * identificador, nome, método/caminho, grupo, origem (Swagger/manual), saída, uso e contrato.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api } from '@/api/http'
import { useNotify } from '@/composables/notify'
import { useConfirm } from 'primevue/useconfirm'
import EndpointWizard from './EndpointWizard.vue'
import { METHOD_TONE, type Connection, type EndpointRow } from './types'

const props = defineProps<{ connections: Connection[] }>()
const emit = defineEmits<{ changed: [] }>()
const notify = useNotify()
const confirm = useConfirm()
const rows = ref<EndpointRow[]>([])
const tags = ref<string[]>([])
const loading = ref(false)
const filters = reactive({ q: '', connection: '', tag: '', status: '', kind: '' })
const selected = ref<string | null>(null)
const wizard = ref<{ id: string | null } | null>(null)
const page = ref(1)
const PER_PAGE = 50
let debounce: number | undefined

async function load() {
  loading.value = true
  try {
    const res = await api<{ data: EndpointRow[]; tags: string[] }>('/admin/integrations/endpoints', { query: { ...filters } })
    rows.value = res.data
    tags.value = res.tags
    page.value = 1
  } catch (e) {
    notify.error(e)
  } finally {
    loading.value = false
  }
}
watch(() => [filters.connection, filters.tag, filters.status, filters.kind], load)
watch(() => filters.q, () => {
  window.clearTimeout(debounce)
  debounce = window.setTimeout(load, 300)
})
onMounted(load)

const paged = computed(() => rows.value.slice((page.value - 1) * PER_PAGE, page.value * PER_PAGE))
const pages = computed(() => Math.max(1, Math.ceil(rows.value.length / PER_PAGE)))
const current = computed(() => rows.value.find((r) => r.id === selected.value) ?? null)

async function toggle(r: EndpointRow) {
  try {
    await api(`/admin/integrations/endpoints/${r.id}`, { method: 'PUT', body: { enabled: !r.enabled } })
    r.enabled = !r.enabled
    emit('changed')
  } catch (e) {
    notify.error(e)
  }
}
function remove() {
  const r = current.value
  if (!r) return
  confirm.require({
    message: `Remover o endpoint "${r.key}"? Vínculos ativos e formulários que usam a consulta impedem a remoção.`, header: 'Remover endpoint', icon: 'pi pi-trash',
    acceptLabel: 'Remover', rejectLabel: 'Cancelar', acceptClass: 'p-button-danger',
    accept: async () => {
      try {
        await api(`/admin/integrations/endpoints/${r.id}`, { method: 'DELETE' })
        selected.value = null
        await load()
        emit('changed')
      } catch (e) {
        notify.error(e)
      }
    },
  })
}
function saved() {
  wizard.value = null
  load()
  emit('changed')
}
defineExpose({ load })
</script>

<template>
  <div class="layout">
    <aside class="tm-panel filters" aria-label="Filtros de pesquisa">
      <div class="tm-panel-head"><div class="tm-panel-title">Filtros de pesquisa</div></div>
      <div class="tm-panel-body">
        <div class="tm-field"><label for="f-q">Identificador, nome ou caminho</label><input id="f-q" v-model="filters.q" type="search" /></div>
        <div class="tm-field"><label for="f-c">Conexão</label><select id="f-c" v-model="filters.connection"><option value="">Todas</option><option v-for="c in props.connections" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
        <div class="tm-field"><label for="f-t">Grupo</label><select id="f-t" v-model="filters.tag"><option value="">Todos</option><option v-for="t in tags" :key="t" :value="t">{{ t }}</option></select></div>
        <div class="tm-field"><label for="f-s">Situação</label>
          <select id="f-s" v-model="filters.status"><option value="">Todas</option><option value="enabled">Habilitados</option><option value="disabled">Desabilitados</option><option value="lookup">Consulta nos formulários</option><option value="changed">Contrato alterado/removido</option></select></div>
        <div class="tm-field"><label for="f-k">Tipo</label><select id="f-k" v-model="filters.kind"><option value="">Leitura e escrita</option><option value="read">Leitura (GET)</option><option value="write">Escrita</option></select></div>
      </div>
    </aside>

    <section class="tm-panel">
      <div class="toolbar" role="toolbar" aria-label="Ações">
        <button class="tm-btn small icon" title="Novo endpoint" aria-label="Novo endpoint" :disabled="!props.connections.length" @click="wizard = { id: null }"><i class="pi pi-plus" /></button>
        <button class="tm-btn small icon" title="Editar" aria-label="Editar" :disabled="!current" @click="wizard = { id: selected }"><i class="pi pi-pencil" /></button>
        <button class="tm-btn small icon" title="Remover" aria-label="Remover" :disabled="!current" @click="remove"><i class="pi pi-trash" /></button>
        <button class="tm-btn small icon" title="Atualizar" aria-label="Atualizar" :disabled="loading" @click="load"><i class="pi pi-refresh" :class="{ 'pi-spin': loading }" /></button>
        <span class="tm-muted" style="margin-left:auto;font-size:11px">Total de registros: {{ rows.length }}</span>
      </div>
      <div style="overflow-x:auto">
        <table class="tm-table">
          <thead><tr><th style="width:30px" /><th style="width:70px">Situação</th><th>Identificador</th><th>Nome</th><th>Serviço</th><th>Grupo</th><th>Origem</th><th>Saída</th><th>Uso</th></tr></thead>
          <tbody>
            <tr v-if="!rows.length && !loading"><td colspan="9" class="tm-empty">Nenhum endpoint. Importe do Swagger pela conexão ou cadastre manualmente.</td></tr>
            <tr v-for="r in paged" :key="r.id" :class="{ sel: selected === r.id }" @click="selected = r.id" @dblclick="wizard = { id: r.id }">
              <td><input type="radio" name="ep" :checked="selected === r.id" :aria-label="`Selecionar ${r.key}`" @change="selected = r.id" /></td>
              <td><button class="state" :class="{ on: r.enabled }" :title="r.enabled ? 'Habilitado — clique para desabilitar' : 'Desabilitado — clique para habilitar'" :aria-label="`${r.enabled ? 'Desabilitar' : 'Habilitar'} ${r.key}`" @click.stop="toggle(r)"><i class="pi" :class="r.enabled ? 'pi-check' : 'pi-minus'" /></button></td>
              <td><code>{{ r.key }}</code></td>
              <td>{{ r.name }}<span v-if="r.contractStatus !== 'ok'" class="tm-pill amber" style="margin-left:6px">contrato {{ r.contractStatus === 'changed' ? 'alterado' : 'removido' }}</span></td>
              <td><span class="tm-pill" :class="METHOD_TONE[r.method]">{{ r.method }}</span> <code class="path">{{ r.path }}</code><div class="tm-hint">{{ r.connection.name }}</div></td>
              <td>{{ r.tag ?? '—' }}</td>
              <td><span class="tm-pill" :class="r.source === 'swagger' ? 'blue' : 'neutral'">{{ r.source === 'swagger' ? 'Swagger' : 'Manual' }}</span></td>
              <td><span :class="r.hasResponseFields ? 'ok' : 'tm-muted'">{{ r.hasResponseFields ? 'definida' : 'pendente' }}</span></td>
              <td>
                <span v-if="r.lookupExposed" class="tm-pill green">consulta</span>
                <span v-if="r.boundTo" class="tm-pill brand" style="margin-left:3px">{{ r.boundTo }}</span>
                <span v-if="r.kind === 'write'" class="tm-pill" :class="r.connection.allowWrites ? 'amber' : 'red'" style="margin-left:3px">{{ r.connection.allowWrites ? 'escrita' : 'escrita bloqueada' }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="pages > 1" class="pager">
        <button class="tm-btn small" :disabled="page === 1" @click="page--">‹</button>
        <span>{{ page }} / {{ pages }}</span>
        <button class="tm-btn small" :disabled="page === pages" @click="page++">›</button>
      </div>
    </section>
  </div>

  <EndpointWizard v-if="wizard" :endpoint-id="wizard.id" :connections="props.connections" @close="wizard = null" @saved="saved" />
</template>

<style scoped>
.layout { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 14px; align-items: start; }
@media (max-width: 1000px) { .layout { grid-template-columns: 1fr; } }
.toolbar { display: flex; gap: 6px; align-items: center; padding: 10px 12px; border-bottom: 1px solid var(--line); }
tr { cursor: default; }
tr.sel td { background: #fffbe6; }
.state { display: grid; place-items: center; width: 26px; height: 26px; border: 1px solid var(--line-strong); border-radius: 6px; background: #fff; color: #9aa6b2; cursor: pointer; }
.state.on { color: var(--green); border-color: color-mix(in srgb, var(--green) 40%, #fff); background: var(--green-soft); }
.path { font-size: 11px; }
.ok { color: var(--green); font-weight: 600; }
.pager { display: flex; gap: 8px; align-items: center; justify-content: flex-end; padding: 10px 12px; font-size: 12px; }
code { font-size: 11px; background: #eef2f5; padding: 1px 4px; border-radius: 4px; overflow-wrap: anywhere; }
</style>
