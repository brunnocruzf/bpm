<script setup lang="ts">
import { computed } from 'vue'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import { useSession } from '@/stores/session'
import { AUTH_SOURCE, USER_CATEGORY } from '@/composables/format'
import type { Facets, UserFilters } from './types'

const props = defineProps<{ modelValue: UserFilters; facets: Facets | null; total: number }>()
const emit = defineEmits<{ 'update:modelValue': [UserFilters]; clear: [] }>()
const session = useSession()

const set = (patch: Partial<UserFilters>) => emit('update:modelValue', { ...props.modelValue, ...patch })
const withCount = (label: string, n?: number) => (n === undefined ? label : `${label} (${n})`)

const sourceOptions = computed(() => [{ label: 'Todas as origens', value: '' }, ...Object.entries(AUTH_SOURCE).map(([value, label]) => ({ label: withCount(label, props.facets?.source[value]), value }))])
const activeOptions = computed(() => [
  { label: 'Todas as situações', value: '' },
  { label: withCount('Ativos', props.facets?.active.true), value: 'true' },
  { label: withCount('Inativos', props.facets?.active.false), value: 'false' },
])
const categoryOptions = computed(() => [
  { label: 'Todos os tipos', value: '' },
  ...Object.entries(USER_CATEGORY).map(([value, v]) => ({ label: withCount(v.label, props.facets?.category[value]), value })),
  { label: withCount('Não informado', props.facets?.category.none), value: 'none' },
])
const ATTENTION_LABELS: Record<string, string> = {
  entra_disabled: 'Desabilitado no Entra ID', guest: 'Convidado (Guest)', login_only: 'Só login (não importado)', manager_pending: 'Gestor aguardando importação',
  invitation_pending: 'Convite pendente', invitation_expired: 'Convite expirado', locked: 'Conta bloqueada',
}
const attentionOptions = computed(() => [{ label: 'Sem filtro de integração', value: '' }, ...Object.entries(ATTENTION_LABELS).map(([value, label]) => ({ label: withCount(label, props.facets?.attention[value]), value }))])
const areaOptions = computed(() => [{ label: 'Todas as áreas', value: '' }, ...(props.facets?.areas ?? []).map((a) => ({ label: a, value: a }))])
const groupOptions = computed(() => [{ label: 'Todos os grupos', value: '' }, ...(session.meta?.groups ?? []).map((g) => ({ label: g.name, value: g.key }))])
const roleOptions = computed(() => [{ label: 'Todos os papéis', value: '' }, ...(props.facets?.roles ?? []).map((r) => ({ label: r.label, value: r.key }))])
const active = computed(() => Object.values(props.modelValue).filter(Boolean).length)
</script>

<template>
  <section class="tm-panel user-filters" role="search" aria-label="Filtros de usuários">
    <div class="filters-head">
      <span class="tm-panel-title">Filtros</span><span class="tm-panel-caption">{{ total }} identidade(s)</span>
      <button v-if="active" type="button" class="tm-link-btn" @click="emit('clear')">Limpar ({{ active }})</button>
    </div>
    <div class="filters-body">
      <div class="tm-field f-search"><label for="uf-q">Buscar</label><InputText id="uf-q" :model-value="modelValue.q" type="search" placeholder="Nome, e-mail ou usuário" aria-label="Buscar usuário por nome, e-mail ou identificador" @update:model-value="set({ q: String($event ?? '') })" /></div>
      <div class="tm-field"><label for="uf-source">Origem</label><Select input-id="uf-source" placeholder="Todas as origens" :model-value="modelValue.source" :options="sourceOptions" option-label="label" option-value="value" @update:model-value="set({ source: $event })" /></div>
      <div class="tm-field"><label for="uf-active">Situação</label><Select input-id="uf-active" placeholder="Todas as situações" :model-value="modelValue.active" :options="activeOptions" option-label="label" option-value="value" @update:model-value="set({ active: $event })" /></div>
      <div class="tm-field"><label for="uf-category">Tipo de usuário</label><Select input-id="uf-category" placeholder="Todos os tipos" :model-value="modelValue.category" :options="categoryOptions" option-label="label" option-value="value" @update:model-value="set({ category: $event })" /></div>
      <div class="tm-field"><label for="uf-attention">Integração</label><Select input-id="uf-attention" placeholder="Sem filtro de integração" :model-value="modelValue.attention" :options="attentionOptions" option-label="label" option-value="value" @update:model-value="set({ attention: $event })" /></div>
      <div class="tm-field"><label for="uf-area">Área</label><Select input-id="uf-area" placeholder="Todas as áreas" :model-value="modelValue.area" :options="areaOptions" option-label="label" option-value="value" filter @update:model-value="set({ area: $event })" /></div>
      <div class="tm-field"><label for="uf-group">Grupo/raia</label><Select input-id="uf-group" placeholder="Todos os grupos" :model-value="modelValue.group" :options="groupOptions" option-label="label" option-value="value" @update:model-value="set({ group: $event })" /></div>
      <div class="tm-field"><label for="uf-role">Papel</label><Select input-id="uf-role" placeholder="Todos os papéis" :model-value="modelValue.role" :options="roleOptions" option-label="label" option-value="value" @update:model-value="set({ role: $event })" /></div>
    </div>
  </section>
</template>

<style scoped>
.user-filters { padding: 12px 14px 2px; }
.filters-head { display: flex; align-items: baseline; flex-wrap: wrap; gap: 4px 10px; margin-bottom: 10px; }
.filters-head .tm-link-btn { margin-left: auto; }
.filters-body { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 0 12px; }
.filters-body .f-search { grid-column: span 2; }
.filters-body :deep(.p-select), .filters-body :deep(.p-inputtext) { width: 100%; min-width: 0; }
.filters-body :deep(.p-inputtext), .filters-body :deep(.p-select-label) { font-size: 12px; }
.filters-body .tm-field { margin-bottom: 10px; min-width: 0; }
@media (max-width: 420px) { .filters-body .f-search { grid-column: auto; } }
</style>
