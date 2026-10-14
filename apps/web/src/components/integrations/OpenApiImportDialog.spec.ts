import { flushPromises, mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import { afterEach, describe, expect, it, vi } from 'vitest'
import OpenApiImportDialog from './OpenApiImportDialog.vue'
import type { Connection } from './types'

const connection = { id: 'c1', name: 'API Dynamics AX 2012', baseUrl: 'https://apidax.tmsa.ind.br', openapiUrl: 'https://apidax.tmsa.ind.br/swagger/v1/swagger.json' } as Connection
const op = (method: string, path: string, extra: Record<string, unknown> = {}) => ({
  method, path, tag: 'Fornecedores', name: `${method} ${path}`, description: null, operationId: null, suggestedKey: path.replace(/\W+/g, '.').slice(1),
  params: [], bodyFields: [], responseFields: [], responseIsList: false, flags: [], warnings: [], status: 'new', endpointId: null, enabled: false, ...extra,
})

describe('OpenApiImportDialog', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('pré-seleciona só leituras novas/alteradas e envia a seleção para importar', async () => {
    const calls: { url: string; body: any }[] = []
    vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit) => {
      calls.push({ url: String(url), body: init?.body ? JSON.parse(String(init.body)) : null })
      if (String(url).includes('/preview')) {
        return new Response(JSON.stringify({
          previewId: 'p1', bytes: 2048, sameAsImported: false,
          meta: { title: 'Documentação API - Dynamics AX 2012 v1', version: 'v1', openapi: '3.0.1', operations: 4, reads: 2, writes: 2, security: [{ name: 'Bearer', type: 'apiKey', in: 'header', header: 'Authorization' }], servers: [], tags: ['Fornecedores'] },
          operations: [
            op('GET', '/v1/Fornecedores/{CPF_CNPJ}'),
            op('GET', '/v1/Fornecedores/Todos/{filter}', { status: 'unchanged' }),
            op('POST', '/v1/Fornecedores/CriarFornecedor'),
            op('POST', '/Access', { flags: ['credential_in_query'] }),
          ],
          removed: [], warnings: ['4 de 4 operação(ões) não documentam a resposta'],
        }), { status: 200 })
      }
      return new Response(JSON.stringify({ created: 1, updated: 0, unchanged: 0, removed: 0 }), { status: 200 })
    }))
    const w = mount(OpenApiImportDialog, { props: { connection }, global: { plugins: [PrimeVue, ToastService], stubs: { Dialog: { template: '<div><slot /><slot name="footer" /></div>' } } } })
    await w.findAll('button').find((b) => b.text().includes('Gerar prévia'))!.trigger('click')
    await flushPromises()
    expect(calls[0].url).toContain('/admin/integrations/connections/c1/openapi/preview')
    expect(calls[0].body).toEqual({ url: 'https://apidax.tmsa.ind.br/swagger/v1/swagger.json' })
    expect(w.text()).toContain('não documentam a resposta')
    expect(w.text()).toContain('1 selecionada(s)')

    await w.findAll('button').find((b) => b.text().includes('Importar selecionadas'))!.trigger('click')
    await flushPromises()
    expect(calls[1].url).toContain('/openapi/apply')
    expect(calls[1].body.operations).toEqual(['GET /v1/Fornecedores/{CPF_CNPJ}'])
    expect(w.emitted('imported')).toBeTruthy()
  })
})
