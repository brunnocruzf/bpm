import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '@/api/http'
import TaskView from './TaskView.vue'

const notify = vi.hoisted(() => ({ error: vi.fn(), success: vi.fn(), info: vi.fn() }))
vi.mock('@/composables/notify', () => ({ useNotify: () => notify }))
vi.mock('@/stores/session', () => ({ useSession: () => ({ me: { id: 'u1' }, refreshCounters: vi.fn() }) }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn() }) }))
vi.mock('@/components/forms/FormRenderer.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/api/http', async (importOriginal) => ({
  ...await importOriginal<typeof import('@/api/http')>(),
  api: vi.fn(),
}))

const task = (overrides = {}) => ({
  id: 't1', name: 'Aprovar', stageKey: 'aprovar', status: 'claimed', candidateGroup: null, candidateGroupName: null,
  assignee: { id: 'u1', name: 'Gestor' }, completedBy: null, dueAt: null, createdAt: '', completedAt: null, ageHours: 0, overdue: false,
  rowVersion: 1, canWork: true, canClaim: false, canRelease: false, canReassign: false, form: null, data: {}, draft: null, submission: null,
  actionPlan: null, attachments: [], timeline: [], description: null, instance: { id: 'i1', number: 'OCP-1', flowKey: 'f', flowName: 'FOR-784', requesterName: 'Ana', requesterArea: null, startedAt: '', status: 'running' },
  ...overrides,
})

async function render(overrides = {}) {
  vi.mocked(api).mockResolvedValue(task(overrides) as never)
  const wrapper = mount(TaskView, {
    props: { id: 't1' },
    global: {
      stubs: { RouterLink: RouterLinkStub, ActionPlanPanel: true, TimelineList: true, AttachmentList: true, StatusPill: true, ReassignDialog: { props: ['visible'], template: '<div class="reassign" :data-open="visible" />' } },
    },
  })
  await flushPromises()
  return wrapper
}
const buttons = (w: Awaited<ReturnType<typeof render>>) => w.findAll('.tm-heading button').map((b) => b.text())

describe('ações da tarefa', () => {
  beforeEach(() => vi.clearAllMocks())

  it('tarefa atribuída diretamente não oferece devolver ao grupo', async () => {
    const wrapper = await render()

    expect(buttons(wrapper)).not.toContain('Devolver ao grupo')
    expect(wrapper.text()).toContain('responsável direto')
  })

  it('tarefa assumida de um grupo pode voltar ao grupo', async () => {
    const wrapper = await render({ candidateGroup: 'CQ', candidateGroupName: 'CQ / Recebimento', canRelease: true })

    expect(buttons(wrapper)).toContain('Devolver ao grupo')
    expect(wrapper.text()).toContain('grupo CQ / Recebimento')
  })

  it('reatribuir só aparece com a permissão e abre o diálogo', async () => {
    expect(buttons(await render())).not.toContain('Reatribuir')

    const wrapper = await render({ canReassign: true })
    await wrapper.findAll('.tm-heading button').find((b) => b.text() === 'Reatribuir')!.trigger('click')

    expect(wrapper.find('.reassign').attributes('data-open')).toBe('true')
  })
})
