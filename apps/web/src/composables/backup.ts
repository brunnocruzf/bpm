import type { BackupEntry, BackupScheduleConfig, BackupScope } from '@/api/types'

export const SCOPE_LABELS: Record<BackupScope, string> = { database: 'Banco', data: 'Dados', full: 'Aplicação inteira' }

export const SCOPE_HINTS: Record<BackupScope, string> = {
  database: 'Os dois bancos de dados.',
  data: 'Os bancos e os arquivos da plataforma: anexos, relatórios, auditoria arquivada, logos e fotos.',
  full: 'Tudo o que é preciso para recuperar em um servidor novo: dados, configuração e imagens de todos os containers.',
}

export const ORIGIN_LABELS: Record<BackupEntry['origin'], string> = { manual: 'Manual', schedule: 'Agendado', pre_restore: 'Antes de uma restauração' }

export const STAGE_LABELS: Record<string, string> = {
  preparing: 'Preparando', database: 'Copiando os bancos', verifying: 'Testando a carga dos bancos', encrypting: 'Criptografando', files: 'Copiando os arquivos',
  config: 'Empacotando a configuração', images: 'Exportando as imagens', azure: 'Enviando ao Azure', retention: 'Aplicando a retenção',
  validating: 'Conferindo o backup', safety: 'Copiando o estado atual', fetching: 'Baixando e conferindo', loading: 'Carregando os dados',
  stopping: 'Parando os serviços', swapping: 'Trocando os dados', finishing: 'Ajustando os dados', starting: 'Reiniciando os serviços',
}

export const JOB_LABELS: Record<string, string> = {
  backup: 'Backup', restore: 'Restauração', restore_test: 'Teste de restauração', delete: 'Exclusão', copy_azure: 'Envio ao Azure',
  catalog_sync: 'Atualização do catálogo', undo_restore: 'Desfazer restauração', release: 'Liberação',
}

export const WEEKDAYS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado']

export function describeSchedule(s: BackupScheduleConfig): string {
  if (s.frequency === 'weekly') return `${WEEKDAYS[s.weekday ?? 0]} às ${s.time}`
  if (s.frequency === 'monthly') return `${s.monthDay === 'last' ? 'Último dia do mês' : `Dia ${s.monthDay}`} às ${s.time}`
  return `Todo dia às ${s.time}`
}

export function daysSince(iso?: string | null): number | null {
  return iso ? Math.floor((Date.now() - new Date(iso).getTime()) / 86400000) : null
}
