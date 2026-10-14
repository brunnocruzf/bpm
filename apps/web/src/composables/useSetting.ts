import { ref } from 'vue'
import { api, ApiError } from '@/api/http'
import type { SettingView } from '@/api/types'
import { useNotify } from './notify'

/** Carrega/grava uma configuração (Configurações → seção). Erros de validação ficam em `errors`. */
export function useSetting<T = Record<string, any>>(slug: string) {
  const setting = ref<SettingView<T> | null>(null)
  const loading = ref(false)
  const saving = ref(false)
  const errors = ref<Record<string, string[]>>({})
  const notify = useNotify()

  async function load() {
    loading.value = true
    try {
      setting.value = await api<SettingView<T>>(`/admin/settings/${slug}`)
    } catch (e) {
      notify.error(e)
    } finally {
      loading.value = false
    }
  }

  async function save(body: Record<string, unknown>, message = 'Configuração salva.') {
    saving.value = true
    errors.value = {}
    try {
      setting.value = await api<SettingView<T>>(`/admin/settings/${slug}`, { method: 'PUT', body })
      notify.success(message)
      return true
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) errors.value = e.errors
      notify.error(e)
      return false
    } finally {
      saving.value = false
    }
  }

  /** Ação POST que devolve a configuração atualizada (validar, testar, ativar). */
  async function action(path: string, body?: Record<string, unknown>, message?: string) {
    saving.value = true
    try {
      setting.value = await api<SettingView<T>>(`/admin/settings/${path}`, { method: 'POST', body: body ?? {} })
      if (message) notify.success(message)
      return true
    } catch (e) {
      notify.error(e)
      return false
    } finally {
      saving.value = false
    }
  }

  const err = (field: string) => errors.value[field]?.[0]

  return { setting, loading, saving, errors, err, load, save, action }
}
