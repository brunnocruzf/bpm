import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api, ApiError } from '@/api/http'
import ReassignDialog from './ReassignDialog.vue'

const notify = vi.hoisted(() => ({ error: vi.fn(), success: vi.fn() }))
vi.mock('@/composables/notify', () => ({ useNotify: () => notify }))
vi.mock('@/api/http', async (importOriginal) => ({
  ...await importOriginal<typeof import('@/api/http')>(),
  api: vi.fn(),
}))

const user = (id: string, overrides = {}) => ({ id, name: id, area: null, roles: ['Bpm.TaskWorker'], groups: [], is_active: true, ...overrides })
const users = [
  user('atual'),
  user('ana'),
  user('inativo', { is_active: false }),
  user('sem-papel', { roles: ['Bpm.Initiator'] }),
  user('do-grupo', { groups: ['CQ'] }),
]

async function render(candidateGroup: string | null = null) {
  const wrapper = mount(ReassignDialog, {
    props: { visible: true, task: { id: 't1', candidateGroup, assignee: { id: 'atual', name: 'Atual' } } },
    global: { stubs: { Dialog: { props: ['visible', 'header'], template: '<section v-if="visible" role="dialog"><h2>{{ header }}</h2><slot /></section>' } } },
  })
  await flushPromises()
  return wrapper
}
const optionValues = (w: Awaited<ReturnType<typeof render>>) => w.findAll('#re-user option').map((o) => o.attributes('value')).filter(Boolean)

describe('reatribuição de tarefa', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(api).mockImplementation(async (path: string) => (path === '/admin/users' ? { data: users } : {}) as never)
  })

  it('sem grupo, lista executores ativos e esconde o responsável atual', async () => {
    expect(optionValues(await render())).toEqual(['ana', 'do-grupo'])
  })

  it('com grupo, lista apenas os membros do grupo', async () => {
    expect(optionValues(await render('CQ'))).toEqual(['do-grupo'])
  })

  it('exige justificativa e envia ao servidor', async () => {
    const wrapper = await render()
    await wrapper.find('#re-user').setValue('ana')
    await wrapper.find('#re-why').setValue('curta')
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()

    await wrapper.find('#re-why').setValue('Gestor em férias até sexta')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api).toHaveBeenCalledWith('/tasks/t1/reassign', { method: 'POST', body: { userId: 'ana', justification: 'Gestor em férias até sexta' } })
    expect(wrapper.emitted('done')).toHaveLength(1)
    expect(wrapper.emitted('update:visible')).toEqual([[false]])
  })

  it('mostra a recusa do servidor no campo', async () => {
    const wrapper = await render()
    vi.mocked(api).mockRejectedValueOnce(new ApiError({ status: 422, title: 'Inválido', errors: { userId: ['Não é possível reatribuir: o solicitante não pode ser o responsável por esta etapa.'] } }))
    await wrapper.find('#re-user').setValue('ana')
    await wrapper.find('#re-why').setValue('Gestor em férias até sexta')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('o solicitante não pode ser o responsável')
    expect(wrapper.emitted('done')).toBeUndefined()
  })
})
