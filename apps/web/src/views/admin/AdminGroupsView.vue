<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import AutoComplete from 'primevue/autocomplete'
import Button from 'primevue/button'
import { api, errorMessage } from '@/api/http'
import { useSession } from '@/stores/session'
import { useNotify } from '@/composables/notify'
import { fmtDateTime } from '@/composables/format'

type Tab = 'users' | 'groups' | 'roles'
interface Group { key: string; name: string; description: string | null; entraGroupId: string | null; email: string | null; members: number }
interface UserRow { id: string; name: string; email: string | null; username: string | null; area: string | null; department: string | null; authSource: string | null; roles: string[]; groups: string[]; isActive: boolean; invitationPending: boolean; lastSeenAt: string | null }
interface PageMeta { currentPage: number; lastPage: number; total: number }
interface Role { key: string; alias: string; name: string; active: boolean; userCount: number; permissions: { key: string; source: string }[]; users: { id: string; name: string; authSource: string; source: string; manual: boolean }[]; directoryMappings: { group: string | null; entraGroupId: string }[] }

const session = useSession()
const notify = useNotify()
const tabs: { id: Tab; label: string }[] = [{ id: 'users', label: 'Usuários' }, { id: 'groups', label: 'Grupos/Raias' }, { id: 'roles', label: 'Papéis' }]
const activeTab = ref<Tab>('users')
const users = ref<UserRow[]>([])
const groups = ref<Group[]>([])
const roles = ref<Role[]>([])
const meta = ref<PageMeta>({ currentPage: 1, lastPage: 1, total: 0 })
const loading = ref(false)
const error = ref('')
const search = ref('')
const sourceFilter = ref('')
const statusFilter = ref('')
const areaFilter = ref('')
const groupFilter = ref('')
const roleFilter = ref('')
const sourceOptions = [
  { label: 'Todas as origens', value: '' }, { label: 'Microsoft Entra ID', value: 'entra' },
  { label: 'Conta local', value: 'local' }, { label: 'Visitante', value: 'demo' }, { label: 'SuperAdmin', value: 'superadmin' },
]
const statusOptions = [{ label: 'Todos os estados', value: '' }, { label: 'Ativos', value: 'true' }, { label: 'Inativos', value: 'false' }]
const groupFilterOptions = computed(() => [{ label: 'Todos os grupos', value: '' }, ...(session.meta?.groups ?? []).map((group) => ({ label: group.name, value: group.key }))])
const gateDefinitions = [
  { key: 'settings.manage', description: 'Permite administrar configurações globais, identidade visual, Entra ID, e-mail, retenção e gestão de usuários, grupos e papéis.' },
  { key: 'processes.manage', description: 'Permite operar processos: reatribuir tarefas, reprocessar instâncias e administrar notificações operacionais.' },
  { key: 'audit.read', description: 'Permite consultar eventos e registros de auditoria da aplicação.' },
  { key: 'audit.export', description: 'Permite exportar dados de auditoria; é uma permissão separada da leitura para segregação de funções.' },
  { key: 'reports.instance', description: 'Permite consultar relatórios no escopo das instâncias de processo às quais o usuário tem acesso.' },
  { key: 'reports.operational', description: 'Permite consultar relatórios operacionais consolidados da operação.' },
  { key: 'reports.templates', description: 'Permite administrar modelos e configurações de relatórios.' },
  { key: 'data.sensitive', description: 'Permite visualizar campos classificados como sensíveis em relatórios e respostas.' },
]
const rolePage = ref(1)
const selectedUser = ref<UserRow | null>(null)
const profile = ref<Record<string, any> | null>(null)
const profileLoading = ref(false)
const editing = ref<Group | null>(null)
const membersGroup = ref<Group | null>(null)
const groupMembers = ref<any[]>([])
const candidateUsers = ref<UserRow[]>([])
const memberLookup = ref<UserRow | null>(null)
const selectedUserIds = ref<string[]>([])
const selectedGroupUsers = ref<UserRow[]>([])
const memberCandidatesError = ref('')
const loadingCandidates = ref(false)
const roleMembership = ref<Role | null>(null)
const roleCandidateUsers = ref<UserRow[]>([])
const roleLookup = ref<UserRow | null>(null)
const selectedRoleUserIds = ref<string[]>([])
const selectedRoleUsers = ref<UserRow[]>([])
const roleCandidatesError = ref('')
const loadingRoleCandidates = ref(false)
const savingRoleMembers = ref(false)
const expandedRoleKey = ref<string | null>(null)
const newGroupOpen = ref(false)
const creatingGroup = ref(false)
const newGroupDraft = ref({ key: '', name: '', description: '', entraGroupId: '', email: '' })
const newUserOpen = ref(false)
const newUser = ref({ name: '', email: '', username: '', area: '', department: '' })
const creatingUser = ref(false)

