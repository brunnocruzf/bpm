import { describe, expect, it, vi } from 'vitest'

vi.mock('@/auth', () => ({ auth: { getToken: async () => 'dev:cq' } }))

import { api, ApiError, errorMessage } from './http'
import { fmtAge, fmtSize, INSTANCE_STATUS } from '@/composables/format'

describe('api client', () => {
  it('envia bearer, correlation id e idempotency key', async () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ ok: true }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
    vi.stubGlobal('fetch', fetchMock)
    await api('/tasks/1/complete', { method: 'POST', body: { data: {} }, idempotencyKey: 'key-12345678' })
    const [, init] = fetchMock.mock.calls[0] as unknown as [URL, RequestInit]
    const headers = init.headers as Record<string, string>
    expect(headers.Authorization).toBe('Bearer dev:cq')
    expect(headers['Idempotency-Key']).toBe('key-12345678')
    expect(headers['X-Correlation-Id']).toBeTruthy()
    expect(headers['Content-Type']).toBe('application/json')
  })

  it('converte problem+json em ApiError com mensagem amigável', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({
      title: 'Dados inválidos', status: 422, code: 'validation_failed', detail: 'Verifique os campos destacados.',
      errors: { prazo: ['Preencha "Data prazo (07)".'] },
    }), { status: 422 })))
    const error = await api('/x').catch((e) => e)
    expect(error).toBeInstanceOf(ApiError)
    expect(error.code).toBe('validation_failed')
    expect(errorMessage(error)).toBe('Preencha "Data prazo (07)".')
  })

  it('falha de rede vira erro com status 0 (permite reenviar com a mesma chave)', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('Failed to fetch') }))
    const error = await api('/x').catch((e) => e)
    expect(error.status).toBe(0)
  })
})

describe('format', () => {
  it('formata idade, tamanho e rótulos de status', () => {
    expect(fmtAge(0)).toBe('agora')
    expect(fmtAge(5)).toBe('5 h')
    expect(fmtAge(49)).toBe('2 d')
    expect(fmtSize(2048)).toBe('2 KB')
    expect(INSTANCE_STATUS.starting.label).toBe('Processando')
  })
})
