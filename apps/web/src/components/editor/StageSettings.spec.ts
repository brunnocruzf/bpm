import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import type { Stage, UserFieldInfo } from '@/api/types'
import StageSettings from './StageSettings.vue'

vi.mock('@/stores/session', () => ({
  useSession: () => ({ meta: { groups: [{ key: 'PONTO_GESTOR', name: 'Gestor — Ponto' }], serviceOperations: [], endResults: [] } }),
}))

const stage = (config: Record<string, unknown> = {}): Stage => ({
  id: 's1', key: 'aprovar', elementId: 'aprovar', name: 'Aprovar', type: 'user_task', lane: 'PONTO_GESTOR', position: 1, description: null,
  config, form: null, incomingCount: 1, outgoing: [],
})
const userFields: UserFieldInfo[] = [
  { key: 'gestorImediatoId', label: 'Matrícula → Gestor', stages: ['abrir'] },
  { key: 'gestorSubstitutoId', label: 'Substituto → Colaborador', stages: ['abrir'] },
]

function render(config: Record<string, unknown> = {}, props: Record<string, unknown> = {}) {
  return mount(StageSettings, {
    props: { stage: stage(config), stages: [], fields: [], userFields, editable: true, isOpening: false, ...props },
    global: { stubs: { MultiSelect: true, RoutingEditor: true, StageNotification: true } },
  })
}
const lastConfig = (w: ReturnType<typeof render>) => (w.emitted('update')!.at(-1)![0] as { config: Record<string, any> }).config

describe('Quem executa?', () => {
  it('oferece os quatro modos e mantém a raia como grupo no modo grupo', () => {
    const wrapper = render()

    expect(wrapper.findAll('#st-who option').map((o) => o.text())).toEqual(['Grupo', 'Campo do formulário', 'Solicitante', 'Gestor do solicitante'])
    expect(wrapper.find('label[for="st-lane"]').text()).toContain('Grupo responsável')
    expect(wrapper.find('#st-who-fallback').exists()).toBe(false)
  })

  it('fora do modo grupo separa a raia e pede o grupo de contingência', async () => {
    const wrapper = render()
    await wrapper.find('#st-who').setValue('field')

    expect(lastConfig(wrapper).assignment).toEqual({ mode: 'field', fallbackGroup: 'PONTO_GESTOR' })
    expect(wrapper.find('label[for="st-lane"]').text()).toContain('Raia no diagrama')
    expect(wrapper.find('#st-who-fallback').exists()).toBe(true)
  })

  it('o modo solicitante não tem a opção de excluir o solicitante', async () => {
    const wrapper = render()
    await wrapper.find('#st-who').setValue('requester')

    expect(wrapper.find('.who-check').exists()).toBe(false)
  })

  it('ordena os campos e preserva o que não tem controle na tela', async () => {
    const rule = { mode: 'field', fields: ['gestorImediatoId', 'gestorSubstitutoId'], fallbackGroup: 'PONTO_GESTOR', excludeFields: ['colaboradorUserId'], requiredUnless: { solicitanteGestor: 'sim' } }
    const wrapper = render({ assignment: rule })

    await wrapper.findAll('button[aria-label="Subir"]')[1].trigger('click')

    expect(lastConfig(wrapper).assignment).toEqual({ ...rule, fields: ['gestorSubstitutoId', 'gestorImediatoId'] })
  })

  it('adiciona só campos ainda não usados', async () => {
    const wrapper = render({ assignment: { mode: 'field', fields: ['gestorImediatoId'] } })

    expect(wrapper.findAll('#st-who-field option').map((o) => o.attributes('value'))).toEqual(['', 'gestorSubstitutoId'])
    await wrapper.find('#st-who-field').setValue('gestorSubstitutoId')
    expect(lastConfig(wrapper).assignment.fields).toEqual(['gestorImediatoId', 'gestorSubstitutoId'])
  })

  it('sem referência de usuário, explica como criar uma', () => {
    const wrapper = render({ assignment: { mode: 'field' } }, { userFields: [] })

    expect(wrapper.find('#st-who-field').attributes('disabled')).toBeDefined()
    expect(wrapper.find('.who-empty').text()).toContain('ainda não tem campo que identifique um usuário')
  })

  it('com referências disponíveis, não mostra o aviso', () => {
    expect(render({ assignment: { mode: 'field' } }).find('.who-empty').exists()).toBe(false)
  })

  it('voltar ao modo grupo remove a regra', async () => {
    const wrapper = render({ assignment: { mode: 'requester', fallbackGroup: 'PONTO_GESTOR' } })
    await wrapper.find('#st-who').setValue('group')

    expect(lastConfig(wrapper).assignment).toBeNull()
  })

  it('a etapa de abertura fica no modo grupo', () => {
    expect(render({}, { isOpening: true }).find('#st-who').attributes('disabled')).toBeDefined()
  })
})
