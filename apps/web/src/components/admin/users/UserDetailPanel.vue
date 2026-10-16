<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Select from 'primevue/select'
import UserAvatar from '@/components/UserAvatar.vue'
import { useSession } from '@/stores/session'
import { useNotify } from '@/composables/notify'
import { AUTH_SOURCE, DELIVERY_ERROR, DISABLED_REASON, ENTRA_USER_TYPE, fmtDateTime, INVITATION_DELIVERY, USER_CATEGORY, USER_CATEGORY_OPTIONS } from '@/composables/format'
import { userState } from './types'
import type { UserDetail, UserRow } from './types'

const props = defineProps<{ row: UserRow | null; detail: UserDetail | null; loading: boolean; canManage: boolean; busy: boolean; loadingActivity: boolean; photo?: string | null; expanded?: boolean }>()
const emit = defineEmits<{ close: []; select: [string]; toggle: []; resend: []; 'change-category': [string]; 'more-activity': []; 'refresh-photo': []; expand: [boolean] }>()
const session = useSession()
const notify = useNotify()

const identity = computed(() => ({
  id: props.detail?.identity.id ?? props.row?.id ?? '',
  name: props.detail?.identity.name ?? props.row?.name ?? '',
  email: props.detail?.identity.email ?? props.row?.email ?? null,
  upn: props.detail?.identity.upn ?? props.row?.upn ?? null,
  username: props.detail?.identity.username ?? props.row?.username ?? null,
  source: props.detail?.identity.authSource ?? props.row?.authSource ?? null,
}))
const category = computed(() => props.detail?.organization.userCategory ?? props.row?.userCategory ?? null)
const state = computed(() => {
  const d = props.detail
  if (d) return userState({ isActive: d.account.active, authSource: d.identity.authSource, invitationStatus: d.integration.local?.invitation.status ?? null, locked: !!d.integration.local?.credential.lockedUntil, disabledReason: d.account.disabledReason })
  return props.row ? userState(props.row) : { label: '…', tone: 'neutral' }
})
const isLocal = computed(() => identity.value.source === 'local')
const local = computed(() => props.detail?.integration.local ?? null)
const entra = computed(() => props.detail?.integration.entra ?? null)
const categoryDraft = ref('')
watch(category, (c) => { categoryDraft.value = c ?? '' }, { immediate: true })

const roleLabel = (r: string) => r.replace(/^Bpm\./, '').replace(/\./g, ' ')
const roleSources = (r: string) => {
  const s = props.detail?.access.roleSources
  return s ? [s.token.includes(r) && 'Token', s.directoryMapping.includes(r) && 'Mapeamento Entra', s.manual.includes(r) && 'Manual BPM'].filter(Boolean) as string[] : []
}
const groupSources = (k: string) => {
  const s = props.detail?.access.groupSources
  return s ? [s.entraToken.includes(k) && 'Token', s.directoryMapping.includes(k) && 'Mapeamento Entra', s.manual.some((m) => m.key === k) && 'Manual BPM'].filter(Boolean) as string[] : []
}
const invitationText = computed(() => {
  const i = local.value?.invitation
  if (!i) return '—'
  if (i.status === 'accepted') return `Aceito em ${fmtDateTime(i.acceptedAt)}`
  if (i.status === 'pending') return `Pendente até ${fmtDateTime(i.expiresAt)}`
  if (i.status === 'expired') return `Expirou em ${fmtDateTime(i.expiresAt)}`
  return 'Nenhum convite emitido'
})
const delivery = computed(() => {
  const d = local.value?.invitation.delivery
  if (!d) return null
  return { ...(INVITATION_DELIVERY[d.state] ?? { label: d.state, tone: 'neutral' }), reason: d.errorCode ? (DELIVERY_ERROR[d.errorCode] ?? d.errorCode) : null }
})
const PHOTO_STATUS: Record<string, string> = { present: 'Importada', absent: 'Sem foto no Entra ID', error: 'Não foi possível importar', unchecked: 'Ainda não verificada' }
const photoText = computed(() => {
  const p = entra.value?.photo
  if (!p) return '—'
  if (!p.enabled) return 'Importação desligada'
  return `${PHOTO_STATUS[p.status] ?? p.status}${p.checkedAt ? ` · verificada em ${fmtDateTime(p.checkedAt)}` : ''}`
})
const canLoadMore = computed(() => !!props.detail && props.detail.activity.meta.currentPage < props.detail.activity.meta.lastPage)

