<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Dialog from 'primevue/dialog'
import Drawer from 'primevue/drawer'
import { api, errorMessage } from '@/api/http'
import { useSession } from '@/stores/session'
import { useNotify } from '@/composables/notify'
import NewLocalUserDialog from './NewLocalUserDialog.vue'
import UserDetailPanel from './UserDetailPanel.vue'
import UserFilters from './UserFilters.vue'
import UserList from './UserList.vue'
import { useUserPhotos } from './photos'
import { emptyFilters } from './types'
import type { ActivityItem, Facets, PageMeta, ProfileTarget, UserDetail, UserFilters as Filters, UserRow } from './types'

const route = useRoute()
const router = useRouter()
const session = useSession()
const notify = useNotify()
const canManage = computed(() => session.can('settingsManage'))
const photos = useUserPhotos()
const photoFor = (u: UserRow) => photos.get(u.id, u.photo?.version)
const detailPhoto = computed(() => (detail.value ? photos.get(detail.value.identity.id, detail.value.identity.photo?.version) : null))

const filters = ref<Filters>(emptyFilters())
const users = ref<UserRow[]>([])
const meta = ref<PageMeta>({ currentPage: 1, lastPage: 1, perPage: 25, total: 0 })
const facets = ref<Facets | null>(null)
const loading = ref(false)
const error = ref('')
const selectedId = ref<string | null>(null)
const detail = ref<UserDetail | null>(null)
const detailLoading = ref(false)
const busy = ref(false)
const loadingActivity = ref(false)
const newOpen = ref(false)
const filtersOpen = ref(false)
const expanded = ref(false)
const wide = ref(typeof window.matchMedia === 'function' ? window.matchMedia('(min-width: 1280px)').matches : true)

const selectedRow = computed(() => users.value.find((u) => u.id === selectedId.value) ?? null)
const activeFilters = computed(() => Object.values(filters.value).filter(Boolean).length)

let listSeq = 0
let detailSeq = 0
let timer: number | undefined
let lastQ = ''
let media: MediaQueryList | null = null
const onMedia = (e: MediaQueryListEvent) => { wide.value = e.matches }

function queryParams(page: number) {
  const query: Record<string, string | number> = { page, perPage: 25 }
  for (const [k, v] of Object.entries(filters.value)) if (v) query[k] = v
  return query
}

async function loadUsers(page = 1) {
  const seq = ++listSeq
  loading.value = true
  error.value = ''
  try {
    const res = await api<{ data: UserRow[]; meta: PageMeta }>('/admin/managed-users', { query: queryParams(page) })
    if (seq !== listSeq) return
    users.value = res.data
    meta.value = res.meta
  } catch (e) {
    if (seq === listSeq) error.value = errorMessage(e)
  } finally {
    if (seq === listSeq) loading.value = false
  }
}

async function loadFacets() {
  try { facets.value = (await api<{ data: Facets }>('/admin/managed-users/facets')).data } catch { /* os filtros seguem sem contagem */ }
}

async function fetchDetail(id: string, silent = false) {
  const seq = ++detailSeq
  if (!silent) { detail.value = null; detailLoading.value = true }
  try {
    const res = await api<{ data: UserDetail }>(`/admin/managed-users/${id}`)
    if (seq !== detailSeq) return
    detail.value = res.data
  } catch (e) {
    if (seq === detailSeq) { if (!silent) detail.value = null; notify.error(e) }
  } finally {
    if (seq === detailSeq) detailLoading.value = false
  }
}

async function openUser(id: string) {
  selectedId.value = id
  await fetchDetail(id)
  await nextTick()
  document.getElementById('user-detail-title')?.focus()
}

function select(u: ProfileTarget) {
  if (u.id === selectedId.value) return
  void router.replace({ query: { ...route.query, user: u.id } })
}

