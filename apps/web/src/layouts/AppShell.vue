<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Menu from 'primevue/menu'
import { useSession } from '@/stores/session'
import { useBranding } from '@/stores/branding'
import { auth } from '@/auth'

const session = useSession()
const brand = useBranding()
const route = useRoute()
const router = useRouter()
const sidebarOpen = ref(false)
const userMenu = ref()
const search = ref('')
const envLabel = import.meta.env.VITE_ENV_LABEL || 'BPM TMSA'
const method = auth.method()
const methodLabel = { entra: 'Login: Microsoft Entra ID', demo: 'Visitante — ambiente de demonstração', superadmin: 'SuperAdmin — acesso de emergência', local: 'Login: conta local' }[method ?? 'entra']
// sessão SuperAdmin: aviso permanente com a expiração absoluta (o servidor também expira por inatividade)
const superAdmin = auth.superAdminSession()
const superAdminUntil = superAdmin ? new Date(superAdmin.expiresAt).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : null

const categories = computed(() => session.meta?.categories.filter((c) => c !== 'Outros') ?? [])
const activeCategory = computed(() => (route.name === 'catalog' ? (route.query.category as string) || 'Todas' : null))

const menuItems = computed(() => [
  { label: session.me?.name ?? '', items: [
    { label: `Área: ${session.me?.area ?? '—'}`, icon: 'pi pi-building', disabled: true },
    { label: 'Sair', icon: 'pi pi-sign-out', command: async () => { await session.logout(); router.push({ name: 'login' }) } },
  ] },
])

function goSearch() {
  const q = search.value.trim()
  if (q) router.push({ name: 'monitor', query: { q } })
}

let timer: number | undefined
onMounted(() => {
  // contador "Minhas tarefas" atualizado periodicamente
  timer = window.setInterval(() => session.refreshCounters(), 30000)
})
onBeforeUnmount(() => window.clearInterval(timer))
watch(() => route.fullPath, () => { sidebarOpen.value = false })
</script>

<template>
  <header class="tm-topbar">
    <div style="display:flex;align-items:center;gap:10px">
      <button class="tm-btn icon tm-menu-btn" aria-label="Abrir menu" @click="sidebarOpen = !sidebarOpen"><i class="pi pi-bars" /></button>
      <RouterLink to="/" class="tm-brand" :aria-label="`${brand.branding.appName} — início`">
        <img v-if="brand.branding.assets.main" :src="brand.branding.assets.main" :alt="brand.branding.orgName" class="tm-brand-logo" />
        <template v-else>
          <img v-if="brand.branding.assets.compact" :src="brand.branding.assets.compact" alt="" class="tm-brand-logo" style="width:34px" />
          <div v-else class="tm-brand-mark" aria-hidden="true">{{ brand.initials }}</div>
          <div class="tm-brand-name">{{ brand.branding.appName }}<span class="tm-brand-sub">{{ brand.branding.tagline }}</span></div>
        </template>
      </RouterLink>
    </div>
    <form class="tm-search" role="search" @submit.prevent="goSearch">
      <i class="pi pi-search" />
      <input v-model="search" placeholder="Buscar solicitação por número (ex.: IRR-000001/26)..." aria-label="Buscar solicitação" />
    </form>
    <div class="tm-top-actions">
      <div class="tm-user-name"><strong>{{ session.me?.name }}</strong>{{ session.me?.groups.map((g) => g.name).join(', ') || session.me?.area }}</div>
      <button class="tm-avatar" :title="methodLabel" @click="(e) => userMenu.toggle(e)">{{ session.initials }}</button>
      <Menu ref="userMenu" :model="menuItems" popup />
    </div>
  </header>

  <div v-if="superAdmin" class="tm-superadmin-bar" role="status">
    <i class="pi pi-shield" aria-hidden="true" />
    <span><strong>Sessão SuperAdmin (acesso de emergência).</strong> Use somente para restabelecer o acesso corporativo; todas as ações são auditadas. Expira às {{ superAdminUntil }} ou após inatividade.</span>
  </div>

  <div class="tm-layout">
    <aside class="tm-sidebar" :class="{ open: sidebarOpen }">
      <nav>
        <div class="tm-side-label">Espaço de trabalho</div>
        <RouterLink class="tm-side-item" :class="{ active: route.name === 'catalog' }" :to="{ name: 'catalog' }"><i class="pi pi-th-large" />Catálogo de fluxos</RouterLink>
        <RouterLink class="tm-side-item" :class="{ active: route.name === 'inbox' || route.name === 'task' }" :to="{ name: 'inbox' }">
          <i class="pi pi-inbox" />Minhas tarefas<span v-if="session.me?.counters.openTasks" class="tm-side-count">{{ session.me.counters.openTasks }}</span>
        </RouterLink>
        <RouterLink class="tm-side-item" :class="{ active: route.name === 'monitor' || route.name === 'instance' }" :to="{ name: 'monitor' }"><i class="pi pi-eye" />Acompanhar</RouterLink>
      </nav>
      <nav>
        <div class="tm-side-label">Áreas</div>
        <RouterLink class="tm-side-item tm-side-sub" :class="{ active: activeCategory === 'Todas' }" :to="{ name: 'catalog' }"><i class="pi pi-folder" />Todas</RouterLink>
        <RouterLink v-for="c in categories" :key="c" class="tm-side-item tm-side-sub" :class="{ active: activeCategory === c }" :to="{ name: 'catalog', query: { category: c } }"><i class="pi pi-folder" />{{ c }}</RouterLink>
      </nav>
      <nav v-if="session.can('reportsInstance') || session.can('reportsOperational')">
        <div class="tm-side-label">Análise</div>
        <RouterLink class="tm-side-item" :class="{ active: route.name === 'reports' }" :to="{ name: 'reports' }"><i class="pi pi-chart-bar" />Relatórios</RouterLink>
        <RouterLink v-if="session.can('auditRead')" class="tm-side-item" :class="{ active: route.name === 'audit' }" :to="{ name: 'audit' }"><i class="pi pi-shield" />Auditoria</RouterLink>
      </nav>
      <nav v-if="session.can('admin') || session.can('design') || session.can('audit') || session.can('processesManage')">
        <div class="tm-side-label">Administração</div>
        <RouterLink class="tm-side-item" :class="{ active: route.name === 'admin-groups' }" :to="{ name: 'admin-groups' }"><i class="pi pi-users" />Usuários e grupos</RouterLink>
        <RouterLink v-if="session.can('admin') || session.can('audit') || session.can('processesManage')" class="tm-side-item" :class="{ active: route.name === 'admin-integrations' }" :to="{ name: 'admin-integrations' }"><i class="pi pi-sitemap" />Integrações e entregas</RouterLink>
      </nav>
      <nav v-if="session.can('settingsManage')" aria-label="Configurações">
        <div class="tm-side-label">Configurações</div>
        <RouterLink class="tm-side-item" :class="{ active: String(route.name ?? '').startsWith('settings') }" :to="{ name: 'settings' }"><i class="pi pi-cog" />Configurações</RouterLink>
      </nav>
      <div class="tm-side-footer">
        {{ brand.branding.orgName }} · {{ envLabel }}<br />
        <strong style="color:#627384;font-weight:650">{{ methodLabel }}</strong>
      </div>
    </aside>
    <main class="tm-main">
      <div class="tm-page">
        <RouterView :key="route.path" />
      </div>
    </main>
  </div>
</template>