async function copy(text: string | null) {
  if (!text) return
  try { await navigator.clipboard.writeText(text); notify.info('Copiado.') } catch { notify.info('Não foi possível copiar automaticamente.') }
}
</script>

<template>
  <section id="user-detail" class="detail" :class="{ expanded }" aria-labelledby="user-detail-title">
    <header class="d-head">
      <UserAvatar :name="identity.name" :size="52" :src="photo ?? null" />
      <div class="d-title">
        <h2 id="user-detail-title" tabindex="-1">{{ identity.name }}</h2>
        <div class="tm-flow-sub">{{ identity.email || identity.upn || identity.username || '—' }}</div>
        <div class="pills">
          <span class="tm-pill neutral">{{ AUTH_SOURCE[identity.source ?? ''] ?? identity.source ?? '—' }}</span>
          <span v-if="category" class="tm-pill" :class="USER_CATEGORY[category]?.tone ?? 'neutral'">{{ USER_CATEGORY[category]?.label ?? category }}</span>
          <span class="tm-pill" :class="state.tone">{{ state.label }}</span>
          <span v-if="entra?.userType" class="tm-pill blue">{{ ENTRA_USER_TYPE[entra.userType] ?? entra.userType }}</span>
        </div>
      </div>
      <div class="d-tools">
        <button type="button" class="tm-btn ghost icon" :aria-label="expanded ? 'Recolher perfil para o painel lateral' : 'Expandir perfil em tela ampla'" :title="expanded ? 'Recolher' : 'Expandir'" @click="emit('expand', !expanded)"><i class="pi" :class="expanded ? 'pi-window-minimize' : 'pi-window-maximize'" aria-hidden="true" /></button>
        <button type="button" class="tm-btn ghost icon" aria-label="Fechar painel do usuário" title="Fechar" @click="emit('close')"><i class="pi pi-times" aria-hidden="true" /></button>
      </div>
    </header>

    <p v-if="loading && !detail" class="tm-empty" role="status">Carregando perfil…</p>
    <template v-else-if="detail">
      <div v-if="canManage && isLocal" class="d-actions">
        <button v-if="!detail.account.active && local?.invitation.status === 'accepted'" type="button" class="tm-btn small" :disabled="busy" @click="emit('toggle')">Ativar conta</button>
        <button v-if="detail.account.active" type="button" class="tm-btn small danger" :disabled="busy" @click="emit('toggle')">Desativar conta</button>
        <button v-if="!detail.account.active && local?.invitation.status !== 'accepted'" type="button" class="tm-btn small" :disabled="busy" @click="emit('resend')">Reenviar convite</button>
      </div>

      <section class="block"><h3>Resumo</h3>
        <dl class="tm-kv">
          <dt>Último acesso</dt><dd>{{ fmtDateTime(detail.account.lastSeenAt) }}</dd>
          <dt>Conta criada</dt><dd>{{ fmtDateTime(detail.account.createdAt) }}</dd>
          <dt>Área</dt><dd>{{ detail.organization.area || '—' }}</dd>
          <dt>Departamento</dt><dd>{{ detail.organization.department || '—' }}</dd>
          <dt>Gestor</dt>
          <dd>
            <button v-if="detail.organization.managerInfo" type="button" class="tm-link-btn" @click="emit('select', detail.organization.managerInfo.id)">{{ detail.organization.managerInfo.name }}</button>
            <span v-else-if="detail.organization.managerPending" class="tm-flow-sub" title="A referência será vinculada quando o gestor for importado">Aguardando importação</span>
            <span v-else>—</span>
          </dd>
          <dt>Subordinados diretos</dt><dd>{{ detail.organization.directReports }}</dd>
          <template v-if="!detail.account.active && detail.account.disabledReason"><dt>Motivo da inativação</dt><dd>{{ DISABLED_REASON[detail.account.disabledReason] ?? detail.account.disabledReason }}<span v-if="detail.account.disabledAt"> · {{ fmtDateTime(detail.account.disabledAt) }}</span></dd></template>
        </dl>
        <div v-if="canManage && isLocal" class="category-edit">
          <label for="detail-category">Tipo de usuário</label>
          <div class="row"><Select input-id="detail-category" v-model="categoryDraft" :options="USER_CATEGORY_OPTIONS" option-label="label" option-value="value" placeholder="Não informado" />
            <button type="button" class="tm-btn small" :disabled="busy || !categoryDraft || categoryDraft === category" @click="emit('change-category', categoryDraft)">Salvar tipo</button></div>
        </div>
      </section>

      <section class="block"><h3>Integração e origem</h3>
        <template v-if="entra">
          <dl class="tm-kv">
            <dt>Sincronização</dt><dd>{{ entra.importedBySync ? 'Importado pelo diretório' : 'Criado no primeiro login (ainda não importado)' }}</dd>
            <dt>Última mudança recebida</dt><dd>{{ fmtDateTime(entra.directorySyncedAt) }}</dd>
            <dt>Habilitado no Entra ID</dt><dd>{{ entra.accountEnabled === null ? 'Não informado' : entra.accountEnabled ? 'Sim' : 'Não' }}</dd>
            <dt>Tipo no Entra ID</dt><dd>{{ entra.userType ? (ENTRA_USER_TYPE[entra.userType] ?? entra.userType) : 'Não informado' }}</dd>
            <dt>UPN</dt><dd class="wrap">{{ entra.upn || '—' }}</dd>
            <dt>Foto do Entra ID</dt><dd>{{ photoText }}<button v-if="canManage && entra.photo.enabled" type="button" class="tm-link-btn" :disabled="busy" @click="emit('refresh-photo')"> · atualizar agora</button></dd>
          </dl>
          <h4>Grupos do Entra ID ({{ entra.groups.length }})</h4>
          <ul v-if="entra.groups.length" class="plain">
            <li v-for="g in entra.groups" :key="g.objectId"><strong>{{ g.name }}</strong>
              <div v-if="g.grants.length" class="tm-flow-sub">Concede: <span v-for="(gr, i) in g.grants" :key="i">{{ gr.groupKey ? `raia ${gr.groupKey}` : '' }}{{ gr.roles.length ? ` · ${gr.roles.map(roleLabel).join(', ')}` : '' }}{{ gr.privileged ? ' (privilegiado)' : '' }}</span></div>
              <div v-else class="tm-flow-sub">Sem mapeamento de acesso</div></li>
          </ul>
          <p v-else class="tm-flow-sub">Nenhum grupo importado para este usuário.</p>
          <div v-if="entra.sync.alerts.length" class="tm-note warn" role="status"><i class="pi pi-exclamation-triangle" aria-hidden="true" /><span><span v-for="a in entra.sync.alerts" :key="a.code" class="alert-line">{{ a.message }}</span></span></div>
          <p class="tm-flow-sub">Última sincronização concluída: {{ fmtDateTime(entra.sync.lastSuccessAt) }}<template v-if="entra.sync.nextRunAt"> · próxima: {{ fmtDateTime(entra.sync.nextRunAt) }}</template> · <RouterLink :to="{ name: 'settings-entra-directory' }">Configurações do Entra ID</RouterLink></p>
          <details class="tech"><summary>Identificadores técnicos</summary>
            <dl class="tm-kv">
              <dt>Object ID</dt><dd class="wrap"><code>{{ entra.oid }}</code> <button type="button" class="tm-link-btn" @click="copy(entra.oid)">copiar</button></dd>
              <dt>Tenant ID</dt><dd class="wrap"><code>{{ entra.tid }}</code> <button type="button" class="tm-link-btn" @click="copy(entra.tid)">copiar</button></dd>
            </dl>
          </details>
        </template>
        <template v-else-if="local">
          <dl class="tm-kv">
            <dt>Convite</dt><dd>{{ invitationText }}</dd>
            <dt>Enviado por</dt><dd>{{ local.invitation.invitedBy || '—' }}<span v-if="local.invitation.sentAt"> · {{ fmtDateTime(local.invitation.sentAt) }}</span></dd>
            <dt>E-mail do convite</dt>
            <dd><template v-if="delivery"><span class="tm-pill" :class="delivery.tone">{{ delivery.label }}</span><span v-if="delivery.reason" class="tm-flow-sub"> — {{ delivery.reason }}</span></template><template v-else>—</template></dd>
            <dt>Último login local</dt><dd>{{ fmtDateTime(local.credential.lastLoginAt) }}</dd>
            <dt>Tentativas falhas</dt><dd>{{ local.credential.failedAttempts }}</dd>
            <dt>Bloqueio</dt><dd>{{ local.credential.lockedUntil ? `Até ${fmtDateTime(local.credential.lockedUntil)}` : 'Não' }}</dd>
            <dt>Sessões ativas</dt><dd>{{ local.sessions.active }}<span v-if="local.sessions.lastUsedAt"> · último uso {{ fmtDateTime(local.sessions.lastUsedAt) }}</span></dd>
            <dt>Senha definida</dt><dd>{{ fmtDateTime(local.credential.passwordChangedAt) }}</dd>
          </dl>
        </template>
        <p v-else class="tm-flow-sub">{{ identity.source === 'superadmin' ? 'Acesso de emergência (SuperAdmin): gerenciado pela plataforma e auditado.' : 'Perfil de demonstração (visitante): não possui integração externa.' }}</p>
      </section>

      <section class="block"><h3>Acesso efetivo</h3>
        <h4>Papéis</h4>
        <ul v-if="detail.access.roles.length" class="plain"><li v-for="r in detail.access.roles" :key="r"><span class="tm-pill brand">{{ roleLabel(r) }}</span> <small class="tm-flow-sub">{{ roleSources(r).join(' · ') || 'Efetivo' }}</small></li></ul>
        <p v-else class="tm-flow-sub">Nenhum papel.</p>
        <h4>Grupos/raias</h4>
        <ul v-if="detail.access.groups.length" class="plain"><li v-for="k in detail.access.groups" :key="k"><span class="tm-pill neutral">{{ session.groupName(k) }}</span> <small class="tm-flow-sub">{{ groupSources(k).join(' · ') || 'Efetivo' }}</small></li></ul>
        <p v-else class="tm-flow-sub">Nenhum grupo.</p>
        <p v-if="detail.access.groupSources.manual.length" class="tm-flow-sub">Vínculos manuais: {{ detail.access.groupSources.manual.map((m) => `${m.name} (${m.createdBy || 'ator indisponível'}, ${fmtDateTime(m.createdAt)})`).join('; ') }}</p>
      </section>

      <section class="block"><h3>Atividade recente</h3>
        <div v-if="detail.activity.data.length" class="tm-timeline">
          <div v-for="(e, i) in detail.activity.data" :key="e.action + e.occurredAt + i" class="tm-tl-item">
            <div class="tm-tl-marker"><span class="tm-tl-dot" :class="{ muted: e.outcome !== 'success' }" /></div>
            <div class="tm-tl-text"><strong>{{ e.label }}</strong><span>{{ e.actor || 'Sistema' }} · {{ fmtDateTime(e.occurredAt) }}</span></div>
          </div>
        </div>
        <p v-else class="tm-flow-sub">Sem eventos registrados.</p>
        <button v-if="canLoadMore" type="button" class="tm-btn small" :disabled="loadingActivity" @click="emit('more-activity')">Carregar mais</button>
      </section>
    </template>
    <p v-else class="tm-empty">Não foi possível carregar o perfil.</p>
  </section>
