<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import Dialog from 'primevue/dialog'
import { api } from '@/api/http'
import { useSession } from '@/stores/session'
import { useNotify } from '@/composables/notify'
import { fmtDateTime } from '@/composables/format'

interface Group { key: string; name: string; description: string | null; entraGroupId: string | null; email: string | null; members: number }
interface UserRow { id: string; name: string; area: string | null; roles: string[]; groups: string[]; is_active: boolean; last_seen_at: string | null }

const session = useSession()
const notify = useNotify()
const groups = ref<Group[]>([])
const users = ref<UserRow[]>([])
const editing = ref<Group | null>(null)
const tab = ref<'users'|'groups'|'roles'>('users')
const roles = computed(() => [...new Set(users.value.flatMap((u) => u.roles ?? []))].sort())

async function load() {
  try {
    groups.value = (await api<{ data: Group[] }>('/admin/groups')).data
    if (session.can('admin')) users.value = (await api<{ data: UserRow[] }>('/admin/users')).data
  } catch (e) {
    notify.error(e)
  }
}

async function save() {
  if (!editing.value) return
  try {
    await api(`/admin/groups/${editing.value.key}`, { method: 'PATCH', body: { name: editing.value.name, description: editing.value.description, entraGroupId: editing.value.entraGroupId || null, email: editing.value.email || null } })
    notify.success('Grupo atualizado.')
    editing.value = null
    load()
  } catch (e) {
    notify.error(e)
  }
}

onMounted(load)
</script>

<template>
  <div class="tm-crumbs"><span>Administração</span><span class="sep">›</span><span>Usuários e grupos</span></div>
  <div class="tm-heading">
    <div><h1>Usuários e grupos</h1><p class="tm-subtitle">Grupos são as raias dos processos (CQ, PPCPM, GEF...). Em cada ambiente, associe o grupo lógico ao ID do grupo no Microsoft Entra ID. Papéis (Bpm.Admin, Bpm.Designer...) vêm dos app roles do Entra.</p></div>
  </div>
  <div class="tm-tabs" role="tablist" aria-label="Administração de identidades">
    <button v-for="item in [{id:'users',label:'Usuários'},{id:'groups',label:'Grupos'},{id:'roles',label:'Papéis'}]" :key="item.id" class="tm-tab" :class="{ active: tab === item.id }" role="tab" :aria-selected="tab === item.id" @click="tab = item.id as 'users'|'groups'|'roles'">{{ item.label }}</button>
  </div>
  <section v-if="tab === 'groups'" class="tm-panel" style="margin-bottom:16px">
    <div class="tm-panel-head"><div class="tm-panel-title">Grupos / raias</div></div>
    <table class="tm-table" style="min-width:0">
      <thead><tr><th>Chave</th><th>Nome</th><th>Grupo Entra (object ID)</th><th>E-mail de notificação</th><th>Membros</th><th /></tr></thead>
      <tbody>
        <tr v-for="g in groups" :key="g.key">
          <td><code>{{ g.key }}</code></td>
          <td class="tm-strong">{{ g.name }}<div class="tm-flow-sub">{{ g.description }}</div></td>
          <td><code v-if="g.entraGroupId">{{ g.entraGroupId }}</code><span v-else class="tm-pill amber">não associado</span></td>
          <td>{{ g.email ?? '—' }}</td>
          <td>{{ g.members }}</td>
          <td><button v-if="session.can('admin')" class="tm-btn small" @click="editing = { ...g }">Editar</button></td>
        </tr>
      </tbody>
    </table>
  </section>
  <section v-if="tab === 'users' && session.can('admin')" class="tm-panel">
    <div class="tm-panel-head"><div class="tm-panel-title">Usuários conhecidos</div><div class="tm-panel-caption">Criados no primeiro acesso a partir do token (oid + tid)</div></div>
    <table class="tm-table" style="min-width:0">
      <thead><tr><th>Nome</th><th>Área</th><th>Papéis</th><th>Grupos</th><th>Último acesso</th></tr></thead>
      <tbody>
        <tr v-for="u in users" :key="u.id">
          <td class="tm-strong">{{ u.name }}</td><td>{{ u.area ?? '—' }}</td>
          <td><span v-for="r in u.roles" :key="r" class="tm-pill neutral" style="margin:1px">{{ r.replace('Bpm.', '') }}</span></td>
          <td><span v-for="g in u.groups" :key="g" class="tm-pill brand" style="margin:1px">{{ g }}</span></td>
          <td>{{ fmtDateTime(u.last_seen_at) }}</td>
        </tr>
      </tbody>
    </table>
  </section>
  <section v-if="tab === 'roles'" class="tm-panel">
    <div class="tm-panel-head"><div class="tm-panel-title">Papéis efetivos</div><div class="tm-panel-caption">Somente leitura; origem derivada do domínio atual</div></div>
    <table class="tm-table" style="min-width:0"><thead><tr><th>Papel</th><th>Usuários relacionados</th></tr></thead><tbody>
      <tr v-for="role in roles" :key="role"><td class="tm-strong">{{ role }}</td><td>{{ users.filter((u) => u.roles.includes(role)).map((u) => u.name).join(', ') || '—' }}</td></tr>
      <tr v-if="!roles.length"><td colspan="2">Nenhum papel disponível.</td></tr>
    </tbody></table>
  </section>

  <Dialog :visible="!!editing" modal header="Editar grupo" :style="{ width: 'min(480px, 96vw)' }" @update:visible="(v) => { if (!v) editing = null }">
    <template v-if="editing">
      <div class="tm-field"><label>Nome</label><input v-model="editing.name" /></div>
      <div class="tm-field"><label>Descrição</label><textarea v-model="editing.description" /></div>
      <div class="tm-field"><label>Object ID do grupo no Entra ID</label><input v-model="editing.entraGroupId" placeholder="00000000-0000-0000-0000-000000000000" /><div class="tm-hint">Membros do grupo Entra recebem as tarefas desta raia (claim "groups" do token).</div></div>
      <div class="tm-field"><label>E-mail de notificação</label><input v-model="editing.email" type="email" /><div class="tm-hint">Destino lógico das notificações de novas tarefas. Notificação não concede acesso.</div></div>
      <div class="tm-actions" style="justify-content:flex-end"><button class="tm-btn" @click="editing = null">Cancelar</button><button class="tm-btn primary" @click="save">Salvar</button></div>
    </template>
  </Dialog>
</template>
