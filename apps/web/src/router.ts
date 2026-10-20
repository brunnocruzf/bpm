import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { auth } from './auth'
import { useSession } from './stores/session'
import { docsRoutes } from './docs/catalog'

const routes: RouteRecordRaw[] = [
  // portal de documentação de integrações: pública, antes do layout autenticado
  docsRoutes,
  // login: Microsoft Entra ID (principal), visitante (ambiente de demonstração) e SuperAdmin (emergência)
  { path: '/login', name: 'login', component: () => import('./views/LoginView.vue'), meta: { public: true }, props: { mode: 'entra' } },
  { path: '/login/visitante', name: 'login-demo', component: () => import('./views/LoginView.vue'), meta: { public: true }, props: { mode: 'demo' } },
  { path: '/login/superadmin', name: 'login-superadmin', component: () => import('./views/LoginView.vue'), meta: { public: true }, props: { mode: 'superadmin' } },
  { path: '/login/local', name: 'login-local', component: () => import('./views/LoginView.vue'), meta: { public: true }, props: { mode: 'local' } },
  { path: '/login/local/activate', name: 'login-local-activate', component: () => import('./views/LoginView.vue'), meta: { public: true }, props: { mode: 'activate' } },
  { path: '/login/local/reset', name: 'login-local-reset', component: () => import('./views/LoginView.vue'), meta: { public: true }, props: { mode: 'reset' } },
  {
    path: '/',
    component: () => import('./layouts/AppShell.vue'),
    children: [
      { path: '', name: 'catalog', component: () => import('./views/CatalogView.vue') },
      { path: 'flows/:flowKey/edit', name: 'flow-editor', component: () => import('./views/FlowEditorView.vue'), props: true },
      { path: 'flows/:flowKey/start', name: 'flow-start', component: () => import('./views/StartView.vue'), props: true },
      { path: 'flows/:flowKey/results', name: 'flow-results', component: () => import('./views/ResultsView.vue'), props: true },
      { path: 'tasks', name: 'inbox', component: () => import('./views/InboxView.vue') },
      { path: 'tasks/:id', name: 'task', component: () => import('./views/TaskView.vue'), props: true },
      { path: 'instances/:id', name: 'instance', component: () => import('./views/InstanceView.vue'), props: true },
      { path: 'monitor', name: 'monitor', component: () => import('./views/MonitorView.vue') },
      { path: 'admin/groups', name: 'admin-groups', component: () => import('./views/admin/AdminGroupsView.vue') },
      { path: 'admin/integrations', name: 'admin-integrations', component: () => import('./views/admin/AdminIntegrationsView.vue') },
      { path: 'reports', name: 'reports', component: () => import('./views/ReportsView.vue') },
      { path: 'audit', name: 'audit', component: () => import('./views/AuditView.vue'), meta: { permission: 'auditRead' } },
      {
        path: 'settings',
        component: () => import('./views/settings/SettingsLayout.vue'),
        meta: { permission: 'settingsManage' },
        children: [
          { path: '', name: 'settings', component: () => import('./views/settings/SettingsOverview.vue') },
          { path: 'branding', name: 'settings-branding', component: () => import('./views/settings/BrandingSettings.vue') },
          { path: 'entra-login', name: 'settings-entra-auth', component: () => import('./views/settings/EntraAuthSettings.vue') },
          { path: 'entra-directory', name: 'settings-entra-directory', component: () => import('./views/settings/DirectorySettings.vue') },
          { path: 'email', name: 'settings-email', component: () => import('./views/settings/EmailSettings.vue') },
          { path: 'audit-retention', name: 'settings-audit', component: () => import('./views/settings/AuditRetentionSettings.vue') },
          { path: 'reports', name: 'settings-reports', component: () => import('./views/settings/ReportSettings.vue') },
          { path: 'attachments-storage', name: 'settings-attachments-storage', component: () => import('./views/settings/AttachmentStorageSettings.vue') },
        ],
      },
      { path: ':pathMatch(.*)*', name: 'not-found', component: () => import('./views/NotFoundView.vue') },
    ],
  },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 }),
})

router.beforeEach(async (to) => {
  if (to.meta.public) return true
  if (!auth.isAuthenticated()) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  const session = useSession()
  if (!session.me) {
    try {
      await session.load()
    } catch {
      return { name: 'login', query: { redirect: to.fullPath } }
    }
  }
  // conveniência de navegação: a autorização real é feita pela API (403/404)
  const permission = to.matched.map((r) => r.meta.permission as string | undefined).find(Boolean)
  if (permission && !session.can(permission as any)) {
    return { name: 'not-found' }
  }
  return true
})
