import { computed, onBeforeUnmount, reactive, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

/** Situação do prazo (parâmetro "due" de /process-instances e /tasks). */
export const DUE_FILTERS = [
  { value: 'overdue', label: 'Vencido' },
  { value: 'today', label: 'Vence hoje' },
  { value: 'week', label: 'Próximos 7 dias' },
  { value: 'ontime', label: 'No prazo' },
  { value: 'none', label: 'Sem prazo' },
]

/**
 * Filtros de grid espelhados na URL (?campo=valor), para sobreviverem à volta da tarefa/solicitação.
 * Digitação recarrega com debounce; apply() aplica na hora (troca de aba, limpar).
 */
export function useGridFilters<K extends string>(keys: readonly K[], onChange: () => void, fixedQuery: () => Record<string, string> = () => ({})) {
  const route = useRoute()
  const router = useRouter()
  const fromUrl = () => Object.fromEntries(keys.flatMap((k) => (typeof route.query[k] === 'string' && route.query[k] ? [[k, route.query[k] as string]] : []))) as Partial<Record<K, string>>
  const initial = fromUrl()
  const filters = reactive(Object.fromEntries(keys.map((k) => [k, initial[k] ?? '']))) as Record<K, string>
  const isFilter = (k: string) => k !== 'sort' && k !== 'dir' // ordenação não conta como filtro nem é limpa
  const active = computed(() => keys.some((k) => isFilter(k) && filters[k] !== ''))
  const current = () => Object.fromEntries(keys.filter((k) => filters[k] !== '').map((k) => [k, filters[k]]))
  let applied = JSON.stringify(current())
  let timer: number | undefined

  function apply() {
    window.clearTimeout(timer)
    applied = JSON.stringify(current())
    router.replace({ query: { ...fixedQuery(), ...current() } })
    onChange()
  }

  function clear() {
    keys.forEach((k) => { if (isFilter(k)) filters[k] = '' })
    apply()
  }

  /** Clique no cabeçalho: crescente → decrescente → sem ordenação (padrão do grid). Requer 'sort' e 'dir' em keys. */
  function toggleSort(column: string) {
    const f = filters as Record<string, string>
    if (f.sort !== column) { f.sort = column; f.dir = 'asc' }
    else if (f.dir === 'asc') f.dir = 'desc'
    else { f.sort = ''; f.dir = '' }
    apply()
  }

  function sortIcon(column: string) {
    const f = filters as Record<string, string>
    return f.sort !== column ? 'pi pi-sort-alt' : f.dir === 'desc' ? 'pi pi-sort-amount-down' : 'pi pi-sort-amount-up-alt'
  }

  watch(filters, () => {
    window.clearTimeout(timer)
    timer = window.setTimeout(() => { if (JSON.stringify(current()) !== applied) apply() }, 350)
  })
  // navegação externa para a mesma tela (busca global, menu lateral): adota os filtros da URL
  const routeName = route.name
  watch(() => route.query, () => {
    const incoming = fromUrl()
    if (route.name !== routeName || JSON.stringify(incoming) === applied) return
    keys.forEach((k) => { filters[k] = incoming[k] ?? '' })
    apply()
  })
  onBeforeUnmount(() => window.clearTimeout(timer))

  return { filters, active, apply, clear, toggleSort, sortIcon }
}
