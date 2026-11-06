import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { api } from '@/api/http'
import type { BackupEntry, BackupScheduleConfig, BackupStatus } from '@/api/types'
import { describeSchedule } from '@/composables/backup'
import BackupSettings from './BackupSettings.vue'

const push = vi.hoisted(() => vi.fn())
const notify = vi.hoisted(() => ({ error: vi.fn(), success: vi.fn() }))
const save = vi.hoisted(() => vi.fn())
vi.mock('vue-router', () => ({ useRouter: () => ({ push }) }))
vi.mock('primevue/useconfirm', () => ({ useConfirm: () => ({ require: vi.fn() }) }))
vi.mock('@/composables/notify', () => ({ useNotify: () => notify }))
vi.mock('@/api/http', async (importOriginal) => ({
  ...await importOriginal<typeof import('@/api/http')>(),
  api: vi.fn(),
}))
const settingValue = vi.hoisted(() => ({ current: {} as Record<string, unknown> }))
vi.mock('@/composables/useSetting', () => ({
  useSetting: () => ({
    setting: ref({ status: 'configured', version: 1, value: settingValue.current }), loading: ref(false), saving: ref(false), err: () => undefined,
    load: vi.fn(), save, action: vi.fn(),
  }),
}))

const entry = (id: string, override: Partial<BackupEntry> = {}): BackupEntry => ({
  id, scope: 'database', origin: 'schedule', scheduleId: null, bytes: 2048, inLocal: true, inAzure: true, keyFingerprint: 'age1…x7m2',
  backupAt: '2026-11-06T05:00:00Z', verifiedAt: null, verification: null, keepUntil: null, ...override,
})
const status = (override: Partial<BackupStatus> = {}): BackupStatus => ({
  mode: 'normal', organization: 'TMSA', agent: { online: true, lastBeatAt: null }, lastBackup: null, nextRunAt: null,
  recoveryKey: { fingerprint: 'age1…x7m2', confirmedAt: '2026-11-04T10:00:00Z', confirmedBy: 'Administrador' }, lastRestoreTest: null, warnings: [], activeJob: null, ...override,
})

async function render(st: BackupStatus = status(), entries: BackupEntry[] = []) {
  vi.mocked(api).mockImplementation(async (path: string) => {
    if (path === '/admin/backups/status') return st as never
    if (path === '/admin/backups') return { data: entries } as never
    return { data: [] } as never
  })
  const wrapper = mount(BackupSettings, {
    global: { stubs: { SettingHeader: true, IntegrationLog: true, NewBackupDialog: true, RecoveryKeyDialog: true, RestoreDialog: true, RestoreTestDialog: true, ScheduleDialog: true, RouterLink: { template: '<a><slot /></a>' } } },
  })
  await flushPromises()
  return wrapper
}
const button = (w: Awaited<ReturnType<typeof render>>, text: string) => w.findAll('button').find((b) => b.text().includes(text))

describe('backup e recuperação', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    settingValue.current = { timezone: 'America/Sao_Paulo', localCopies: 2, azure: { enabled: true, folder: 'backups' }, schedules: [], recoveryKey: null, azureTarget: { accountName: 'tmsabpm', container: 'anexos', pathPrefix: '', emulator: false } }
  })
  afterEach(() => vi.useRealTimers())

  it('avisa que falta a chave e não deixa iniciar backup sem ela', async () => {
    const wrapper = await render(status({ recoveryKey: null }))
    expect(wrapper.text()).toContain('Falta a chave de recuperação')
    expect(button(wrapper, 'Fazer backup agora')?.attributes('disabled')).toBeDefined()
  })

  it('mostra quando o agente está fora do ar e bloqueia as ações', async () => {
    const wrapper = await render(status({ agent: { online: false, lastBeatAt: null } }), [entry('a')])
    expect(wrapper.text()).toContain('Fora do ar')
    expect(button(wrapper, 'Restaurar')?.attributes('disabled')).toBeDefined()
  })

  it('avisa quando falta backup completo ou teste de restauração', async () => {
    const wrapper = await render(status({ warnings: ['no_recent_full_backup', 'no_recent_restore_test'] }))
    expect(wrapper.text()).toContain('sem ele não dá para recuperar em um servidor novo')
    expect(wrapper.text()).toContain('Nenhum teste de restauração recente')
  })

  it('acompanha a operação em andamento e bloqueia outra', async () => {
    const activeJob = { id: 'j', kind: 'backup', status: 'running', stage: 'azure', percent: 85 } as BackupStatus['activeJob']
    const wrapper = await render(status({ activeJob }), [entry('a')])
    expect(wrapper.text()).toContain('Backup em andamento')
    expect(wrapper.text()).toContain('Enviando ao Azure (85%)')
    expect(button(wrapper, 'Fazer backup agora')?.attributes('disabled')).toBeDefined()
  })

  it('oferece reenviar quando a cópia não chegou ao Azure, e não apagar o mais recente', async () => {
    const wrapper = await render(status(), [entry('novo', { inAzure: false }), entry('antigo')])
    expect(wrapper.text()).toContain('Sem cópia no Azure')
    expect(button(wrapper, 'Enviar ao Azure')).toBeTruthy()
    expect(wrapper.findAll('button[aria-label="Apagar backup"]')).toHaveLength(1)
  })

  it('vai para a revisão quando há restauração aguardando', async () => {
    await render(status({ mode: 'safe' }))
    expect(push).toHaveBeenCalledWith({ name: 'settings-backups-review' })
  })

  it('avisa do risco de manter os backups no contêiner dos anexos', async () => {
    const wrapper = await render()
    await button(wrapper, 'Agendamentos e destino')!.trigger('click')
    expect(wrapper.text()).toContain('mesmo contêiner dos anexos')
  })

  it('sem destino ativo, orienta a configurar o Armazenamento de anexos', async () => {
    settingValue.current = { ...settingValue.current, azure: { enabled: false, folder: 'backups' }, azureTarget: null }
    const wrapper = await render()
    await button(wrapper, 'Agendamentos e destino')!.trigger('click')
    expect(wrapper.text()).toContain('Armazenamento de anexos')
    expect((wrapper.find('input[type="checkbox"]').element as HTMLInputElement).disabled).toBe(true)
  })

  it('grava a pasta e o número de cópias', async () => {
    save.mockResolvedValue(true)
    const wrapper = await render()
    await button(wrapper, 'Agendamentos e destino')!.trigger('click')
    await wrapper.find('#d-folder').setValue('bpm-backups')
    await button(wrapper, 'Salvar')!.trigger('click')
    expect(save).toHaveBeenCalledWith({ timezone: 'America/Sao_Paulo', localCopies: 2, azure: { enabled: true, folder: 'bpm-backups' } })
  })
})

describe('descrição dos agendamentos', () => {
  const base: BackupScheduleConfig = { enabled: true, frequency: 'daily', time: '02:00', weekday: null, monthDay: null, scope: 'database', azure: false, keep: 7, verify: false }

  it('escreve a frequência em português', () => {
    expect(describeSchedule(base)).toBe('Todo dia às 02:00')
    expect(describeSchedule({ ...base, frequency: 'weekly', weekday: 0, time: '03:00' })).toBe('Domingo às 03:00')
    expect(describeSchedule({ ...base, frequency: 'monthly', monthDay: 1 })).toBe('Dia 1 às 02:00')
    expect(describeSchedule({ ...base, frequency: 'monthly', monthDay: 'last' })).toBe('Último dia do mês às 02:00')
  })
})
