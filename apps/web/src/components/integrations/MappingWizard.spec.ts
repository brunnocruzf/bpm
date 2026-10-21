import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import { afterEach, describe, expect, it, vi } from 'vitest'
import MappingWizard from './MappingWizard.vue'
import type { EndpointRow } from './types'

const connection = { id: 'c1', key: 'apidax', name: 'API Dynamics AX 2012', system: 'AX2012', enabled: true, allowWrites: false, environment: 'homologacao' }
const row = (id: string, method: string, path: string, name: string) =>
  ({ id, key: id, name, tag: 'Fornecedores', method, path, enabled: true, kind: method === 'GET' ? 'read' : 'write', source: 'swagger', contractStatus: 'ok', version: 1, connection,
    lookupExposed: false, boundTo: null, hasResponseFields: true, paramsCount: 1, warningsCount: 0, updatedAt: null }) as EndpointRow
const endpoints = [row('e1', 'GET', '/v1/Fornecedores/{CPF_CNPJ}', 'Lista um Fornecedor'), row('e2', 'POST', '/v1/Financeiro/CriarOrdemTransferencia', 'Criar OT')]

describe('MappingWizard', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('cria uma consulta: sugere o identificador, só aceita GET e envia entrada/saída escolhidas', async () => {
    const calls: { url: string; method: string; body: any }[] = []
    vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit) => {
      calls.push({ url: String(url), method: init?.method ?? 'GET', body: init?.body ? JSON.parse(String(init.body)) : null })
      if (String(url).includes('/admin/integrations/endpoints/e1')) {
        return new Response(JSON.stringify({
          ...endpoints[0], description: null, operationId: 'ListaFornCNPJ', url: 'https://apidax.tmsa.ind.br/v1/Fornecedores/{CPF_CNPJ}',
          params: [{ name: 'CPF_CNPJ', in: 'path', type: 'string', required: true, mode: 'input', constant: null, description: null }], bodyFields: [],
          responseFields: [{ path: 'nome', label: 'Nome', type: 'string', sensitive: false }, { path: 'contato.email', label: 'E-mail', type: 'string', sensitive: true }],
          responseSample: null, responseIsList: true, resultPath: null, lookup: { expose: false }, timeoutSeconds: null, warnings: [], usedInForms: 0, lookupSourceKey: 'api:e1', bindings: [],
        }), { status: 200 })
      }
      return new Response(JSON.stringify(String(url).includes('/bindings') ? { data: [] } : {}), { status: String(url).includes('/bindings') ? 201 : 200 })
    }))
    const w = mount(MappingWizard, { props: { op: null, endpoints }, global: { plugins: [PrimeVue, ToastService, createPinia()], stubs: { Dialog: { template: '<div><slot /><slot name="footer" /></div>' } } } })
    const step = (name: string) => w.findAll('button.step').find((b) => b.text().includes(name))!.trigger('click')

    await w.find('#m-label').setValue('Fornecedor por CNPJ')
    expect((w.find('#m-key').element as HTMLInputElement).value).toBe('fornecedorPorCnpj')

    await step('Endpoint')
    const options = w.findAll('#m-ep option').map((o) => o.text())
    expect(options.some((t) => t.startsWith('GET /v1/Fornecedores'))).toBe(true)
    expect(options.some((t) => t.startsWith('POST')), 'consulta só lista endpoints de leitura').toBe(false)
    await w.find('#m-ep').setValue('e1')
    await flushPromises()

    await step('Entrada')
    await w.find('select[aria-label="Origem de CPF_CNPJ"]').setValue('value')

    await step('Saída')
    expect((w.find('input[aria-label="Chave de contato.email"]').element as HTMLInputElement).value).toBe('contatoEmail')
    await w.find('input[aria-label="Usar nome"]').setValue(true)
    await w.find('input[aria-label="Chave de nome"]').setValue('nomeFornecedor')

    await step('Resumo')
    await w.find('.activate input').setValue(true)
    await w.findAll('button').find((b) => b.text().includes('Salvar'))!.trigger('click')
    await flushPromises()

    const post = calls.find((c) => c.url.endsWith('/admin/integrations/bindings') && c.method === 'POST')
    expect(post?.body).toEqual({
      key: 'fornecedorPorCnpj', kind: 'lookup', label: 'Fornecedor por CNPJ', description: null, endpointId: 'e1', enabled: true,
      inputMap: { CPF_CNPJ: 'input:value' }, outputs: [{ key: 'nomeFornecedor', label: 'Nome', path: 'nome' }],
    })
    expect(w.emitted('saved')).toBeTruthy()
  })
})
