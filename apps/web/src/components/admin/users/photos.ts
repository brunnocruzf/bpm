import { onBeforeUnmount, reactive } from 'vue'
import { apiBlob } from '@/api/http'

/**
 * Fotos de usuários para a administração: buscadas com o token (apiBlob) e expostas como object URLs, em cache por
 * `id:versão` — uma foto nova muda a versão e substitui a URL. Falhas não são repetidas a cada renderização.
 */
export function useUserPhotos() {
  const urls = reactive(new Map<string, string>())
  const settled = new Set<string>()

  function get(id: string, version: string | null | undefined): string | null {
    if (!version) return null
    const key = `${id}:${version}`
    const hit = urls.get(key)
    if (hit) return hit
    if (!settled.has(key)) {
      settled.add(key)
      apiBlob(`/admin/managed-users/${id}/photo`, { v: version })
        .then(({ blob }) => { urls.set(key, URL.createObjectURL(blob)) })
        .catch(() => { /* sem foto: a interface mostra as iniciais */ })
    }
    return null
  }

  onBeforeUnmount(() => {
    urls.forEach((u) => URL.revokeObjectURL(u))
    urls.clear()
  })

  return { get }
}
