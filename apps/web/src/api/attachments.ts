import { api, ApiError } from './http'
import type { AttachmentInfo, Problem } from './types'

const previewTypes: Record<string, string> = {
  pdf: 'application/pdf',
  png: 'image/png',
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  gif: 'image/gif',
  webp: 'image/webp',
  bmp: 'image/bmp',
  avif: 'image/avif',
}

export function attachmentPreviewType(a: Pick<AttachmentInfo, 'name' | 'type'>): string | null {
  const type = a.type.split(';')[0]!.trim().toLowerCase()
  if (Object.values(previewTypes).includes(type)) return type
  if (type && type !== 'application/octet-stream') return null
  return previewTypes[a.name.split('.').pop()?.toLowerCase() ?? ''] ?? null
}

export async function attachmentBlob(id: string, signal: AbortSignal): Promise<Blob> {
  const { url } = await api<{ url: string }>(`/attachments/${id}/url`, { query: { disposition: 'attachment' } })
  const response = await fetch(url, { signal, credentials: 'same-origin' })
  if (!response.ok) {
    const problem: Problem = response.headers.get('Content-Type')?.includes('json')
      ? await response.json()
      : { title: 'Não foi possível carregar o anexo.', status: response.status }
    throw new ApiError(problem)
  }
  return response.blob()
}
