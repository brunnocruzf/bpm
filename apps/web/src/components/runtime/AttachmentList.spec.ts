import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api, saveBlob } from '@/api/http'
import type { AttachmentInfo } from '@/api/types'
import AttachmentList from './AttachmentList.vue'

const notify = vi.hoisted(() => ({ error: vi.fn(), info: vi.fn() }))
vi.mock('@/composables/notify', () => ({ useNotify: () => notify }))
vi.mock('@/api/http', async (importOriginal) => ({
  ...await importOriginal<typeof import('@/api/http')>(),
  api: vi.fn(),
  saveBlob: vi.fn(),
}))

const attachment = (name: string, type: string): AttachmentInfo => ({
  id: 'file-1', name, type, size: 123, fieldKey: null, avStatus: 'clean',
  taskId: null, supplierActionId: null, uploadedAt: '',
})

function render(a: AttachmentInfo) {
  return mount(AttachmentList, {
    props: { items: [a] },
    global: {
      directives: { tooltip: () => {} },
      stubs: { Dialog: { props: ['visible', 'header'], template: '<section v-if="visible" role="dialog"><h2>{{ header }}</h2><slot /></section>' } },
    },
  })
}

describe('abertura de anexos', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(api).mockResolvedValue({ url: '/api/v1/files/file-1?signature=test' })
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(new Blob(['arquivo']))))
    vi.stubGlobal('URL', Object.assign(class extends URL {}, {
      createObjectURL: vi.fn(() => 'blob:preview'),
      revokeObjectURL: vi.fn(),
    }))
  })

  afterEach(() => vi.unstubAllGlobals())

  it('identifica PNG pela extensão com MIME genérico e abre dialog com download, sem nova janela', async () => {
    const w = render(attachment('Designer (1).PNG', 'application/octet-stream'))
    await w.get('.tm-link-btn').trigger('click')
    await flushPromises()
    expect(w.get('[role="dialog"]').text()).toContain('Designer (1).PNG')
    expect(w.get('img').attributes('src')).toBe('blob:preview')
    expect(fetch).toHaveBeenCalledWith('/api/v1/files/file-1?signature=test', expect.objectContaining({ signal: expect.any(AbortSignal) }))
    expect(saveBlob).not.toHaveBeenCalled()
    await w.get('.tm-btn').trigger('click')
    expect(saveBlob).toHaveBeenCalledWith(expect.any(Blob), 'Designer (1).PNG')
    w.unmount()
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:preview')
  })

  it('exibe PDF no visualizador local e não embute a rota protegida por X-Frame-Options', async () => {
    const w = render(attachment('laudo.pdf', 'application/pdf'))
    await w.get('.tm-link-btn').trigger('click')
    await flushPromises()
    expect(w.get('iframe').attributes('src')).toBe('blob:preview')
    expect(saveBlob).not.toHaveBeenCalled()
    w.unmount()
  })

  it('faz download automático de Word sem visualizador, preservando o nome', async () => {
    const w = render(attachment('relatório.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'))
    await w.get('.tm-link-btn').trigger('click')
    await flushPromises()
    expect(saveBlob).toHaveBeenCalledWith(expect.any(Blob), 'relatório.docx')
    expect(w.find('[role="dialog"]').exists()).toBe(false)
    w.unmount()
  })

  it('faz download se o navegador não consegue decodificar a imagem', async () => {
    const w = render(attachment('foto.jpg', 'image/jpeg'))
    await w.get('.tm-link-btn').trigger('click')
    await flushPromises()
    await w.get('img').trigger('error')
    expect(saveBlob).toHaveBeenCalledWith(expect.any(Blob), 'foto.jpg')
    expect(notify.info).toHaveBeenCalled()
    w.unmount()
  })

  it('mostra falha do armazenamento sem baixar uma resposta de erro como arquivo', async () => {
    vi.mocked(fetch).mockResolvedValue(new Response(JSON.stringify({ title: 'Armazenamento indisponível', status: 503 }), {
      status: 503, headers: { 'Content-Type': 'application/problem+json' },
    }))
    const w = render(attachment('foto.png', 'image/png'))
    await w.get('.tm-link-btn').trigger('click')
    await flushPromises()
    expect(notify.error).toHaveBeenCalledWith(expect.objectContaining({ message: 'Armazenamento indisponível' }))
    expect(saveBlob).not.toHaveBeenCalled()
    w.unmount()
  })
})