async function loadUsers(page = 1) {
  loading.value = true; error.value = ''
  try {
    const res = await api<{ data: UserRow[]; meta: PageMeta }>('/admin/managed-users', { query: { q: search.value, source: sourceFilter.value, active: statusFilter.value, area: areaFilter.value, group: groupFilter.value, role: roleFilter.value, page, perPage: 25 } })
    users.value = res.data; meta.value = res.meta
  } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
async function loadGroups() {
  loading.value = true; error.value = ''
  try { groups.value = (await api<{ data: Group[] }>('/admin/groups')).data } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
async function loadRoles(page = 1) {
  loading.value = true; error.value = ''
  try { roles.value = (await api<{ data: Role[] }>('/admin/roles', { query: { page, perPage: 25 } })).data; rolePage.value = page } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
function selectTab(tab: Tab) {
  activeTab.value = tab
  if (tab === 'users') void loadUsers()
  if (tab === 'groups') void loadGroups()
  if (tab === 'roles') void loadRoles()
}
function onTabKeydown(event: KeyboardEvent, index: number) {
  let next = index
  if (event.key === 'ArrowRight') next = (index + 1) % tabs.length
  else if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length
  else if (event.key === 'Home') next = 0
  else if (event.key === 'End') next = tabs.length - 1
  else return
  event.preventDefault()
  const tab = tabs[next]
  const button = (event.currentTarget as HTMLElement).parentElement?.querySelectorAll<HTMLButtonElement>('[role="tab"]')[next]
  button?.focus(); selectTab(tab.id)
}
async function openProfile(user: UserRow) {
  selectedUser.value = user; profile.value = null; profileLoading.value = true
  try { profile.value = (await api<{ data: Record<string, any> }>(`/admin/managed-users/${user.id}`)).data } catch (e) { notify.error(e) } finally { profileLoading.value = false }
}
async function saveGroup() {
  if (!editing.value) return
  try {
    await api(`/admin/groups/${editing.value.key}`, { method: 'PATCH', body: { name: editing.value.name, description: editing.value.description, entraGroupId: editing.value.entraGroupId || null, email: editing.value.email || null } })
    notify.success('Grupo atualizado.'); editing.value = null; await loadGroups()
  } catch (e) { notify.error(e) }
}
async function openMembers(group: Group) {
  newGroupOpen.value = false
  membersGroup.value = group; groupMembers.value = []; candidateUsers.value = []; memberLookup.value = null; selectedUserIds.value = []; selectedGroupUsers.value = []
  memberCandidatesError.value = ''
  try {
    const res = await api<{ data: any[] }>(`/admin/groups/${group.key}/members`)
    groupMembers.value = res.data
  } catch (e) { notify.error(e) }
}
async function searchCandidates(event: { query: string }) {
  const query = event.query.trim()
  if (query.length < 2) { candidateUsers.value = []; return }
  loadingCandidates.value = true
  try {
    memberCandidatesError.value = ''
    const existingIds = new Set([...(membersGroup.value ? groupMembers.value.map((member) => member.id) : []), ...selectedUserIds.value])
    candidateUsers.value = (await api<{ data: UserRow[] }>('/admin/managed-users', { query: { q: query, active: true, perPage: 50 } })).data.filter((u) => ['local', 'entra'].includes(u.authSource ?? '') && !existingIds.has(u.id))
  } catch (e) { memberCandidatesError.value = errorMessage(e) } finally { loadingCandidates.value = false }
}
function selectGroupCandidate(event: { value: UserRow }) {
  const user = event.value
  if (!selectedUserIds.value.includes(user.id)) {
    selectedUserIds.value = [...selectedUserIds.value, user.id]
    selectedGroupUsers.value = [...selectedGroupUsers.value, user]
  }
  memberLookup.value = null
  candidateUsers.value = []
}
function removeSelectedGroupUser(user: UserRow) {
  selectedUserIds.value = selectedUserIds.value.filter((id) => id !== user.id)
  selectedGroupUsers.value = selectedGroupUsers.value.filter((item) => item.id !== user.id)
}
async function addMembers() {
  if (!membersGroup.value || !selectedUserIds.value.length) return
  try {
    await api(`/admin/groups/${membersGroup.value.key}/members`, { method: 'POST', body: { userIds: selectedUserIds.value } })
    notify.success('Membros adicionados ao grupo TMSA.'); selectedUserIds.value = []; await openMembers(membersGroup.value); await loadGroups()
  } catch (e) { notify.error(e) }
}
async function removeMember(member: any) {
  if (!membersGroup.value || !window.confirm(`Remover o vínculo manual de ${member.name}?`)) return
  try {
    await api(`/admin/groups/${membersGroup.value.key}/members/${member.id}`, { method: 'DELETE' })
    notify.success('Vínculo manual removido.'); await openMembers(membersGroup.value); await loadGroups()
  } catch (e) { notify.error(e) }
}
async function openRoleMembers(role: Role) {
  roleMembership.value = role; roleCandidateUsers.value = []; roleLookup.value = null; selectedRoleUserIds.value = []; selectedRoleUsers.value = []; roleCandidatesError.value = ''
}
async function searchRoleCandidates(event: { query: string }) {
  const query = event.query.trim()
  if (query.length < 2) { roleCandidateUsers.value = []; return }
  loadingRoleCandidates.value = true
  try {
    roleCandidatesError.value = ''
    const existingIds = new Set([...(roleMembership.value?.users ?? []).map((user) => user.id), ...selectedRoleUserIds.value])
    roleCandidateUsers.value = (await api<{ data: UserRow[] }>('/admin/managed-users', { query: { q: query, active: true, perPage: 50 } })).data.filter((u) => ['local', 'entra'].includes(u.authSource ?? '') && !existingIds.has(u.id))
  } catch (e) { roleCandidatesError.value = errorMessage(e) } finally { loadingRoleCandidates.value = false }
}
function selectRoleCandidate(event: { value: UserRow }) {
  const user = event.value
  if (!selectedRoleUserIds.value.includes(user.id)) {
    selectedRoleUserIds.value = [...selectedRoleUserIds.value, user.id]
    selectedRoleUsers.value = [...selectedRoleUsers.value, user]
  }
  roleLookup.value = null
  roleCandidateUsers.value = []
}
function removeSelectedRoleUser(user: UserRow) {
  selectedRoleUserIds.value = selectedRoleUserIds.value.filter((id) => id !== user.id)
  selectedRoleUsers.value = selectedRoleUsers.value.filter((item) => item.id !== user.id)
}
async function addRoleMembers() {
  if (!roleMembership.value || !selectedRoleUserIds.value.length) return
  savingRoleMembers.value = true
  try {
    await api(`/admin/roles/${encodeURIComponent(roleMembership.value.key)}/users`, { method: 'POST', body: { userIds: selectedRoleUserIds.value } })
    notify.success('Usuários adicionados ao papel.')
    selectedRoleUserIds.value = []; selectedRoleUsers.value = []
    const roleKey = roleMembership.value.key
    await loadRoles(rolePage.value)
    roleMembership.value = roles.value.find((role) => role.key === roleKey) ?? roleMembership.value
    roleCandidateUsers.value = []
  } catch (e) { notify.error(e) } finally { savingRoleMembers.value = false }
}
async function removeRoleMember(role: Role, member: Role['users'][number]) {
  if (!member.manual || !window.confirm(`Remover a atribuição manual de ${role.name} para ${member.name}?`)) return
  try {
    await api(`/admin/roles/${encodeURIComponent(role.key)}/users/${member.id}`, { method: 'DELETE' })
    notify.success('Atribuição manual removida.')
    const roleKey = role.key
    await loadRoles(rolePage.value)
    if (roleMembership.value?.key === roleKey) roleMembership.value = roles.value.find((item) => item.key === roleKey) ?? roleMembership.value
  } catch (e) { notify.error(e) }
}
function toggleExpandedRole(key: string) {
  expandedRoleKey.value = expandedRoleKey.value === key ? null : key
}
function startNewGroup() {
  membersGroup.value = null
  groupMembers.value = []
  newGroupDraft.value = { key: '', name: '', description: '', entraGroupId: '', email: '' }
  memberLookup.value = null; candidateUsers.value = []; selectedUserIds.value = []; selectedGroupUsers.value = []; memberCandidatesError.value = ''
  newGroupOpen.value = true
}
async function createGroup() {
  const key = newGroupDraft.value.key.trim().toUpperCase()
  if (!/^[A-Z][A-Z0-9_]{0,39}$/.test(key)) { notify.error('Use uma chave iniciada por letra, com letras, números ou _ (até 40 caracteres).'); return }
  creatingGroup.value = true
  let created = false
  try {
    await api('/admin/groups', { method: 'POST', body: { ...newGroupDraft.value, key, description: newGroupDraft.value.description || null, entraGroupId: newGroupDraft.value.entraGroupId || null, email: newGroupDraft.value.email || null } })
    created = true
    if (selectedUserIds.value.length) await api(`/admin/groups/${encodeURIComponent(key)}/members`, { method: 'POST', body: { userIds: selectedUserIds.value } })
    notify.success('Grupo/raia criado e membros vinculados.')
    newGroupOpen.value = false; await loadGroups()
  } catch (e) {
    if (created) {
      newGroupOpen.value = false; await loadGroups()
      notify.error(`O grupo ${key} foi criado, mas não foi possível vincular todos os membros. Abra “Membros” para concluir a associação. ${errorMessage(e)}`)
    } else notify.error(e)
  } finally { creatingGroup.value = false }
}
async function createLocalUser() {
  creatingUser.value = true
  try {
    const res = await api<{ message: string }>('/admin/managed-users', { method: 'POST', body: newUser.value })
    notify.success(res.message || 'Conta criada pendente de ativação.')
    newUser.value = { name: '', email: '', username: '', area: '', department: '' }; newUserOpen.value = false; await loadUsers()
  } catch (e) { notify.error(e) } finally { creatingUser.value = false }
}
async function toggleUser(user: UserRow) {
  if (user.authSource !== 'local') return
  const active = !user.isActive
  if (!window.confirm(`${active ? 'Ativar' : 'Desativar'} a conta de ${user.name}?`)) return
  try { await api(`/admin/managed-users/${user.id}`, { method: 'PATCH', body: { active } }); notify.success('Estado da conta atualizado.'); await loadUsers(meta.value.currentPage) } catch (e) { notify.error(e) }
}
async function resendInvitation(user: UserRow) {
  try { const result = await api<{ message: string }>(`/admin/managed-users/${user.id}/invitation`, { method: 'POST' }); notify.success(result.message); await loadUsers(meta.value.currentPage) } catch (e) { notify.error(e) }
}
watch([search, sourceFilter, statusFilter, areaFilter, groupFilter, roleFilter], () => { if (activeTab.value === 'users') void loadUsers(1) })
onMounted(() => { void loadUsers() })
</script>

<template>
  <div class="tm-crumbs"><span>Administração</span><span class="sep">›</span><span>Usuários e grupos</span></div>
  <div class="tm-heading"><div><h1>Usuários e grupos</h1><p class="tm-subtitle">Gerencie contas locais, consulte identidades Entra e administre grupos internos do BPM TMSA.</p></div></div>

<div class="tm-tabbar" role="tablist" aria-label="Administração de identidades">
    <button v-for="(item, index) in tabs" :id="`identity-tab-${item.id}`" :key="item.id" class="tm-tab" :class="{ active: activeTab === item.id }" role="tab" :aria-selected="activeTab === item.id" :aria-controls="`identity-panel-${item.id}`" :tabindex="activeTab === item.id ? 0 : -1" @click="selectTab(item.id)" @keydown="onTabKeydown($event, index)">{{ item.label }}</button>
  </div>

  <section v-if="activeTab === 'users'" id="identity-panel-users" role="tabpanel" aria-labelledby="identity-tab-users" tabindex="0" class="tm-panel">
    <div class="tm-panel-head"><div><div class="tm-panel-title">Usuários</div><div class="tm-panel-caption">{{ meta.total }} identidade(s) · busca e filtros no servidor</div></div><button v-if="session.can('settingsManage')" class="tm-btn primary" @click="newUserOpen = true">Novo usuário</button></div>
    <div class="tm-filter-row">
      <InputText v-model="search" class="tm-filter-search" aria-label="Buscar usuário por nome, e-mail ou identificador" placeholder="Buscar nome, e-mail ou usuário" />
      <Select v-model="sourceFilter" class="tm-filter-select" :options="sourceOptions" option-label="label" option-value="value" aria-label="Filtrar por origem" />
      <Select v-model="statusFilter" class="tm-filter-select" :options="statusOptions" option-label="label" option-value="value" aria-label="Filtrar por situação" />
      <InputText v-model="areaFilter" class="tm-filter-input" aria-label="Filtrar por área" placeholder="Área" />
      <Select v-model="groupFilter" class="tm-filter-select" :options="groupFilterOptions" option-label="label" option-value="value" aria-label="Filtrar por grupo" />
      <InputText v-model="roleFilter" class="tm-filter-input" aria-label="Filtrar por papel técnico" placeholder="Papel técnico" />
    </div>
    <p v-if="error" class="tm-error" role="alert">{{ error }}</p>
    <p v-else-if="loading" class="tm-empty" role="status">Carregando usuários…</p>
    <div v-else class="tm-table-wrap"><table class="tm-table" style="min-width:0"><thead><tr><th>Nome</th><th>E-mail / usuário</th><th>Origem</th><th>Estado</th><th>Grupos</th><th>Papéis</th><th>Último acesso</th><th>Ações</th></tr></thead><tbody>
      <tr v-for="u in users" :key="u.id"><td class="tm-strong">{{ u.name }}<div class="tm-flow-sub">{{ u.department || u.area || 'Centro de Custo não informado' }}</div></td><td>{{ u.email || u.username || '—' }}<div class="tm-flow-sub">{{ u.username }}</div></td><td>{{ u.authSource === 'entra' ? 'Entra ID' : u.authSource === 'local' ? 'Conta local' : u.authSource || 'Indisponível' }}</td><td><span class="tm-pill" :class="u.isActive ? 'green' : 'amber'">{{ u.isActive ? 'Ativo' : u.invitationPending ? 'Convite pendente' : 'Inativo' }}</span></td><td>{{ u.groups?.length ?? 0 }}</td><td>{{ u.roles?.length ?? 0 }}</td><td>{{ fmtDateTime(u.lastSeenAt) }}</td><td><div class="tm-actions"><button class="tm-btn small" @click="openProfile(u)">Perfil</button><button v-if="u.authSource === 'local' && session.can('settingsManage') && !u.isActive && !u.invitationPending" class="tm-btn small" @click="resendInvitation(u)">Reenviar convite</button><button v-if="u.authSource === 'local' && session.can('settingsManage') && (u.isActive || !u.invitationPending)" class="tm-btn small" @click="toggleUser(u)">{{ u.isActive ? 'Desativar' : 'Ativar' }}</button></div></td></tr>
      <tr v-if="!users.length"><td colspan="8" class="tm-empty">Nenhum usuário encontrado.</td></tr>
    </tbody></table></div>
    <div class="tm-pager" style="display:flex;justify-content:flex-end;align-items:center;gap:10px;padding:12px 16px"><span>Página {{ meta.currentPage }} de {{ meta.lastPage }}</span><button class="tm-btn small" :disabled="meta.currentPage <= 1 || loading" @click="loadUsers(meta.currentPage - 1)">Anterior</button><button class="tm-btn small" :disabled="meta.currentPage >= meta.lastPage || loading" @click="loadUsers(meta.currentPage + 1)">Próxima</button></div>
  </section>

  <section v-else-if="activeTab === 'groups'" id="identity-panel-groups" role="tabpanel" aria-labelledby="identity-tab-groups" tabindex="0" class="tm-panel">
    <div class="tm-panel-head"><div><div class="tm-panel-title">Grupos/Raias internos e associações</div><div class="tm-panel-caption">Vínculos manuais afetam somente o BPM TMSA; a sincronização Entra permanece independente. Raias usam hoje a chave global do grupo: o acesso alcança tarefas abertas que compartilham essa chave.</div></div><Button v-if="session.can('settingsManage')" class="tm-btn primary" label="Novo grupo/raia" @click="startNewGroup" /></div>
    <p v-if="error" class="tm-error" role="alert">{{ error }}</p><p v-else-if="loading" class="tm-empty" role="status">Carregando grupos…</p>
    <div v-else class="tm-table-wrap"><table class="tm-table" style="min-width:0"><thead><tr><th>Chave</th><th>Grupo</th><th>Referência Entra</th><th>E-mail</th><th>Membros</th><th>Ações</th></tr></thead><tbody>
      <tr v-for="g in groups" :key="g.key"><td><code>{{ g.key }}</code></td><td class="tm-strong">{{ g.name }}<div class="tm-flow-sub">{{ g.description }}</div></td><td><code v-if="g.entraGroupId">{{ g.entraGroupId }}</code><span v-else class="tm-pill amber">Sem grupo Entra</span></td><td>{{ g.email || '—' }}</td><td>{{ g.members }}</td><td><div class="tm-actions"><button v-if="session.can('settingsManage')" class="tm-btn small" @click="openMembers(g)">Membros</button><button v-if="session.can('settingsManage')" class="tm-btn small" @click="editing = { ...g }">Editar</button></div></td></tr>
      <tr v-if="!groups.length"><td colspan="6" class="tm-empty">Nenhum grupo cadastrado.</td></tr>
    </tbody></table></div>
  </section>

  <section v-else id="identity-panel-roles" role="tabpanel" aria-labelledby="identity-tab-roles" tabindex="0" class="tm-panel">
    <div class="tm-panel-head"><div><div class="tm-panel-title">Papéis e permissões efetivas</div><div class="tm-panel-caption">Atribuições manuais valem somente no BPM TMSA; papéis do token e do Entra permanecem sob gestão das respectivas origens.</div></div></div>
    <section class="tm-gate-legend" aria-labelledby="gate-legend-title"><div class="tm-gate-legend-head"><h2 id="gate-legend-title">Legenda das permissões efetivas (Gates)</h2><p>Um Gate é uma permissão verificada pela API para autorizar uma capacidade. As permissões exibidas em cada papel são as capacidades efetivas daquele papel.</p></div><dl class="tm-gate-list"><div v-for="gate in gateDefinitions" :key="gate.key" class="tm-gate-item"><dt><code>{{ gate.key }}</code></dt><dd>{{ gate.description }}</dd></div></dl></section>
    <p v-if="error" class="tm-error" role="alert">{{ error }}</p><p v-else-if="loading" class="tm-empty" role="status">Carregando papéis…</p>
    <div v-else class="tm-table-wrap"><table class="tm-table" style="min-width:0"><thead><tr><th>Papel</th><th>Permissões efetivas (Gates)</th><th>Usuários relacionados</th><th>Mapeamentos Entra</th></tr></thead><tbody>
      <template v-for="role in roles" :key="role.key"><tr><td class="tm-strong">{{ role.name }}<div class="tm-flow-sub"><code>{{ role.key }}</code> · {{ role.userCount }} usuário(s)</div></td><td><span v-for="p in role.permissions" :key="p.key" class="tm-pill neutral" style="margin:2px">{{ p.key }}</span><span v-if="!role.permissions.length">—</span></td><td><Button class="tm-btn small" :label="`${expandedRoleKey === role.key ? 'Ocultar' : 'Ver'} usuários (${role.userCount})`" :aria-expanded="expandedRoleKey === role.key" @click="toggleExpandedRole(role.key)" /></td><td><div v-for="m in role.directoryMappings" :key="m.entraGroupId">{{ m.group || m.entraGroupId }}</div><span v-if="!role.directoryMappings.length">—</span></td></tr>
        <tr v-if="expandedRoleKey === role.key" class="tm-expanded-role-row"><td colspan="4"><div class="tm-expanded-role"><div class="tm-expanded-role-head"><div><strong>Usuários vinculados a {{ role.name }}</strong><div class="tm-flow-sub">Atribuições efetivas de token, Entra e BPM TMSA.</div></div><Button v-if="session.can('settingsManage')" class="tm-btn primary small" label="Vincular novo usuário" @click="openRoleMembers(role)" /></div><div class="tm-table-wrap"><table class="tm-table tm-nested-table"><thead><tr><th>Usuário</th><th>Origem do vínculo</th><th>Tipo de atribuição</th><th>Ação</th></tr></thead><tbody><tr v-for="u in role.users" :key="u.id"><td class="tm-strong">{{ u.name }}<div class="tm-flow-sub">{{ u.authSource }}</div></td><td>{{ u.source }}</td><td><span class="tm-pill" :class="u.manual ? 'amber' : 'neutral'">{{ u.manual ? 'Manual — BPM TMSA' : 'Efetiva' }}</span></td><td><Button v-if="u.manual && session.can('settingsManage')" class="tm-btn small" label="Remover vínculo" @click="removeRoleMember(role, u)" /><span v-else class="tm-flow-sub">Gerenciado pela origem</span></td></tr><tr v-if="!role.users.length"><td colspan="4" class="tm-empty">Nenhum usuário relacionado nesta página.</td></tr></tbody></table></div></div></td></tr>
      </template>
      <tr v-if="!roles.length"><td colspan="4" class="tm-empty">Nenhum papel configurado.</td></tr>
    </tbody></table></div><div class="tm-pager" style="display:flex;justify-content:flex-end;gap:10px;padding:12px 16px"><button class="tm-btn small" :disabled="rolePage <= 1 || loading" @click="loadRoles(rolePage - 1)">Anterior</button><span>Página {{ rolePage }}</span><button class="tm-btn small" :disabled="roles.every((r) => r.userCount <= rolePage * 25) || loading" @click="loadRoles(rolePage + 1)">Próxima</button></div>
  </section>

  <Dialog :visible="!!selectedUser" modal header="Perfil do usuário" :style="{ width: 'min(760px, 96vw)' }" @update:visible="(v) => { if (!v) { selectedUser = null; profile = null } }">
    <p v-if="profileLoading" role="status">Carregando perfil…</p><template v-else-if="profile">
      <section v-for="(section, key) in profile" :key="key" class="tm-panel" style="margin:0 0 12px;padding:14px"><h2 style="margin:0 0 10px;font-size:15px">{{ key === 'identity' ? 'Identificação' : key === 'organization' ? 'Dados organizacionais' : key === 'account' ? 'Conta e segurança' : key === 'access' ? 'Acesso e origem' : 'Atividade de auditoria' }}</h2>
        <template v-if="key === 'activity'"><div v-for="item in (section as any).data" :key="item.action + item.occurredAt" class="tm-flow-sub">{{ item.label }} · {{ item.actor || 'Sistema' }} · {{ fmtDateTime(item.occurredAt) }}</div><p v-if="!(section as any).data.length">Sem eventos registrados.</p></template>
        <template v-else-if="key === 'access'"><p><strong>Papéis efetivos:</strong> {{ (section as any).roles.join(', ') || '—' }}</p><p><strong>Por token:</strong> {{ (section as any).roleSources.token.join(', ') || '—' }}</p><p><strong>Por mapeamento Entra:</strong> {{ (section as any).roleSources.directoryMapping.join(', ') || '—' }}</p><p><strong>Manuais (BPM TMSA):</strong> {{ (section as any).roleSources.manual.join(', ') || '—' }}</p><p><strong>Grupos efetivos:</strong> {{ (section as any).groups.join(', ') || '—' }}</p><p><strong>Vínculos manuais:</strong> {{ (section as any).groupSources.manual.map((g: any) => `${g.name} (${g.createdBy || 'ator indisponível'})`).join(', ') || '—' }}</p></template>
        <dl v-else style="display:grid;grid-template-columns:minmax(140px, 1fr) 2fr;gap:6px 14px;margin:0"><template v-for="(value, field) in section" :key="field"><dt class="tm-flow-sub">{{ field }}</dt><dd style="margin:0;overflow-wrap:anywhere">{{ value ?? 'Indisponível' }}</dd></template></dl>
      </section>
    </template><p v-else>Não foi possível carregar o perfil.</p>
  </Dialog>

  <Dialog :visible="!!membersGroup" modal header="Membros do grupo TMSA" :style="{ width: 'min(850px, 96vw)' }" @update:visible="(v) => { if (!v) membersGroup = null }">
    <template v-if="membersGroup"><p>{{ membersGroup.name }} · <code>{{ membersGroup.key }}</code>. Alterações manuais não escrevem no Entra ID.</p>
      <div class="tm-table-wrap"><table class="tm-table" style="min-width:0"><thead><tr><th>Usuário</th><th>Origem do vínculo</th><th>Ator / data</th><th>Ação</th></tr></thead><tbody><tr v-for="m in groupMembers" :key="m.id"><td>{{ m.name }}<div class="tm-flow-sub">{{ m.email }}</div></td><td>{{ m.source === 'manual' ? 'Manual — BPM TMSA' : m.source }}</td><td>{{ m.createdBy || '—' }} {{ fmtDateTime(m.createdAt) }}</td><td><button v-if="m.source === 'manual' && session.can('settingsManage')" class="tm-btn small" @click="removeMember(m)">Remover vínculo</button></td></tr><tr v-if="!groupMembers.length"><td colspan="4">Nenhum membro neste grupo.</td></tr></tbody></table></div>
      <div v-if="session.can('settingsManage')" class="tm-panel tm-membership-form" style="margin-top:16px;padding:14px"><h3 class="tm-membership-title">Adicionar usuários ativos</h3><div class="tm-lookup-field"><label for="group-member-lookup">Buscar e selecionar usuário</label><AutoComplete id="group-member-lookup" v-model="memberLookup" class="tm-identity-autocomplete" input-class="tm-identity-input" :suggestions="candidateUsers" option-label="name" :delay="300" :min-length="2" :loading="loadingCandidates" force-selection aria-label="Buscar usuários ativos por nome ou e-mail" placeholder="Digite ao menos 2 caracteres do nome ou e-mail" @complete="searchCandidates" @item-select="selectGroupCandidate"><template #option="{ option }"><div class="tm-user-option"><span>{{ option.name }}</span><small>{{ option.email || option.username || option.authSource }}</small></div></template></AutoComplete></div><div class="tm-selected-users"><span class="tm-selected-label">{{ selectedGroupUsers.length }} selecionado(s)</span><div v-for="user in selectedGroupUsers" :key="user.id" class="tm-selected-user"><span>{{ user.name }}<small>{{ user.email || user.username }}</small></span><Button class="tm-btn small" label="Remover" aria-label="Remover usuário selecionado" @click="removeSelectedGroupUser(user)" /></div><span v-if="!selectedGroupUsers.length" class="tm-flow-sub">Os usuários escolhidos aparecerão aqui.</span></div><Button class="tm-btn primary tm-membership-submit" label="Adicionar selecionados" :disabled="!selectedUserIds.length" @click="addMembers"/><p v-if="memberCandidatesError" class="tm-error tm-membership-error" role="alert">{{ memberCandidatesError }}</p></div>
    </template>
  </Dialog>

  <Dialog :visible="!!roleMembership" modal header="Adicionar usuários ao papel" :style="{ width: 'min(760px, 96vw)' }" @update:visible="(v) => { if (!v) roleMembership = null }">
    <template v-if="roleMembership"><p class="tm-flow-sub">{{ roleMembership.name }} · <code>{{ roleMembership.key }}</code>. A atribuição manual vale somente nesta aplicação.</p>
      <div class="tm-panel tm-membership-form tm-role-membership-form">
        <div class="tm-lookup-field"><label for="role-member-lookup">Buscar e selecionar usuário</label><AutoComplete id="role-member-lookup" v-model="roleLookup" class="tm-identity-autocomplete" input-class="tm-identity-input" :suggestions="roleCandidateUsers" option-label="name" :delay="300" :min-length="2" :loading="loadingRoleCandidates" force-selection aria-label="Buscar usuários ativos por nome ou e-mail" placeholder="Digite ao menos 2 caracteres do nome ou e-mail" @complete="searchRoleCandidates" @item-select="selectRoleCandidate"><template #option="{ option }"><div class="tm-user-option"><span>{{ option.name }}</span><small>{{ option.email || option.username || option.authSource }}</small></div></template></AutoComplete></div>
        <div class="tm-selected-users"><span class="tm-selected-label">{{ selectedRoleUsers.length }} selecionado(s)</span><div v-for="user in selectedRoleUsers" :key="user.id" class="tm-selected-user"><span>{{ user.name }}<small>{{ user.email || user.username }}</small></span><Button class="tm-btn small" label="Remover" aria-label="Remover usuário selecionado" @click="removeSelectedRoleUser(user)" /></div><span v-if="!selectedRoleUsers.length" class="tm-flow-sub">Os usuários escolhidos aparecerão aqui.</span></div>
        <Button class="tm-btn primary tm-membership-submit" label="Adicionar ao papel" :disabled="!selectedRoleUserIds.length || savingRoleMembers" :loading="savingRoleMembers" @click="addRoleMembers"/>
        <p v-if="roleCandidatesError" class="tm-error tm-membership-error" role="alert">{{ roleCandidatesError }}</p>
      </div>
    </template>
  </Dialog>

  <Dialog :visible="newUserOpen" modal header="Novo usuário local" :style="{ width: 'min(520px, 96vw)' }" @update:visible="(v) => { newUserOpen = v }">
    <form class="tm-form" @submit.prevent="createLocalUser"><p class="tm-flow-sub">A conta começa pendente e só ganha acesso depois de ativada pelo link de uso único enviado ao e-mail.</p>
      <div class="tm-field"><label for="new-user-name">Nome completo</label><input id="new-user-name" v-model="newUser.name" required maxlength="200" /></div>
      <div class="tm-field"><label for="new-user-email">E-mail</label><input id="new-user-email" v-model="newUser.email" required type="email" maxlength="200" /></div>
      <div class="tm-field"><label for="new-user-username">Usuário</label><input id="new-user-username" v-model="newUser.username" required maxlength="120" autocomplete="off" /></div>
      <div class="tm-field"><label for="new-user-area">Área</label><input id="new-user-area" v-model="newUser.area" maxlength="200" /></div>
      <div class="tm-field"><label for="new-user-department">Departamento</label><input id="new-user-department" v-model="newUser.department" maxlength="200" /></div>
      <div class="tm-actions" style="justify-content:flex-end"><button type="button" class="tm-btn" @click="newUserOpen = false">Cancelar</button><button class="tm-btn primary" type="submit" :disabled="creatingUser">{{ creatingUser ? 'Criando…' : 'Criar conta pendente' }}</button></div>
    </form>
  </Dialog>

  <Dialog :visible="newGroupOpen" modal header="Novo grupo/raia" :style="{ width: 'min(720px, 96vw)' }" @update:visible="(v) => { newGroupOpen = v }"><form v-if="newGroupOpen" class="tm-form tm-group-create-form" @submit.prevent="createGroup"><p class="tm-flow-sub">Cadastre a raia e, se desejar, vincule usuários ativos já existentes. O vínculo é interno ao BPM TMSA e não altera o Microsoft Entra ID.</p><div class="tm-field"><label for="new-group-key">Chave</label><InputText id="new-group-key" v-model="newGroupDraft.key" required maxlength="40" class="tm-identity-input" placeholder="Ex.: RH ou COMPRAS" /><small>Inicie com uma letra; use letras maiúsculas, números e _.</small></div><div class="tm-field"><label for="new-group-name">Nome</label><InputText id="new-group-name" v-model="newGroupDraft.name" required maxlength="120" class="tm-identity-input" /></div><div class="tm-field"><label for="new-group-description">Descrição</label><InputText id="new-group-description" v-model="newGroupDraft.description" maxlength="400" class="tm-identity-input" /></div><div class="tm-field"><label for="new-group-entra">Object ID do grupo no Entra ID (opcional)</label><InputText id="new-group-entra" v-model="newGroupDraft.entraGroupId" class="tm-identity-input" placeholder="UUID" /></div><div class="tm-field"><label for="new-group-email">E-mail de notificação (opcional)</label><InputText id="new-group-email" v-model="newGroupDraft.email" type="email" maxlength="200" class="tm-identity-input" /></div><div class="tm-field"><label for="new-group-member-lookup">Vincular membros cadastrados</label><AutoComplete id="new-group-member-lookup" v-model="memberLookup" class="tm-identity-autocomplete" input-class="tm-identity-input" :suggestions="candidateUsers" option-label="name" :delay="300" :min-length="2" :loading="loadingCandidates" force-selection aria-label="Buscar usuários ativos para vincular ao novo grupo" placeholder="Digite ao menos 2 caracteres do nome ou e-mail" @complete="searchCandidates" @item-select="selectGroupCandidate"><template #option="{ option }"><div class="tm-user-option"><span>{{ option.name }}</span><small>{{ option.email || option.username || option.authSource }}</small></div></template></AutoComplete><div class="tm-selected-users"><span class="tm-selected-label">{{ selectedGroupUsers.length }} selecionado(s)</span><div v-for="user in selectedGroupUsers" :key="user.id" class="tm-selected-user"><span>{{ user.name }}<small>{{ user.email || user.username }}</small></span><Button class="tm-btn small" label="Remover" aria-label="Remover usuário selecionado" @click="removeSelectedGroupUser(user)" /></div></div></div><div class="tm-actions" style="justify-content:flex-end"><Button type="button" class="tm-btn" label="Cancelar" @click="newGroupOpen = false" /><Button type="submit" class="tm-btn primary" label="Criar grupo e vincular" :disabled="creatingGroup" :loading="creatingGroup" /></div></form></Dialog>

  <Dialog :visible="!!editing" modal header="Editar grupo" :style="{ width: 'min(480px, 96vw)' }" @update:visible="(v) => { if (!v) editing = null }"><template v-if="editing">
    <div class="tm-field"><label for="group-name">Nome</label><input id="group-name" v-model="editing.name" /></div><div class="tm-field"><label for="group-description">Descrição</label><textarea id="group-description" v-model="editing.description" /></div><div class="tm-field"><label for="group-entra">Object ID do grupo no Entra ID</label><input id="group-entra" v-model="editing.entraGroupId" /><div class="tm-hint">Associação de referência para sincronização. A edição não altera o diretório.</div></div><div class="tm-field"><label for="group-email">E-mail de notificação</label><input id="group-email" v-model="editing.email" type="email" /></div><div class="tm-actions" style="justify-content:flex-end"><button class="tm-btn" @click="editing = null">Cancelar</button><button class="tm-btn primary" @click="saveGroup">Salvar</button></div>
  </template></Dialog>
</template>

<style scoped>
.tm-filter-row { display: grid; grid-template-columns: minmax(220px, 1.6fr) repeat(2, minmax(155px, 1fr)) minmax(130px, .75fr) minmax(175px, 1.1fr) minmax(150px, 1fr); gap: 10px; padding: 14px 16px; border-bottom: 1px solid var(--line, #e7ebef); }
.tm-filter-search, .tm-filter-input, .tm-filter-select { width: 100%; min-width: 0; }
.tm-filter-row :deep(.p-inputtext), .tm-filter-row :deep(.p-select) { min-height: 38px; border-radius: 7px; border-color: var(--line, #d5dde6); font-size: 12px; }
.tm-filter-row :deep(.p-select-label) { display: flex; align-items: center; padding-top: 8px; padding-bottom: 8px; }
.tm-filter-row :deep(.p-inputtext:focus), .tm-filter-row :deep(.p-select.p-focus) { border-color: #f47b43; box-shadow: 0 0 0 2px rgb(244 123 67 / 14%); }
.tm-membership-form { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: start; gap: 12px; }
.tm-membership-title { grid-column: 1 / -1; margin: 0 0 2px; color: #344455; font-size: 14px; }
.tm-identity-input { width: 100%; min-height: 38px; font-size: 12px; }
.tm-identity-autocomplete { display: flex; width: 100%; }
.tm-identity-autocomplete :deep(.p-autocomplete-input) { width: 100%; min-height: 38px; border-radius: 7px; border-color: var(--line, #d5dde6); font-size: 12px; }
.tm-identity-autocomplete :deep(.p-autocomplete-input:focus) { border-color: #f47b43; box-shadow: 0 0 0 2px rgb(244 123 67 / 14%); }
.tm-lookup-field, .tm-selected-users { display: grid; gap: 7px; min-width: 0; }
.tm-lookup-field > label { color: #53677b; font-size: 12px; font-weight: 600; }
.tm-selected-users { grid-column: 1 / -1; padding: 10px; border: 1px solid var(--line, #e7ebef); border-radius: 8px; background: #fbfcfd; }
.tm-selected-label { color: #53677b; font-size: 11px; font-weight: 700; }
.tm-selected-user { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 8px 0; border-top: 1px solid var(--line, #e7ebef); color: #344455; font-size: 12px; }
.tm-selected-user > span { display: grid; gap: 2px; }
.tm-selected-user small { color: #8794a1; font-size: 11px; }
.tm-membership-submit { min-height: 38px; align-self: end; }
.tm-membership-error { grid-column: 1 / -1; margin: 0; }
.tm-user-option { display: flex; flex-direction: column; gap: 2px; }
.tm-user-option small { color: #8794a1; font-size: 11px; }
.tm-role-user { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin: 3px 0; }
.tm-role-membership-form { margin-top: 14px; padding: 14px; }
.tm-gate-legend { margin: 0 16px 14px; border: 1px solid var(--line, #e7ebef); border-radius: 8px; overflow: hidden; }
.tm-gate-legend-head { padding: 13px 15px; background: #fbfcfd; border-bottom: 1px solid var(--line, #e7ebef); }
.tm-gate-legend-head h2 { margin: 0 0 4px; color: #344455; font-size: 13px; }
.tm-gate-legend-head p { margin: 0; color: #738397; font-size: 11px; }
.tm-gate-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 0; }
.tm-gate-item { display: grid; grid-template-columns: minmax(150px, .7fr) minmax(0, 1.5fr); gap: 10px; padding: 10px 14px; border-bottom: 1px solid var(--line, #edf0f3); }
.tm-gate-item:nth-child(odd) { border-right: 1px solid var(--line, #edf0f3); }
.tm-gate-item dt { font-size: 11px; font-weight: 700; }
.tm-gate-item dd { margin: 0; color: #617388; font-size: 11px; line-height: 1.5; }
.tm-expanded-role-row > td { padding: 0 !important; background: #fbfcfd; }
.tm-expanded-role { padding: 14px 18px 18px; }
.tm-expanded-role-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 10px; color: #344455; font-size: 13px; }
.tm-nested-table { min-width: 0 !important; border: 1px solid var(--line, #e7ebef); border-radius: 7px; overflow: hidden; }
.tm-group-create-form { gap: 12px; }
.tm-group-create-form > .tm-flow-sub, .tm-group-create-form > .tm-actions { grid-column: 1 / -1; }
.tm-group-create-form .tm-field { margin: 0; }
.tm-group-create-form .tm-field > small { color: #7c8b9b; font-size: 11px; }
@media (max-width: 1180px) { .tm-filter-row { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 760px) { .tm-filter-row { grid-template-columns: repeat(2, minmax(0, 1fr)); } .tm-filter-search { grid-column: 1 / -1; } .tm-membership-form { grid-template-columns: 1fr; } .tm-membership-title, .tm-membership-error, .tm-selected-users { grid-column: auto; } .tm-membership-submit { justify-self: start; } .tm-gate-list { grid-template-columns: 1fr; } .tm-gate-item:nth-child(odd) { border-right: 0; } .tm-expanded-role-head { align-items: flex-start; flex-direction: column; } }
@media (max-width: 480px) { .tm-filter-row { grid-template-columns: 1fr; } .tm-filter-search { grid-column: auto; } .tm-gate-item { grid-template-columns: 1fr; gap: 4px; } }
</style>
