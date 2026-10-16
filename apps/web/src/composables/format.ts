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

/** Classificação administrativa de contas locais (não concede acesso). */
export const USER_CATEGORY: Record<string, { label: string; tone: string; hint: string }> = {
  supplier: { label: 'Fornecedor', tone: 'amber', hint: 'Pessoa de empresa fornecedora' },
  customer: { label: 'Cliente', tone: 'blue', hint: 'Pessoa de empresa cliente' },
  internal: { label: 'Interno', tone: 'brand', hint: 'Colaborador da organização' },
}
export const USER_CATEGORY_OPTIONS = Object.entries(USER_CATEGORY).map(([value, v]) => ({ value, ...v }))

export const AUTH_SOURCE: Record<string, string> = { entra: 'Entra ID', local: 'Conta local', demo: 'Visitante', superadmin: 'SuperAdmin' }
export const ENTRA_USER_TYPE: Record<string, string> = { Member: 'Membro', Guest: 'Convidado (B2B)' }
export const DISABLED_REASON: Record<string, string> = {
  admin: 'Desativada pelo administrador', directory_disabled: 'Desabilitada no Entra ID',
  directory_deleted: 'Removida do Entra ID', superadmin_disabled: 'SuperAdmin desativado',
}

/** Iniciais (até 2) de um nome, para avatares. */
export function initialsOf(name?: string | null): string {
  return (name ?? '?').split(/\s+/).filter((w) => /^[A-Za-zÀ-ú]/.test(w)).slice(0, 2).map((w) => w[0]).join('').toUpperCase()
}

export const INVITATION_DELIVERY: Record<string, { label: string; tone: string }> = {
  queued: { label: 'Na fila de envio', tone: 'blue' }, sending: { label: 'Enviando', tone: 'blue' }, sent: { label: 'Aceito pelo provedor de e-mail', tone: 'green' },
  retrying: { label: 'Aguardando nova tentativa', tone: 'amber' }, failed: { label: 'Falha no envio', tone: 'red' }, skipped: { label: 'Não enviado', tone: 'amber' },
}
export const DELIVERY_ERROR: Record<string, string> = { provider_disabled: 'o serviço de e-mail está desabilitado', no_recipient: 'a conta não tem e-mail' }
