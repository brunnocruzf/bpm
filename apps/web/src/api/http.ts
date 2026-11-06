import type { Problem } from './types'
import { auth } from '@/auth'

const BASE = (import.meta.env.VITE_API_BASE_URL || '/api/v1').replace(/\/$/, '')

/** Erro da API no formato RFC 7807 (problem details). */
export class ApiError extends Error {
  readonly problem: Problem
  constructor(problem: Problem) {
    super(problem.detail || problem.title)
    this.problem = problem
  }
  get status() { return this.problem.status }
  get code() { return this.problem.code }
  get errors() { return this.problem.errors ?? {} }
}

export interface RequestOptions {
  method?: string
  body?: unknown
  query?: Record<string, string | number | boolean | null | undefined>
  idempotencyKey?: string
  ifMatch?: number
  raw?: boolean
}

let onUnauthorized: (() => void) | null = null
export function setUnauthorizedHandler(fn: () => void) { onUnauthorized = fn }

let onMaintenance: (() => void) | null = null
/** Restauração em andamento (503 maintenance) ou aguardando revisão (503 safe_mode). */
export function setMaintenanceHandler(fn: () => void) { onMaintenance = fn }

export function newId(): string {
  return globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`
}

export async function api<T = any>(path: string, opts: RequestOptions = {}): Promise<T> {
  const url = new URL(BASE + path, window.location.origin)
  for (const [k, v] of Object.entries(opts.query ?? {})) {
    if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v))
  }
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Correlation-Id': newId() }
  const token = await auth.getToken()
  if (token) headers.Authorization = `Bearer ${token}`
  const csrf = document.cookie.split(';').map((part) => part.trim()).find((part) => part.startsWith('bpm_local_csrf='))?.slice('bpm_local_csrf='.length)
  if (csrf) headers['X-BPM-CSRF'] = decodeURIComponent(csrf)
  if (opts.idempotencyKey) headers['Idempotency-Key'] = opts.idempotencyKey
  if (opts.ifMatch !== undefined) headers['If-Match'] = String(opts.ifMatch)

  let body: BodyInit | undefined
  if (opts.body instanceof FormData) {
    body = opts.body
  } else if (opts.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify(opts.body)
  }

  let response: Response
  try {
    response = await fetch(url, { method: opts.method ?? (body ? 'POST' : 'GET'), headers, body, credentials: 'same-origin' })
  } catch {
    throw new ApiError({ title: 'Sem conexão', status: 0, detail: 'Não foi possível contactar o servidor. Verifique sua conexão.', code: 'network_error' })
  }

  if (response.status === 401) {
    onUnauthorized?.()
  }
  if (!response.ok) {
    let problem: Problem
    try {
      problem = await response.json()
    } catch {
      problem = { title: 'Erro', status: response.status, detail: response.statusText }
    }
    problem.status ??= response.status
    if (response.status === 503 && (problem.code === 'maintenance' || problem.code === 'safe_mode')) onMaintenance?.()
    throw new ApiError(problem)
  }
  if (response.status === 204) return null as T
  if (opts.raw) return (await response.text()) as T
  return (await response.json()) as T
}

/**
 * Download autenticado (relatórios, auditoria, imagens de rascunho): o arquivo nunca tem URL pública;
 * o Laravel revalida a autorização e audita. Retorna Blob para pré-visualização/impressão local.
 */
export async function apiBlob(path: string, query: RequestOptions['query'] = {}): Promise<{ blob: Blob; fileName: string | null }> {
  const url = new URL(BASE + path, window.location.origin)
  for (const [k, v] of Object.entries(query ?? {})) {
    if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v))
  }
  const headers: Record<string, string> = { 'X-Correlation-Id': newId() }
  const token = await auth.getToken()
  if (token) headers.Authorization = `Bearer ${token}`
  const response = await fetch(url, { headers })
  if (response.status === 401) onUnauthorized?.()
  if (!response.ok) {
    let problem: Problem
    try {
      problem = await response.json()
    } catch {
      problem = { title: 'Erro', status: response.status, detail: response.statusText }
    }
    problem.status ??= response.status
    throw new ApiError(problem)
  }
  const disposition = response.headers.get('Content-Disposition') ?? ''
  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)
  return { blob: await response.blob(), fileName: match ? decodeURIComponent(match[1]) : null }
}

/** Salva um Blob como arquivo (download local). */
export function saveBlob(blob: Blob, fileName: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = fileName
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 30000)
}

/** Mensagem amigável a partir de qualquer erro. */
export function errorMessage(e: unknown): string {
  if (e instanceof ApiError) {
    const first = Object.values(e.errors)[0]?.[0]
    return first && e.status === 422 && Object.keys(e.errors).length === 1 ? first : (e.problem.detail || e.problem.title)
  }
  return e instanceof Error ? e.message : 'Erro inesperado.'
}
