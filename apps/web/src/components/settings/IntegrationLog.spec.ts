import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import IntegrationLog from './IntegrationLog.vue'

const entry = {
  id: 'msg:1', at: '2026-10-13T12:00:00Z', kind: 'delivery', subsystem: 'email', level: 'error', title: 'Aviso de etapa concluída — falhou (dead-letter)',
  summary: 'Para ca•••@tmsa.example · “IRR-000001/26”', actor: 'worker de notificações', code: 'auth_failed',
  explain: { title: 'Autenticação recusada', cause: 'Usuário/senha inválidos.', action: 'Confira a credencial.' },
  durationMs: null, correlationId: 'corr-123',
  steps: [{ name: 'Tentativa 1 — falhou — sem novas tentativas', ok: false, level: 'error', code: 'auth_failed', message: 'Servidor SMTP recusou a autenticação.', durationMs: 120, detail: 'SMTP personalizado' }],
  facts: [{ label: 'Tentativas', value: '1 de 6' }],
}

function mockApi() {
  const fetch = vi.fn(async (_url: string) => new Response(JSON.stringify({ data: [entry], next: null, stats: { since: '', testsOk: 2, testsFailed: 1, deliveries: { sent: 5, failed: 1 } } }), { status: 200 }))
  vi.stubGlobal('fetch', fetch)
  return fetch
}

describe('IntegrationLog', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('lista registros com código, resumo e estatísticas e expande a explicação e as tentativas', async () => {
    const fetch = mockApi()
    const w = mount(IntegrationLog, {
      props: { area: 'email', title: 'Log de e-mail', kindLabels: { test: 'Testes', delivery: 'Entregas', config: 'Configuração' } },
      global: { stubs: { RouterLink: true } },
    })
    await flushPromises()
    expect(String(fetch.mock.calls[0][0])).toContain('/admin/settings/logs/email')
    expect(String(fetch.mock.calls[0][0])).toContain('kind=test%2Cdelivery%2Cconfig')
    expect(w.text()).toContain('auth_failed')
    expect(w.text()).toContain('Entregas: 5 enviada(s) · 1 falha(s)')
    expect(w.text()).not.toContain('O que fazer')

    await w.get('button.row').trigger('click')
    expect(w.text()).toContain('Autenticação recusada')
    expect(w.text()).toContain('O que fazer: Confira a credencial.')
    expect(w.text()).toContain('Tentativa 1 — falhou')
    expect(w.text()).toContain('120 ms')
    expect(w.text()).toContain('corr-123')
  })

  it('filtros recarregam com os parâmetros escolhidos', async () => {
    const fetch = mockApi()
    const w = mount(IntegrationLog, {
      props: { area: 'entra', title: 'Log do Entra', kindLabels: { sync: 'Importações', access: 'Logins' }, subsystem: 'directory', showSubsystem: true },
      global: { stubs: { RouterLink: true } },
    })
    await flushPromises()
    expect(String(fetch.mock.calls[0][0])).toContain('subsystem=directory')
    await w.get('input[type=checkbox]').setValue(true)
    await flushPromises()
    expect(String(fetch.mock.calls.at(-1)?.[0])).toContain('failures=1')
    await w.findAll('button.chip')[1].trigger('click')
    await flushPromises()
    expect(String(fetch.mock.calls.at(-1)?.[0])).toContain('kind=sync&')
  })
})