</template>

<style scoped>
.detail { padding: 16px; display: grid; gap: 14px; align-content: start; }
.d-head { display: grid; grid-template-columns: 52px minmax(0, 1fr) auto; gap: 12px; align-items: start; }
.d-tools { display: flex; gap: 2px; }
/* Perfil ampliado (modal): seções em duas colunas; cabeçalho e ações ocupam a largura toda. */
.detail.expanded { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 28px; padding: 20px 24px 24px; }
.detail.expanded > :not(.block) { grid-column: 1 / -1; }
.detail.expanded .d-title h2 { font-size: 19px; }
@media (max-width: 760px) { .detail.expanded { grid-template-columns: minmax(0, 1fr); padding: 16px; } }
.d-title h2 { margin: 0; font-size: 16px; overflow-wrap: anywhere; }
.d-title h2:focus { outline: none; }
.pills { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 8px; }
.d-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.block { border-top: 1px solid #edf1f4; padding-top: 12px; }
.block h3 { margin: 0 0 8px; font-size: 12px; color: #2c3e50; }
.block h4 { margin: 12px 0 6px; font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em; }
.tm-kv dd.wrap { overflow-wrap: anywhere; }
.plain { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; font-size: 12px; }
.tech { margin-top: 10px; font-size: 12px; }
.tech summary { cursor: pointer; color: var(--brand-text); font-weight: 650; }
.tech code { font-size: 11px; }
.category-edit { margin-top: 12px; }
.category-edit label { display: block; margin-bottom: 6px; color: #667789; font-size: 11px; font-weight: 700; }
.category-edit .row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.alert-line { display: block; }
</style>
