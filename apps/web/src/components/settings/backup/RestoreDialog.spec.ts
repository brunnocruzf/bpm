import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api, ApiError } from '@/api/http'
import type { BackupEntry } from '@/api/types'
import RestoreDialog from './RestoreDialog.vue'

const notify = vi.hoisted(() => ({ error: vi.fn(), success: vi.fn() }))
vi.mock('@/composables/notify', () => ({ useNotify: () => notify }))
vi.mock('@/api/http', async (importOriginal) => ({
  ...await importOriginal<typeof import('@/api/http')>(),
  api: vi.fn(),
}))

const entry: BackupEntry = {
  id: '20261101T060000Z-abcd1234', scope: 'data', origin: 'schedule', scheduleId: null, bytes: 1024, inLocal: true, inAzure: true,
  keyFingerprint: 'age1q9v…x7m2', backupAt: '2026-11-01T06:00:00Z', verifiedAt: null, verification: null, keepUntil: null,
}

function render(override: Partial<BackupEntry> = {}) {
  return mount(RestoreDialog, {
    props: { visible: true, entry: { ...entry, ...override }, organization: 'TMSA' },
    global: { stubs: { Dialog: { props: ['visible', 'header'], template: '<section v-if="visible" role="dialog"><h2>{{ header }}</h2><slot /></section>' } } },
  })
}
const submit = (w: ReturnType<typeof render>) => w.find('button[type="submit"]')

async function fill(w: ReturnType<typeof render>, values: { org?: string; why?: string; key?: string } = {}) {
  await w.find('#r-org').setValue(values.org ?? 'tmsa')
  await w.find('#r-why').setValue(values.why ?? 'Banco corrompido na migração')
  await w.find('#r-key').setValue(values.key ?? 'AGE-SECRET-KEY-1ABC')
}

describe('restaurar backup', () => {
  beforeEach(() => vi.clearAllMocks())

  it('mostra a data e o que será perdido antes de pedir qualquer coisa', () => {
    const text = render().text()
    expect(text).toContain('será substituído')
    expect(text).toContain('só administradores entram')
    expect(text).toContain('age1q9v…x7m2')
    expect(text).toContain('Bancos e arquivos da plataforma')
  })

  it('backup só de banco não promete restaurar arquivos', () => {
    expect(render({ scope: 'database' }).text()).not.toContain('arquivos da plataforma')
  })

  it('só libera com a organização certa, justificativa e chave', async () => {
    const wrapper = render()
    expect(submit(wrapper).attributes('disabled')).toBeDefined()
    await fill(wrapper, { org: 'outra' })
    expect(submit(wrapper).attributes('disabled')).toBeDefined()
    await fill(wrapper, { why: 'curto' })
    expect(submit(wrapper).attributes('disabled')).toBeDefined()
    await fill(wrapper, { key: ' ' })
    expect(submit(wrapper).attributes('disabled')).toBeDefined()
    await fill(wrapper)
    expect(submit(wrapper).attributes('disabled')).toBeUndefined()
  })

  it('envia ao servidor e apaga a chave do campo', async () => {
    vi.mocked(api).mockResolvedValue({} as never)
    const wrapper = render()
    await fill(wrapper)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api).toHaveBeenCalledWith(`/admin/backups/${entry.id}/restore`, { method: 'POST', body: { confirmation: 'tmsa', justification: 'Banco corrompido na migração', recoveryKey: 'AGE-SECRET-KEY-1ABC' } })
    expect((wrapper.find('#r-key').element as HTMLInputElement).value).toBe('')
    expect(wrapper.emitted('done')).toHaveLength(1)
  })

  it('mostra no campo a recusa do servidor quando a chave não abre o backup', async () => {
    vi.mocked(api).mockRejectedValueOnce(new ApiError({ status: 422, title: 'Inválido', errors: { recoveryKey: ['Esta chave não abre este backup.'] } }))
    const wrapper = render()
    await fill(wrapper)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Esta chave não abre este backup.')
    expect(wrapper.emitted('done')).toBeUndefined()
  })
})
