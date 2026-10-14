<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api/http'
import type { CatalogItem } from '@/api/types'
import { useSession } from '@/stores/session'
import { useNotify } from '@/composables/notify'
import { fmtDateTime } from '@/composables/format'
import NewFlowDialog from '@/components/NewFlowDialog.vue'
import StatusPill from '@/components/StatusPill.vue'
import DeleteFlowDialog from '@/components/DeleteFlowDialog.vue'

const route = useRoute()
const router = useRouter()
const session = useSession()
const notify = useNotify()
const items = ref<CatalogItem[]>([])
const loading = ref(true)
const search = ref('')
const category = ref((route.query.category as string) || 'Todas')
const showNew = ref(false)
// fluxo nunca publicado e sem solicitações pode ser excluído (o servidor confirma as regras)
const deleting = ref<CatalogItem | null>(null)

const filtered = computed(() => items.value.filter((f) =>
  (category.value === 'Todas' || f.category === category.value) &&
  (!search.value || `${f.name} ${f.description ?? ''}`.toLowerCase().includes(search.value.toLowerCase())),
))

async function load() {
  loading.value = true
  try {
    items.value = (await api<{ data: CatalogItem[] }>('/catalog/processes')).data
  } catch (e) {
    notify.error(e)
  } finally {
    loading.value = false
  }
}

watch(() => route.query.category, (c) => { category.value = (c as string) || 'Todas' })
watch(category, (c) => { if ((route.query.category || 'Todas') !== c) router.replace({ query: c === 'Todas' ? {} : { category: c } }) })
onMounted(load)

function versionStatus(f: CatalogItem) {
  if (f.publishedVersion) return { status: 'published', version: f.publishedVersion.version, deployed: f.publishedVersion.deployed }
  return { status: 'draft', version: f.latestVersion?.version ?? 1, deployed: false }
}
</script>

<template>
  <div class="tm-crumbs"><span>Início</span><span class="sep">›</span><strong style="color:#36495c">Catálogo de fluxos</strong></div>
  <div class="tm-heading">
    <div>
      <h1>Processos de negócio</h1>
      <p class="tm-subtitle">Encontre um fluxo para iniciar uma solicitação, acompanhar as que estão em andamento ou consultar respostas.</p>
    </div>
    <button v-if="session.can('design')" class="tm-btn primary" @click="showNew = true"><i class="pi pi-plus" /> Novo fluxo</button>
  </div>

  <div class="tm-toolbar">
    <div class="tm-toolbar-left">
      <select v-model="category" class="tm-select" aria-label="Filtrar por área">
        <option>Todas</option>
        <option v-for="c in session.meta?.categories" :key="c">{{ c }}</option>
      </select>
      <span class="tm-muted" style="font-size:12px">{{ filtered.length }} fluxo(s)</span>
    </div>
    <input v-model="search" class="tm-input" placeholder="Buscar processo..." aria-label="Buscar processo" />
  </div>
  <div class="tm-table-wrap">
    <table class="tm-table">
      <thead>
        <tr><th style="width:38%">Fluxo</th><th>Área</th><th>Versão / estado</th><th>Instâncias</th><th>Atualizado</th><th>Ações</th></tr>
      </thead>
      <tbody>
        <tr v-if="loading"><td colspan="6" class="tm-empty">Carregando...</td></tr>
        <tr v-else-if="!filtered.length"><td colspan="6"><div class="tm-empty"><strong>Nenhum fluxo encontrado</strong>Crie um novo fluxo ou ajuste os filtros.</div></td></tr>
        <tr v-for="f in filtered" :key="f.key">
          <td>
            <div class="tm-flow-cell">
              <div class="tm-flow-icon"><i class="pi pi-sitemap" /></div>
              <div>
                <div class="tm-flow-title">{{ f.name }}</div>
                <div class="tm-flow-sub">{{ f.description || 'Processo BPMN configurável' }}</div>
              </div>
            </div>
          </td>
          <td>{{ f.category }}</td>
          <td>
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
              <span class="tm-muted" style="font-size:11px">v{{ versionStatus(f).version }}</span>
              <StatusPill kind="version" :status="versionStatus(f).status" />
              <span v-if="versionStatus(f).status === 'published' && !versionStatus(f).deployed" class="tm-pill amber" v-tooltip="'Aguardando confirmação do engine'">Implantando</span>
              <span v-if="f.hasDraft && f.publishedVersion" class="tm-pill neutral" v-tooltip="'Há um rascunho em edição'">v{{ f.draftVersion }} rascunho</span>
            </div>
          </td>
          <td>{{ f.instances }} <span v-if="f.openInstances" class="tm-muted">({{ f.openInstances }} abertas)</span></td>
          <td>{{ fmtDateTime(f.updatedAt) }}</td>
          <td>
            <div class="tm-actions">
              <RouterLink v-if="f.canStart" class="tm-btn small primary" :to="{ name: 'flow-start', params: { flowKey: f.key } }"><i class="pi pi-plus" /> Novo</RouterLink>
              <RouterLink v-if="f.publishedVersion" class="tm-btn small" :to="{ name: 'monitor', query: { flow: f.key } }">Acompanhar</RouterLink>
              <RouterLink v-if="f.publishedVersion" class="tm-btn small" :to="{ name: 'flow-results', params: { flowKey: f.key } }">Respostas</RouterLink>
              <RouterLink v-if="session.can('design') || session.can('publish')" class="tm-btn small icon" :to="{ name: 'flow-editor', params: { flowKey: f.key } }" v-tooltip.top="'Editar fluxo'" aria-label="Editar fluxo"><i class="pi pi-pencil" /></RouterLink>
              <button v-if="session.can('design') && !f.publishedVersion && !f.instances" class="tm-btn small icon danger" v-tooltip.top="'Excluir fluxo em rascunho'" :aria-label="`Excluir fluxo ${f.name}`" @click="deleting = f"><i class="pi pi-trash" /></button>
            </div>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
  <div class="tm-note">
    <i class="pi pi-info-circle" />
    <span><strong>Como funciona:</strong> um fluxo organiza as etapas; cada etapa humana tem seu próprio formulário. Ao criar um novo fluxo, a primeira etapa e o formulário já aparecem associados no editor. Use <b>Novo</b> para abrir uma solicitação.</span>
  </div>

  <NewFlowDialog v-model:visible="showNew" />
  <DeleteFlowDialog v-if="deleting" :flow="deleting" mode="flow" @close="deleting = null" @deleted="() => { deleting = null; load() }" />
</template>