/** Alterna entre o painel lateral e o perfil ampliado (modal), mantendo o usuário selecionado. */
function setExpanded(value: boolean) {
  expanded.value = value
  if (!value) void nextTick(() => document.getElementById('user-detail-title')?.focus())
}

function close() {
  const id = selectedId.value
  expanded.value = false
  selectedId.value = null
  detail.value = null
  const { user: _user, ...rest } = route.query
  void router.replace({ query: rest })
  void nextTick(() => document.querySelector<HTMLElement>(`[data-user-id="${id}"]`)?.focus())
}

async function refresh() {
  await Promise.all([loadUsers(meta.value.currentPage), loadFacets(), selectedId.value ? fetchDetail(selectedId.value, true) : Promise.resolve()])
}

async function toggle() {
  const d = detail.value
  if (!d) return
  const active = !d.account.active
  if (!window.confirm(`${active ? 'Ativar' : 'Desativar'} a conta de ${d.identity.name}?`)) return
  busy.value = true
  try { await api(`/admin/managed-users/${d.identity.id}`, { method: 'PATCH', body: { active } }); notify.success('Estado da conta atualizado.'); await refresh() } catch (e) { notify.error(e) } finally { busy.value = false }
}

async function resend() {
  const d = detail.value
  if (!d) return
  busy.value = true
  try { const r = await api<{ message: string }>(`/admin/managed-users/${d.identity.id}/invitation`, { method: 'POST' }); notify.success(r.message); await refresh() } catch (e) { notify.error(e) } finally { busy.value = false }
}

async function changeCategory(userCategory: string) {
  const d = detail.value
  if (!d) return
  busy.value = true
  try { await api(`/admin/managed-users/${d.identity.id}`, { method: 'PATCH', body: { userCategory } }); notify.success('Tipo de usuário atualizado.'); await refresh() } catch (e) { notify.error(e) } finally { busy.value = false }
}

async function refreshPhoto() {
  const d = detail.value
  if (!d) return
  busy.value = true
  try { await api(`/admin/managed-users/${d.identity.id}/photo/refresh`, { method: 'POST' }); notify.info('Atualização da foto solicitada; o worker a processa em instantes.') } catch (e) { notify.error(e) } finally { busy.value = false }
}

async function moreActivity() {
  const d = detail.value
  if (!d || loadingActivity.value) return
  loadingActivity.value = true
  try {
    const page = d.activity.meta.currentPage + 1
    const res = await api<{ data: ActivityItem[]; meta: UserDetail['activity']['meta'] }>(`/admin/managed-users/${d.identity.id}/activity`, { query: { page } })
    if (detail.value?.identity.id === d.identity.id) detail.value = { ...detail.value, activity: { data: [...detail.value.activity.data, ...res.data], meta: res.meta } }
  } catch (e) { notify.error(e) } finally { loadingActivity.value = false }
}

function onCreated() { void loadUsers(1); void loadFacets() }

watch(filters, (f) => {
  window.clearTimeout(timer)
  const qChanged = f.q !== lastQ
  lastQ = f.q
  if (qChanged) timer = window.setTimeout(() => void loadUsers(1), 300)
  else void loadUsers(1)
}, { deep: true })

watch(() => route.query.user, (id) => {
  if (typeof id === 'string' && id) { if (id !== selectedId.value || !detail.value) void openUser(id) } else { selectedId.value = null; detail.value = null }
})

onMounted(() => {
  if (typeof window.matchMedia === 'function') { media = window.matchMedia('(min-width: 1280px)'); media.addEventListener('change', onMedia) }
  if (!canManage.value) return
  void loadUsers(1)
  void loadFacets()
  const id = route.query.user
  if (typeof id === 'string' && id) void openUser(id)
})
onBeforeUnmount(() => { window.clearTimeout(timer); media?.removeEventListener('change', onMedia) })
</script>

