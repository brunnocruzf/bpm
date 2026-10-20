<script setup lang="ts">
import { ref } from 'vue'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import AutoComplete from 'primevue/autocomplete'
import Button from 'primevue/button'
import { api, errorMessage } from '@/api/http'
import { useSession } from '@/stores/session'
import { useNotify } from '@/composables/notify'
import { fmtDateTime } from '@/composables/format'
import UsersWorkspace from '@/components/admin/users/UsersWorkspace.vue'
import type { UserRow } from '@/components/admin/users/types'

type Tab = 'users' | 'groups' | 'roles'
interface Group { key: string; name: string; description: string | null; entraGroupId: string | null; email: string | null; members: number }
interface Role { key: string; alias: string; name: string; active: boolean; userCount: number; permissions: { key: string; source: string }[]; directoryMappings: { group: string | null; entraGroupId: string }[] }
type RoleOrigin = 'token' | 'directory' | 'manual'
interface RoleUser { id: string; name: string; email: string | null; username: string | null; authSource: string; isActive: boolean; origins: RoleOrigin[]; manual: boolean }
interface PageMeta { currentPage: number; lastPage: number; perPage: number; total: number }

const session = useSession()
const notify = useNotify()
const tabs: { id: Tab; label: string }[] = [{ id: 'users', label: 'Usuários' }, { id: 'groups', label: 'Grupos/Raias' }, { id: 'roles', label: 'Papéis' }]
const activeTab = ref<Tab>('users')
const groups = ref<Group[]>([])
const roles = ref<Role[]>([])
const loading = ref(false)
const error = ref('')
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
const ROLE_ORIGIN: Record<RoleOrigin, string> = { token: 'Token Entra', directory: 'Mapeamento Entra', manual: 'Manual — BPM TMSA' }
const AUTH_SOURCE: Record<string, string> = { entra: 'Entra ID', local: 'Local', demo: 'Visitante', superadmin: 'SuperAdmin' }
const ROLE_USERS_PER_PAGE = 25
const editing = ref<Group | null>(null)
const membersGroup = ref<Group | null>(null)
const groupMembers = ref<any[]>([])
const groupExcluded = ref<any[]>([])
const groupMembersTotal = ref(0)
const removingMemberId = ref<string | null>(null)
const MEMBER_SOURCE: Record<string, string> = {
  manual: 'Manual — BPM TMSA', entra_sync: 'Entra ID — sincronização', entra_token: 'Entra ID — token de login', existing_mapping: 'Mapeamento / cadastro existente',
}
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
const roleUsers = ref<RoleUser[]>([])
const roleUsersMeta = ref<PageMeta>({ currentPage: 1, lastPage: 1, perPage: ROLE_USERS_PER_PAGE, total: 0 })
const roleUsersLoading = ref(false)
const roleUsersError = ref('')
const roleFilters = ref({ q: '', origin: '', authSource: '', active: '' })
const removingRoleUserId = ref<string | null>(null)
let roleSearchTimer: ReturnType<typeof setTimeout> | undefined
const newGroupOpen = ref(false)
const creatingGroup = ref(false)
const newGroupDraft = ref({ key: '', name: '', description: '', entraGroupId: '', email: '' })
async function loadGroups() {
  loading.value = true; error.value = ''
  try { groups.value = (await api<{ data: Group[] }>('/admin/groups')).data } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
async function loadRoles() {
  loading.value = true; error.value = ''
  try { roles.value = (await api<{ data: Role[] }>('/admin/roles')).data } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
function selectTab(tab: Tab) {
  activeTab.value = tab
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
async function saveGroup() {
  if (!editing.value) return
  try {
    await api(`/admin/groups/${editing.value.key}`, { method: 'PATCH', body: { name: editing.value.name, description: editing.value.description, entraGroupId: editing.value.entraGroupId || null, email: editing.value.email || null } })
    notify.success('Grupo atualizado.'); editing.value = null; await loadGroups()
  } catch (e) { notify.error(e) }
}
async function openMembers(group: Group) {
  newGroupOpen.value = false
  membersGroup.value = group; groupMembers.value = []; groupExcluded.value = []; groupMembersTotal.value = 0; candidateUsers.value = []; memberLookup.value = null; selectedUserIds.value = []; selectedGroupUsers.value = []
  memberCandidatesError.value = ''
  try {
    const res = await api<{ data: any[]; excluded?: any[]; meta?: { total: number } }>(`/admin/groups/${group.key}/members`, { query: { perPage: 100 } })
    groupMembers.value = res.data
    groupExcluded.value = res.excluded ?? []
    groupMembersTotal.value = res.meta?.total ?? res.data.length
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
  const group = membersGroup.value
  if (!group) return
  const detail = member.source === 'manual'
    ? 'O vínculo manual será removido. Se o acesso também vier do Entra ID ou de um mapeamento, ele será bloqueado apenas neste sistema.'
    : 'Este acesso vem do Entra ID ou de um mapeamento: será bloqueado apenas no BPM TMSA (o Entra ID não é alterado) e continuará bloqueado nas próximas sincronizações até você restaurar.'
  if (!window.confirm(`Remover ${member.name} do grupo ${group.name}?\n\n${detail}`)) return
  removingMemberId.value = member.id
  try {
    await api(`/admin/groups/${group.key}/exclusions/${member.id}`, { method: 'PUT' })
    notify.success(`${member.name} foi removido(a) do grupo.`); await openMembers(group); await loadGroups()
  } catch (e) { notify.error(e) } finally { removingMemberId.value = null }
}
async function restoreMember(member: any) {
  const group = membersGroup.value
  if (!group) return
  removingMemberId.value = member.id
  try {
    await api(`/admin/groups/${group.key}/exclusions/${member.id}`, { method: 'DELETE' })
    notify.success(`Bloqueio removido: ${member.name} volta a valer no grupo se a origem ainda concede o acesso.`); await openMembers(group); await loadGroups()
  } catch (e) { notify.error(e) } finally { removingMemberId.value = null }
}
async function openRoleMembers(role: Role) {
  roleMembership.value = role; roleCandidateUsers.value = []; roleLookup.value = null; selectedRoleUserIds.value = []; selectedRoleUsers.value = []; roleCandidatesError.value = ''
  roleFilters.value = { q: '', origin: '', authSource: '', active: '' }; roleUsers.value = []
  await loadRoleUsers(1)
}
async function loadRoleUsers(page = roleUsersMeta.value.currentPage) {
  const role = roleMembership.value
  if (!role) return
  roleUsersLoading.value = true; roleUsersError.value = ''
  const f = roleFilters.value
  try {
    const res = await api<{ data: RoleUser[]; meta: PageMeta }>(`/admin/roles/${encodeURIComponent(role.key)}/users`, {
      query: { page, perPage: ROLE_USERS_PER_PAGE, ...(f.q.trim() ? { q: f.q.trim() } : {}), ...(f.origin ? { origin: f.origin } : {}), ...(f.authSource ? { authSource: f.authSource } : {}), ...(f.active ? { active: f.active } : {}) },
    })
    roleUsers.value = res.data; roleUsersMeta.value = res.meta
  } catch (e) { roleUsersError.value = errorMessage(e) } finally { roleUsersLoading.value = false }
}
function onRoleSearchInput() {
  clearTimeout(roleSearchTimer)
  roleSearchTimer = setTimeout(() => void loadRoleUsers(1), 350)
}
function clearRoleFilters() {
  roleFilters.value = { q: '', origin: '', authSource: '', active: '' }
  void loadRoleUsers(1)
}
async function refreshRoleAfterChange(roleKey: string) {
  await Promise.all([loadRoles(), loadRoleUsers()])
  if (roleMembership.value?.key === roleKey) roleMembership.value = roles.value.find((r) => r.key === roleKey) ?? roleMembership.value
}
async function searchRoleCandidates(event: { query: string }) {
  const query = event.query.trim()
  if (query.length < 2) { roleCandidateUsers.value = []; return }
  loadingRoleCandidates.value = true
  try {
    roleCandidatesError.value = ''
    const roleKey = roleMembership.value?.key ?? ''
    const selected = new Set(selectedRoleUserIds.value)
    roleCandidateUsers.value = (await api<{ data: UserRow[] }>('/admin/managed-users', { query: { q: query, active: true, perPage: 50 } })).data.filter((u) => ['local', 'entra'].includes(u.authSource ?? '') && !selected.has(u.id) && !(u.roles ?? []).includes(roleKey))
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
    selectedRoleUserIds.value = []; selectedRoleUsers.value = []; roleCandidateUsers.value = []
    await refreshRoleAfterChange(roleMembership.value.key)
  } catch (e) { notify.error(e) } finally { savingRoleMembers.value = false }
}
async function removeRoleMember(member: RoleUser) {
  const role = roleMembership.value
  if (!role || !member.manual) return
  const detail = member.origins.length > 1 ? '\n\nO papel continuará efetivo porque também vem do Entra ID.' : ''
  if (!window.confirm(`Remover a atribuição manual de ${role.name} para ${member.name}?${detail}`)) return
  removingRoleUserId.value = member.id
  try {
    await api(`/admin/roles/${encodeURIComponent(role.key)}/users/${member.id}`, { method: 'DELETE' })
    notify.success('Atribuição manual removida.')
    await refreshRoleAfterChange(role.key)
  } catch (e) { notify.error(e) } finally { removingRoleUserId.value = null }
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
</script>

<template>
  <div class="tm-crumbs"><span>Administração</span><span class="sep">›</span><span>Usuários e grupos</span></div>
  <div class="tm-heading"><div><h1>Usuários e grupos</h1><p class="tm-subtitle">Gerencie contas locais, consulte identidades Entra e administre grupos internos do BPM TMSA.</p></div></div>

<div class="tm-tabbar" role="tablist" aria-label="Administração de identidades">
    <button v-for="(item, index) in tabs" :id="`identity-tab-${item.id}`" :key="item.id" class="tm-tab" :class="{ active: activeTab === item.id }" role="tab" :aria-selected="activeTab === item.id" :aria-controls="`identity-panel-${item.id}`" :tabindex="activeTab === item.id ? 0 : -1" @click="selectTab(item.id)" @keydown="onTabKeydown($event, index)">{{ item.label }}</button>
  </div>

  <div v-if="activeTab === 'users'" id="identity-panel-users" role="tabpanel" aria-labelledby="identity-tab-users" tabindex="0"><UsersWorkspace /></div>

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
      <tr v-for="role in roles" :key="role.key"><td class="tm-strong">{{ role.name }}<div class="tm-flow-sub"><code>{{ role.key }}</code> · {{ role.userCount }} usuário(s)</div></td><td><span v-for="p in role.permissions" :key="p.key" class="tm-pill neutral" style="margin:2px">{{ p.key }}</span><span v-if="!role.permissions.length">—</span></td><td><Button class="tm-btn small" :label="`Ver usuários (${role.userCount})`" aria-haspopup="dialog" @click="openRoleMembers(role)" /></td><td><div v-for="m in role.directoryMappings" :key="m.entraGroupId">{{ m.group || m.entraGroupId }}</div><span v-if="!role.directoryMappings.length">—</span></td></tr>
      <tr v-if="!roles.length"><td colspan="4" class="tm-empty">Nenhum papel configurado.</td></tr>
    </tbody></table></div>
  </section>


  <Dialog :visible="!!membersGroup" modal header="Membros do grupo TMSA" :style="{ width: 'min(850px, 96vw)' }" @update:visible="(v) => { if (!v) membersGroup = null }">
    <template v-if="membersGroup"><p>{{ membersGroup.name }} · <code>{{ membersGroup.key }}</code>. Alterações manuais não escrevem no Entra ID.</p>
      <div class="tm-table-wrap"><table class="tm-table" style="min-width:0"><thead><tr><th>Usuário</th><th>Origem do vínculo</th><th>Ator / data</th><th>Ação</th></tr></thead><tbody><tr v-for="m in groupMembers" :key="m.id"><td>{{ m.name }}<div class="tm-flow-sub">{{ m.email }}</div></td><td>{{ MEMBER_SOURCE[m.source] ?? m.source }}</td><td>{{ m.createdBy || '—' }} {{ fmtDateTime(m.createdAt) }}</td><td><button v-if="session.can('settingsManage')" class="tm-btn small" :disabled="removingMemberId === m.id" :aria-label="`Remover ${m.name} do grupo`" @click="removeMember(m)">Remover do grupo</button></td></tr><tr v-if="!groupMembers.length"><td colspan="4">Nenhum membro neste grupo.</td></tr></tbody></table></div>
      <p v-if="groupMembersTotal > groupMembers.length" class="tm-flow-sub" role="status">Mostrando {{ groupMembers.length }} de {{ groupMembersTotal }} membros.</p>
      <div v-if="groupExcluded.length" class="tm-panel" style="margin-top:16px;padding:14px"><h3 class="tm-membership-title">Removidos neste sistema ({{ groupExcluded.length }})</h3><p class="tm-flow-sub">O acesso ainda pode existir no Entra ID ou no mapeamento, mas não vale no BPM TMSA enquanto o bloqueio existir. Restaurar não escreve no Entra ID.</p>
        <div class="tm-table-wrap"><table class="tm-table" style="min-width:0"><thead><tr><th>Usuário</th><th>Removido por / data</th><th>Ação</th></tr></thead><tbody><tr v-for="x in groupExcluded" :key="x.id"><td>{{ x.name }}<div class="tm-flow-sub">{{ x.email }}</div></td><td>{{ x.createdBy || '—' }} {{ fmtDateTime(x.createdAt) }}</td><td><button v-if="session.can('settingsManage')" class="tm-btn small" :disabled="removingMemberId === x.id" :aria-label="`Restaurar ${x.name} no grupo`" @click="restoreMember(x)">Restaurar</button></td></tr></tbody></table></div></div>
      <div v-if="session.can('settingsManage')" class="tm-panel tm-membership-form" style="margin-top:16px;padding:14px"><h3 class="tm-membership-title">Adicionar usuários ativos</h3><div class="tm-lookup-field"><label for="group-member-lookup">Buscar e selecionar usuário</label><AutoComplete id="group-member-lookup" v-model="memberLookup" class="tm-identity-autocomplete" input-class="tm-identity-input" :suggestions="candidateUsers" option-label="name" :delay="300" :min-length="2" :loading="loadingCandidates" force-selection aria-label="Buscar usuários ativos por nome ou e-mail" placeholder="Digite ao menos 2 caracteres do nome ou e-mail" @complete="searchCandidates" @item-select="selectGroupCandidate"><template #option="{ option }"><div class="tm-user-option"><span>{{ option.name }}</span><small>{{ option.email || option.username || option.authSource }}</small></div></template></AutoComplete></div><div class="tm-selected-users"><span class="tm-selected-label">{{ selectedGroupUsers.length }} selecionado(s)</span><div v-for="user in selectedGroupUsers" :key="user.id" class="tm-selected-user"><span>{{ user.name }}<small>{{ user.email || user.username }}</small></span><Button class="tm-btn small" label="Remover" aria-label="Remover usuário selecionado" @click="removeSelectedGroupUser(user)" /></div><span v-if="!selectedGroupUsers.length" class="tm-flow-sub">Os usuários escolhidos aparecerão aqui.</span></div><Button class="tm-btn primary tm-membership-submit" label="Adicionar selecionados" :disabled="!selectedUserIds.length" @click="addMembers"/><p v-if="memberCandidatesError" class="tm-error tm-membership-error" role="alert">{{ memberCandidatesError }}</p></div>
    </template>
  </Dialog>

  <Dialog :visible="!!roleMembership" modal :header="roleMembership ? `Usuários do papel ${roleMembership.name}` : 'Usuários do papel'" :style="{ width: 'min(980px, 96vw)' }" @update:visible="(v) => { if (!v) roleMembership = null }">
    <template v-if="roleMembership"><p class="tm-flow-sub"><code>{{ roleMembership.key }}</code> · {{ roleMembership.userCount }} usuário(s) com o papel efetivo. Atribuições manuais valem somente nesta aplicação; vínculos do token e do Entra são gerenciados na origem.</p>
      <form class="tm-role-filters" role="search" aria-label="Filtrar usuários do papel" @submit.prevent="loadRoleUsers(1)">
        <div class="tm-lookup-field tm-role-filter-q"><label for="role-users-q">Buscar</label><InputText id="role-users-q" v-model="roleFilters.q" class="tm-identity-input" placeholder="Nome, e-mail ou usuário" @input="onRoleSearchInput" /></div>
        <div class="tm-lookup-field"><label for="role-users-origin">Origem do vínculo</label><select id="role-users-origin" v-model="roleFilters.origin" class="tm-identity-input tm-role-select" @change="loadRoleUsers(1)"><option value="">Todas</option><option v-for="(label, key) in ROLE_ORIGIN" :key="key" :value="key">{{ label }}</option></select></div>
        <div class="tm-lookup-field"><label for="role-users-source">Tipo de conta</label><select id="role-users-source" v-model="roleFilters.authSource" class="tm-identity-input tm-role-select" @change="loadRoleUsers(1)"><option value="">Todas</option><option v-for="(label, key) in AUTH_SOURCE" :key="key" :value="key">{{ label }}</option></select></div>
        <div class="tm-lookup-field"><label for="role-users-active">Situação</label><select id="role-users-active" v-model="roleFilters.active" class="tm-identity-input tm-role-select" @change="loadRoleUsers(1)"><option value="">Todas</option><option value="true">Ativas</option><option value="false">Inativas</option></select></div>
        <Button type="button" class="tm-btn small tm-role-filter-clear" label="Limpar" :disabled="!roleFilters.q && !roleFilters.origin && !roleFilters.authSource && !roleFilters.active" @click="clearRoleFilters" />
      </form>
      <p v-if="roleUsersError" class="tm-error" role="alert">{{ roleUsersError }}</p>
      <div class="tm-table-wrap tm-role-users-wrap" :aria-busy="roleUsersLoading"><table class="tm-table" style="min-width:0"><thead><tr><th>Usuário</th><th>Conta</th><th>Origem do vínculo</th><th>Ação</th></tr></thead><tbody>
        <tr v-for="u in roleUsers" :key="u.id"><td class="tm-strong">{{ u.name }}<div class="tm-flow-sub">{{ u.email || u.username || '—' }}</div></td><td>{{ AUTH_SOURCE[u.authSource] ?? u.authSource }}<div v-if="!u.isActive"><span class="tm-pill amber">Inativa</span></div></td><td><span v-for="o in u.origins" :key="o" class="tm-pill" :class="o === 'manual' ? 'amber' : 'neutral'" style="margin:2px">{{ ROLE_ORIGIN[o] }}</span><span v-if="!u.origins.length" class="tm-pill neutral">Papel efetivo</span></td><td><Button v-if="u.manual && session.can('settingsManage')" class="tm-btn small" label="Remover vínculo" :disabled="removingRoleUserId === u.id" :aria-label="`Remover vínculo manual de ${u.name}`" @click="removeRoleMember(u)" /><span v-else class="tm-flow-sub">Gerenciado pela origem</span></td></tr>
        <tr v-if="!roleUsers.length"><td colspan="4" class="tm-empty">{{ roleUsersLoading ? 'Carregando usuários…' : 'Nenhum usuário encontrado com esses filtros.' }}</td></tr>
      </tbody></table></div>
      <div class="tm-role-pager"><span class="tm-flow-sub" role="status">{{ roleUsersMeta.total }} resultado(s) · página {{ roleUsersMeta.currentPage }} de {{ roleUsersMeta.lastPage }}</span><div class="tm-actions"><Button class="tm-btn small" label="Anterior" :disabled="roleUsersMeta.currentPage <= 1 || roleUsersLoading" @click="loadRoleUsers(roleUsersMeta.currentPage - 1)" /><Button class="tm-btn small" label="Próxima" :disabled="roleUsersMeta.currentPage >= roleUsersMeta.lastPage || roleUsersLoading" @click="loadRoleUsers(roleUsersMeta.currentPage + 1)" /></div></div>
      <div v-if="session.can('settingsManage')" class="tm-panel tm-membership-form tm-role-membership-form"><h3 class="tm-membership-title">Adicionar usuários ativos ao papel</h3>
        <div class="tm-lookup-field"><label for="role-member-lookup">Buscar e selecionar usuário</label><AutoComplete id="role-member-lookup" v-model="roleLookup" class="tm-identity-autocomplete" input-class="tm-identity-input" :suggestions="roleCandidateUsers" option-label="name" :delay="300" :min-length="2" :loading="loadingRoleCandidates" force-selection aria-label="Buscar usuários ativos por nome ou e-mail" placeholder="Digite ao menos 2 caracteres do nome ou e-mail" @complete="searchRoleCandidates" @item-select="selectRoleCandidate"><template #option="{ option }"><div class="tm-user-option"><span>{{ option.name }}</span><small>{{ option.email || option.username || option.authSource }}</small></div></template></AutoComplete></div>
        <div class="tm-selected-users"><span class="tm-selected-label">{{ selectedRoleUsers.length }} selecionado(s)</span><div v-for="user in selectedRoleUsers" :key="user.id" class="tm-selected-user"><span>{{ user.name }}<small>{{ user.email || user.username }}</small></span><Button class="tm-btn small" label="Remover" aria-label="Remover usuário selecionado" @click="removeSelectedRoleUser(user)" /></div><span v-if="!selectedRoleUsers.length" class="tm-flow-sub">Os usuários escolhidos aparecerão aqui.</span></div>
        <Button class="tm-btn primary tm-membership-submit" label="Adicionar ao papel" :disabled="!selectedRoleUserIds.length || savingRoleMembers" :loading="savingRoleMembers" @click="addRoleMembers"/>
        <p v-if="roleCandidatesError" class="tm-error tm-membership-error" role="alert">{{ roleCandidatesError }}</p>
      </div>
    </template>
  </Dialog>


  <Dialog :visible="newGroupOpen" modal header="Novo grupo/raia" :style="{ width: 'min(720px, 96vw)' }" @update:visible="(v) => { newGroupOpen = v }"><form v-if="newGroupOpen" class="tm-form tm-group-create-form" @submit.prevent="createGroup"><p class="tm-flow-sub">Cadastre a raia e, se desejar, vincule usuários ativos já existentes. O vínculo é interno ao BPM TMSA e não altera o Microsoft Entra ID.</p><div class="tm-field"><label for="new-group-key">Chave</label><InputText id="new-group-key" v-model="newGroupDraft.key" required maxlength="40" class="tm-identity-input" placeholder="Ex.: RH ou COMPRAS" /><small>Inicie com uma letra; use letras maiúsculas, números e _.</small></div><div class="tm-field"><label for="new-group-name">Nome</label><InputText id="new-group-name" v-model="newGroupDraft.name" required maxlength="120" class="tm-identity-input" /></div><div class="tm-field"><label for="new-group-description">Descrição</label><InputText id="new-group-description" v-model="newGroupDraft.description" maxlength="400" class="tm-identity-input" /></div><div class="tm-field"><label for="new-group-entra">Object ID do grupo no Entra ID (opcional)</label><InputText id="new-group-entra" v-model="newGroupDraft.entraGroupId" class="tm-identity-input" placeholder="UUID" /></div><div class="tm-field"><label for="new-group-email">E-mail de notificação (opcional)</label><InputText id="new-group-email" v-model="newGroupDraft.email" type="email" maxlength="200" class="tm-identity-input" /></div><div class="tm-field"><label for="new-group-member-lookup">Vincular membros cadastrados</label><AutoComplete id="new-group-member-lookup" v-model="memberLookup" class="tm-identity-autocomplete" input-class="tm-identity-input" :suggestions="candidateUsers" option-label="name" :delay="300" :min-length="2" :loading="loadingCandidates" force-selection aria-label="Buscar usuários ativos para vincular ao novo grupo" placeholder="Digite ao menos 2 caracteres do nome ou e-mail" @complete="searchCandidates" @item-select="selectGroupCandidate"><template #option="{ option }"><div class="tm-user-option"><span>{{ option.name }}</span><small>{{ option.email || option.username || option.authSource }}</small></div></template></AutoComplete><div class="tm-selected-users"><span class="tm-selected-label">{{ selectedGroupUsers.length }} selecionado(s)</span><div v-for="user in selectedGroupUsers" :key="user.id" class="tm-selected-user"><span>{{ user.name }}<small>{{ user.email || user.username }}</small></span><Button class="tm-btn small" label="Remover" aria-label="Remover usuário selecionado" @click="removeSelectedGroupUser(user)" /></div></div></div><div class="tm-actions" style="justify-content:flex-end"><Button type="button" class="tm-btn" label="Cancelar" @click="newGroupOpen = false" /><Button type="submit" class="tm-btn primary" label="Criar grupo e vincular" :disabled="creatingGroup" :loading="creatingGroup" /></div></form></Dialog>

  <Dialog :visible="!!editing" modal header="Editar grupo" :style="{ width: 'min(480px, 96vw)' }" @update:visible="(v) => { if (!v) editing = null }"><template v-if="editing">
    <div class="tm-field"><label for="group-name">Nome</label><input id="group-name" v-model="editing.name" /></div><div class="tm-field"><label for="group-description">Descrição</label><textarea id="group-description" v-model="editing.description" /></div><div class="tm-field"><label for="group-entra">Object ID do grupo no Entra ID</label><input id="group-entra" v-model="editing.entraGroupId" /><div class="tm-hint">Associação de referência para sincronização. A edição não altera o diretório.</div></div><div class="tm-field"><label for="group-email">E-mail de notificação</label><input id="group-email" v-model="editing.email" type="email" /></div><div class="tm-actions" style="justify-content:flex-end"><button class="tm-btn" @click="editing = null">Cancelar</button><button class="tm-btn primary" @click="saveGroup">Salvar</button></div>
  </template></Dialog>
</template>

<style scoped>
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
.tm-role-filters { display: grid; grid-template-columns: minmax(200px, 2fr) repeat(3, minmax(130px, 1fr)) auto; align-items: end; gap: 10px; margin: 12px 0; }
.tm-role-select { padding: 0 8px; border: 1px solid var(--line, #d5dde6); border-radius: 7px; background: #fff; color: #344455; }
.tm-role-filter-clear { min-height: 38px; }
.tm-role-users-wrap { max-height: 46vh; overflow: auto; }
.tm-role-users-wrap[aria-busy="true"] { opacity: .6; }
.tm-role-pager { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 10px; }
.tm-group-create-form { gap: 12px; }
.tm-group-create-form > .tm-flow-sub, .tm-group-create-form > .tm-actions { grid-column: 1 / -1; }
.tm-group-create-form .tm-field { margin: 0; }
.tm-group-create-form .tm-field > small { color: #7c8b9b; font-size: 11px; }
@media (max-width: 760px) { .tm-membership-form { grid-template-columns: 1fr; } .tm-membership-title, .tm-membership-error, .tm-selected-users { grid-column: auto; } .tm-membership-submit { justify-self: start; } .tm-gate-list { grid-template-columns: 1fr; } .tm-gate-item:nth-child(odd) { border-right: 0; } .tm-role-filters { grid-template-columns: 1fr 1fr; } .tm-role-filter-q { grid-column: 1 / -1; } .tm-role-pager { flex-direction: column; align-items: flex-start; } }
@media (max-width: 480px) { .tm-gate-item { grid-template-columns: 1fr; gap: 4px; } }
</style>
