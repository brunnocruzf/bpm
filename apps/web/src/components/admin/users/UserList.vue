<script setup lang="ts">
import Paginator from 'primevue/paginator'
import UserAvatar from '@/components/UserAvatar.vue'
import { useSession } from '@/stores/session'
import { AUTH_SOURCE, fmtDateTime, USER_CATEGORY } from '@/composables/format'
import { userState } from './types'
import type { PageMeta, UserRow } from './types'

defineProps<{ users: UserRow[]; selectedId: string | null; loading: boolean; error: string; meta: PageMeta; canCreate: boolean; photoFor?: (u: UserRow) => string | null }>()
const emit = defineEmits<{ select: [UserRow]; page: [number]; create: [] }>()
const session = useSession()

/** Quantos grupos aparecem como pílula antes do contador "+N". */
const VISIBLE_GROUPS = 2
const groupNames = (u: UserRow) => u.groups.map((k) => session.groupName(k))
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
    <template v-else>
    <div class="cols list-head" aria-hidden="true"><span /><span>Usuário</span><span class="c-area">Área</span><span class="c-dept">Departamento</span><span class="c-groups">Grupos/raias</span><span class="pills">Origem e situação</span><span class="seen">Último acesso</span></div>
    <ul class="user-list" :class="{ 'is-loading': loading }">
      <li v-for="u in users" :key="u.id">
        <button type="button" class="cols user-row" :data-user-id="u.id" :class="{ selected: u.id === selectedId }" :aria-current="u.id === selectedId ? 'true' : undefined" aria-controls="user-detail" @click="emit('select', u)">
          <UserAvatar :name="u.name" :size="36" :src="photoFor?.(u) ?? null" />
          <span class="who"><strong>{{ u.name }}</strong><small>{{ u.email || u.upn || u.username || '—' }}</small></span>
          <span class="cell c-area" :title="u.area ?? undefined"><span class="tm-sr-only">Área: </span>{{ u.area || '—' }}</span>
          <span class="cell c-dept" :title="u.department ?? undefined"><span class="tm-sr-only">Departamento: </span>{{ u.department || '—' }}</span>
          <span class="c-groups" :title="groupNames(u).join(', ') || undefined"><span class="tm-sr-only">Grupos/raias: </span>
            <span v-for="(name, i) in groupNames(u).slice(0, VISIBLE_GROUPS)" :key="i" class="tm-pill neutral">{{ name }}</span>
            <span v-if="u.groups.length > VISIBLE_GROUPS" class="tm-pill neutral more">+{{ u.groups.length - VISIBLE_GROUPS }}</span>
            <span v-if="!u.groups.length" class="cell">—</span>
          </span>
          <span class="pills">
            <span class="tm-pill neutral">{{ AUTH_SOURCE[u.authSource ?? ''] ?? u.authSource ?? '—' }}</span>
            <span v-if="u.userCategory" class="tm-pill" :class="USER_CATEGORY[u.userCategory]?.tone ?? 'neutral'">{{ USER_CATEGORY[u.userCategory]?.label ?? u.userCategory }}</span>
            <span class="tm-pill" :class="userState(u).tone">{{ userState(u).label }}</span>
          </span>
          <span class="seen" :title="u.lastSeenAt ? 'Último acesso' : 'Sem acesso registrado'">{{ u.lastSeenAt ? fmtDateTime(u.lastSeenAt) : '—' }}</span>
        </button>
      </li>
    </ul>
    </template>
    <Paginator v-if="meta.lastPage > 1" :rows="meta.perPage ?? 25" :total-records="meta.total" :first="(meta.currentPage - 1) * (meta.perPage ?? 25)" template="PrevPageLink CurrentPageReport NextPageLink" current-page-report-template="Página {currentPage} de {totalPages}" @page="emit('page', $event.page + 1)" />
  </section>
</template>

<style scoped>
.user-list-panel { container-type: inline-size; }
.user-list { list-style: none; margin: 0; padding: 0; }
.user-list.is-loading { opacity: 0.6; transition: opacity 0.15s; }
.user-list li + li { border-top: 1px solid #edf1f4; }
/* Colunas somem conforme a largura do painel (inclusive com o perfil aberto ao lado): departamento → último acesso → grupos → área. */
.cols { display: grid; grid-template-columns: 36px minmax(150px, 1.5fr) minmax(0, 0.9fr) minmax(0, 0.9fr) minmax(0, 1.2fr) minmax(0, 1.3fr) 104px; align-items: center; gap: 12px; padding: 10px 14px; border-left: 3px solid transparent; }
.list-head { padding-block: 7px; border-bottom: 1px solid #edf1f4; background: #fbfcfd; color: var(--muted); font-size: 10.5px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; }
.user-row { width: 100%; border-top: 0; border-right: 0; border-bottom: 0; background: transparent; text-align: left; cursor: pointer; font: inherit; color: inherit; }
.user-row:hover { background: var(--brand-softer); }
.user-row.selected { background: var(--brand-soft); border-left-color: var(--brand); }
.who { display: grid; min-width: 0; }
.who strong { font-size: 13px; color: #2c3e50; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.who small { color: var(--muted); font-size: 11px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cell { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #4f6276; font-size: 12px; }
.c-groups { display: flex; gap: 4px; min-width: 0; overflow: hidden; }
.c-groups .tm-pill { flex: 0 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.c-groups .tm-pill.more { flex: none; }
.pills { display: flex; flex-wrap: wrap; gap: 4px; }
.seen { color: var(--muted); font-size: 11px; text-align: right; }
.list-head .seen { font-size: inherit; }
@container (max-width: 1080px) {
  .cols { grid-template-columns: 36px minmax(150px, 1.5fr) minmax(0, 0.9fr) minmax(0, 1.2fr) minmax(0, 1.3fr) 104px; }
  .c-dept { display: none; }
}
@container (max-width: 900px) {
  .cols { grid-template-columns: 36px minmax(140px, 1.5fr) minmax(0, 0.9fr) minmax(0, 1.2fr) minmax(0, 1.3fr); }
  .seen { display: none; }
}
@container (max-width: 740px) {
  .cols { grid-template-columns: 36px minmax(0, 1.5fr) minmax(0, 0.9fr) minmax(0, 1.3fr); }
  .c-groups { display: none; }
}
@container (max-width: 560px) {
  .cols { grid-template-columns: 36px minmax(0, 1fr); }
  .list-head, .c-area { display: none; }
  .pills { grid-column: 2; }
}
:deep(.p-paginator) { justify-content: flex-end; border-top: 1px solid #edf1f4; padding: 8px 12px; background: transparent; }
</style>
