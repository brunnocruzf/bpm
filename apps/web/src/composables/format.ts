const dateFmt = new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' })
const dateTimeFmt = new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })

export function fmtDate(iso?: string | null): string {
  return iso ? dateFmt.format(new Date(iso)) : '—'
}

export function fmtDateTime(iso?: string | null): string {
  return iso ? dateTimeFmt.format(new Date(iso)) : '—'
}

export function fmtAge(hours: number): string {
  if (hours < 1) return 'agora'
  if (hours < 24) return `${hours} h`
  const days = Math.floor(hours / 24)
  return `${days} d`
}

export function fmtSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

export const STAGE_TYPE_LABELS: Record<string, string> = {
  start: 'Início',
  user_task: 'Tarefa humana',
  exclusive_gateway: 'Decisão',
  parallel_gateway: 'Caminhos paralelos',
  service_task: 'Integração',
  timer: 'Timer',
  end: 'Fim',
}

export const INSTANCE_STATUS: Record<string, { label: string; tone: string }> = {
  starting: { label: 'Processando', tone: 'amber' },
  running: { label: 'Em andamento', tone: 'blue' },
  completed: { label: 'Concluída', tone: 'green' },
  cancelled: { label: 'Cancelada', tone: 'neutral' },
  error: { label: 'Erro de integração', tone: 'red' },
}

export const TASK_STATUS: Record<string, { label: string; tone: string }> = {
  open: { label: 'Aberta', tone: 'blue' },
  claimed: { label: 'Em execução', tone: 'amber' },
  completing: { label: 'Processando', tone: 'amber' },
  completed: { label: 'Concluída', tone: 'green' },
  cancelled: { label: 'Cancelada', tone: 'neutral' },
}
