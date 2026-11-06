import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api, ApiError, saveBlob } from '@/api/http'
import RecoveryKeyDialog from './RecoveryKeyDialog.vue'

const notify = vi.hoisted(() => ({ error: vi.fn(), success: vi.fn() }))
vi.mock('@/composables/notify', () => ({ useNotify: () => notify }))
vi.mock('@/api/http', async (importOriginal) => ({
  ...await importOriginal<typeof import('@/api/http')>(),
  api: vi.fn(),
  saveBlob: vi.fn(),
}))

const KEY = 'AGE-SECRET-KEY-1ABC'

async function render() {
  const wrapper = mount(RecoveryKeyDialog, {
    props: { visible: true, organization: 'TMSA', destination: 'conta/anexos/backups' },
    global: { stubs: { Dialog: { props: ['visible', 'header'], template: '<section v-if="visible" role="dialog"><h2>{{ header }}</h2><slot /></section>' } } },
  })
  await flushPromises()
  return wrapper
}

describe('chave de recuperação', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(api).mockImplementation(async (path: string) => (path === '/admin/backups/recovery-key' ? { privateKey: KEY, fingerprint: 'age1…x7m2' } : {}) as never)
  })

  it('mostra a chave uma vez e avisa que perder a chave perde os backups', async () => {
    const wrapper = await render()
    expect((wrapper.find('#k-key').element as HTMLTextAreaElement).value).toBe(KEY)
    expect(wrapper.text()).toContain('única vez')
    expect(wrapper.text()).toContain('nenhum backup criado com ela poderá ser restaurado')
  })

  it('exige colar a chave para confirmar que ela foi guardada', async () => {
    const wrapper = await render()
    const submit = wrapper.find('button[type="submit"]')
    expect(submit.attributes('disabled')).toBeDefined()

    await wrapper.find('#k-confirm').setValue(KEY)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api).toHaveBeenCalledWith('/admin/backups/recovery-key/confirm', { method: 'POST', body: { recoveryKey: KEY } })
    expect(wrapper.emitted('done')).toHaveLength(1)
  })

  it('o kit de recuperação traz a chave e o destino', async () => {
    const wrapper = await render()
    await wrapper.findAll('button').find((b) => b.text().includes('Baixar kit'))!.trigger('click')

    const blob = vi.mocked(saveBlob).mock.calls[0][0]
    const text = await new Promise<string>((resolve) => {
      const reader = new FileReader()
      reader.onload = () => resolve(String(reader.result))
      reader.readAsText(blob)
    })
    expect(text).toContain(KEY)
    expect(text).toContain('conta/anexos/backups')
  })

  it('mostra a recusa do servidor no campo', async () => {
    const wrapper = await render()
    vi.mocked(api).mockRejectedValueOnce(new ApiError({ status: 422, title: 'Inválido', errors: { recoveryKey: ['Chave de recuperação inválida.'] } }))
    await wrapper.find('#k-confirm').setValue('errada')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Chave de recuperação inválida.')
    expect(wrapper.emitted('done')).toBeUndefined()
  })
})
