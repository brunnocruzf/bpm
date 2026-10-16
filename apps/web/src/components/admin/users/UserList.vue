<script setup lang="ts">
import Paginator from 'primevue/paginator'
import UserAvatar from '@/components/UserAvatar.vue'
import { AUTH_SOURCE, fmtDateTime, USER_CATEGORY } from '@/composables/format'
import { userState } from './types'
import type { PageMeta, UserRow } from './types'

defineProps<{ users: UserRow[]; selectedId: string | null; loading: boolean; error: string; meta: PageMeta; canCreate: boolean; photoFor?: (u: UserRow) => string | null }>()
const emit = defineEmits<{ select: [UserRow]; page: [number]; create: [] }>()
</script>

<template>
  <section class="tm-panel user-list-panel" aria-label="Lista de usuários" :aria-busy="loading">
    <div class="tm-panel-head">
      <div><div class="tm-panel-title">Usuários</div><div class="tm-panel-caption">{{ meta.total }} resultado(s) · busca e filtros no servidor</div></div>
      <button v-if="canCreate" type="button" class="tm-btn primary" @click="emit('create')">Novo usuário</button>
    </div>
    <p v-if="error" class="tm-note err" role="alert" style="margin:12px">{{ error }}</p>
    <p v-else-if="loading && !users.length" class="tm-empty" role="status">Carregando usuários…</p>
    <p v-else-if="!users.length" class="tm-empty"><strong>Nenhum usuário encontrado</strong>Ajuste ou limpe os filtros.</p>
    <ul v-else class="user-list" :class="{ 'is-loading': loading }">
      <li v-for="u in users" :key="u.id">
        <button type="button" class="user-row" :data-user-id="u.id" :class="{ selected: u.id === selectedId }" :aria-current="u.id === selectedId ? 'true' : undefined" aria-controls="user-detail" @click="emit('select', u)">
          <UserAvatar :name="u.name" :size="36" :src="photoFor?.(u) ?? null" />
          <span class="who"><strong>{{ u.name }}</strong><small>{{ u.email || u.upn || u.username || '—' }}</small></span>
          <span class="pills">
            <span class="tm-pill neutral">{{ AUTH_SOURCE[u.authSource ?? ''] ?? u.authSource ?? '—' }}</span>
            <span v-if="u.userCategory" class="tm-pill" :class="USER_CATEGORY[u.userCategory]?.tone ?? 'neutral'">{{ USER_CATEGORY[u.userCategory]?.label ?? u.userCategory }}</span>
            <span class="tm-pill" :class="userState(u).tone">{{ userState(u).label }}</span>
          </span>
          <span class="seen" :title="u.lastSeenAt ? 'Último acesso' : 'Sem acesso registrado'">{{ u.lastSeenAt ? fmtDateTime(u.lastSeenAt) : '—' }}</span>
        </button>
      </li>
    </ul>
    <Paginator v-if="meta.lastPage > 1" :rows="meta.perPage ?? 25" :total-records="meta.total" :first="(meta.currentPage - 1) * (meta.perPage ?? 25)" template="PrevPageLink CurrentPageReport NextPageLink" current-page-report-template="Página {currentPage} de {totalPages}" @page="emit('page', $event.page + 1)" />
  </section>
</template>

<style scoped>
.user-list-panel { container-type: inline-size; }
.user-list { list-style: none; margin: 0; padding: 0; }
.user-list.is-loading { opacity: 0.6; transition: opacity 0.15s; }
.user-list li + li { border-top: 1px solid #edf1f4; }
.user-row { width: 100%; display: grid; grid-template-columns: 36px minmax(0, 1.4fr) minmax(0, 1.2fr) 110px; align-items: center; gap: 12px; padding: 10px 14px; border: 0; border-left: 3px solid transparent; background: transparent; text-align: left; cursor: pointer; font: inherit; color: inherit; }
.user-row:hover { background: var(--brand-softer); }
.user-row.selected { background: var(--brand-soft); border-left-color: var(--brand); }
.who { display: grid; min-width: 0; }
.who strong { font-size: 13px; color: #2c3e50; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.who small { color: var(--muted); font-size: 11px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pills { display: flex; flex-wrap: wrap; gap: 4px; }
.seen { color: var(--muted); font-size: 11px; text-align: right; }
@container (max-width: 720px) {
  .user-row { grid-template-columns: 36px minmax(0, 1fr); }
  .pills { grid-column: 2; }
  .seen { display: none; }
}
:deep(.p-paginator) { justify-content: flex-end; border-top: 1px solid #edf1f4; padding: 8px 12px; background: transparent; }
</style>