<template>
  <div v-if="!canManage" class="tm-panel"><div class="tm-note" style="margin:14px" role="status"><i class="pi pi-lock" aria-hidden="true" /><span>A administração de usuários exige a permissão <code>settings.manage</code>. Peça acesso a um administrador.</span></div></div>
  <div v-else class="users-ws">
    <div class="filters-bar">
      <button type="button" class="tm-btn small filters-toggle" :aria-expanded="filtersOpen" aria-controls="user-filters-wrap" @click="filtersOpen = !filtersOpen"><i class="pi pi-filter" aria-hidden="true" /> Filtros<span v-if="activeFilters"> ({{ activeFilters }})</span></button>
      <div id="user-filters-wrap" class="filters-wrap" :class="{ open: filtersOpen }">
        <UserFilters v-model="filters" :facets="facets" :total="meta.total" @clear="filters = emptyFilters()" />
      </div>
    </div>
  <div class="users-grid" :class="{ 'has-detail': wide && selectedId && !expanded }">
    <UserList :users="users" :selected-id="selectedId" :loading="loading" :error="error" :meta="meta" :can-create="canManage" :photo-for="photoFor" @select="select" @page="loadUsers" @create="newOpen = true" />
    <aside v-if="wide && selectedId && !expanded" class="tm-panel detail-col" aria-label="Perfil do usuário selecionado" @keydown.esc="close">
      <UserDetailPanel :row="selectedRow" :detail="detail" :loading="detailLoading" :can-manage="canManage" :busy="busy" :loading-activity="loadingActivity" :photo="detailPhoto" @close="close" @select="(id) => select({ id, name: '' })" @toggle="toggle" @resend="resend" @change-category="changeCategory" @more-activity="moreActivity" @refresh-photo="refreshPhoto" @expand="setExpanded" />
    </aside>
    <Drawer v-if="!wide" :visible="!!selectedId && !expanded" position="right" :style="{ width: 'min(460px, 100vw)' }" :show-close-icon="false" :dismissable="true" @update:visible="(v: boolean) => { if (!v && !expanded) close() }">
      <UserDetailPanel :row="selectedRow" :detail="detail" :loading="detailLoading" :can-manage="canManage" :busy="busy" :loading-activity="loadingActivity" :photo="detailPhoto" @close="close" @select="(id) => select({ id, name: '' })" @toggle="toggle" @resend="resend" @change-category="changeCategory" @more-activity="moreActivity" @refresh-photo="refreshPhoto" @expand="setExpanded" />
    </Drawer>
    <Dialog :visible="!!selectedId && expanded" modal dismissable-mask :show-header="false" :style="{ width: 'min(1120px, 96vw)' }" :pt="{ content: { style: 'padding: 0' } }" aria-label="Perfil do usuário (ampliado)" @update:visible="(v: boolean) => { if (!v) setExpanded(false) }">
      <UserDetailPanel :row="selectedRow" :detail="detail" :loading="detailLoading" :can-manage="canManage" :busy="busy" :loading-activity="loadingActivity" :photo="detailPhoto" expanded @close="close" @select="(id) => select({ id, name: '' })" @toggle="toggle" @resend="resend" @change-category="changeCategory" @more-activity="moreActivity" @refresh-photo="refreshPhoto" @expand="setExpanded" />
    </Dialog>
    <NewLocalUserDialog v-model:visible="newOpen" @created="onCreated" />
  </div>
  </div>
</template>

<style scoped>
.users-ws { display: grid; gap: 14px; }
.users-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 14px; align-items: start; }
.users-grid.has-detail { grid-template-columns: minmax(0, 1fr) minmax(360px, 440px); }
.detail-col { position: sticky; top: 80px; max-height: calc(100vh - 96px); overflow: auto; }
.filters-toggle { display: none; }
@media (max-width: 759px) {
  .filters-toggle { display: inline-flex; }
  .filters-wrap { display: none; margin-top: 8px; }
  .filters-wrap.open { display: block; }
}
</style>
