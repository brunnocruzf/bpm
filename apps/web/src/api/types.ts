// Contratos da API /api/v1 (ver apps/api/openapi.yaml).

export type StageType = 'start' | 'user_task' | 'exclusive_gateway' | 'parallel_gateway' | 'service_task' | 'timer' | 'end'

export interface Problem {
  type?: string
  title: string
  status: number
  detail?: string
  code?: string
  correlationId?: string
  errors?: Record<string, string[]>
  problems?: ValidationProblem[]
}

export interface Me {
  id: string
  name: string
  email: string | null
  area: string | null
  roles: string[]
  groups: { key: string; name: string }[]
  permissions: {
    design: boolean; publish: boolean; start: boolean; work: boolean; audit: boolean; admin: boolean
    // permissões granulares (Gates no servidor) — apenas apresentação
    settingsManage: boolean; processesManage: boolean; auditRead: boolean; auditExport: boolean
    reportsInstance: boolean; reportsOperational: boolean; reportsTemplates: boolean; dataSensitive: boolean
  }
  counters: { openTasks: number }
  /** método do login atual; SuperAdmin traz a expiração absoluta da sessão */
  auth: { method: 'entra' | 'demo' | 'superadmin' | 'local'; expiresAt?: string; idleMinutes?: number }
}

export interface Option { key: string; label: string }

export interface Meta {
  groups: { key: string; name: string; description: string | null }[]
  categories: string[]
  autoSources: Option[]
  lookupSources: { key: string; label: string; fields: Option[] }[]
  serviceOperations: { key: string; label: string; outputs: string[] }[]
  endResults: Option[]
  conditionOperators: Option[]
}

export interface CatalogItem {
  id: string
  key: string
  name: string
  category: string
  description: string | null
  status: string
  instancePrefix: string
  initiatorGroups?: string[]
  publishedVersion: { version: number; deployed: boolean; publishedAt: string | null } | null
  latestVersion: { version: number; status: string } | null
  hasDraft: boolean
  draftVersion: number | null
  instances: number
  openInstances: number
  updatedAt: string | null
  canStart?: boolean
}

export interface ConditionRule { field: string; operator: string; value: string | number | boolean | null }
export interface Condition { match: 'all' | 'any'; rules: ConditionRule[] }

export interface Transition {
  id: string
  elementId: string
  target: string
  targetName: string
  condition: Condition | null
  conditionText: string
  isDefault: boolean
  label: string | null
}

export interface Stage {
  id: string
  key: string
  elementId: string
  name: string
  type: StageType
  lane: string | null
  position: number
  description: string | null
  config: Record<string, any>
  form: { id: string; formKey: string; title: string; status: string; fieldCount: number } | null
  incomingCount: number
  outgoing: Transition[]
}

export interface FieldInfo { key: string; label: string; type: string; values: { value: string; label: string }[]; stages: string[] }

export interface ValidationProblem { severity: 'error' | 'warning' | 'info'; code: string; message: string; stageKey: string | null }

export interface Version {
  id: string
  version: number
  status: 'draft' | 'published' | 'retired' | 'suspended'
  lockVersion: number
  hash: string | null
  publishedAt: string | null
  engineDeployed: boolean
  validation: { problems: ValidationProblem[]; at: string } | null
  catalog: { key: string; name: string; category: string; description: string | null; instancePrefix: string }
  stages: Stage[]
  fields: FieldInfo[]
  openingStageKey: string | null
}

export interface FormSchema { display?: string; components: any[] }

export interface CurrentTask { id: string; name: string; candidateGroup: string | null; candidateGroupName: string | null; assigneeName: string | null; dueAt: string | null; overdue: boolean }

export interface InstanceSummary {
  id: string
  number: string
  flow: { key: string; name: string; category: string }
  version: number
  requesterName: string
  requesterArea: string | null
  startedAt: string
  endedAt: string | null
  status: 'starting' | 'running' | 'completed' | 'cancelled' | 'error'
  result: string | null
  resultLabel: string | null
  processing: boolean
  currentTasks: CurrentTask[]
  ageDays: number
}

export interface TaskSummary {
  id: string
  name: string
  stageKey: string
  status: 'open' | 'claimed' | 'completing' | 'completed' | 'cancelled'
  candidateGroup: string | null
  candidateGroupName: string | null
  assignee: { id: string; name: string } | null
  completedBy: string | null
  dueAt: string | null
  createdAt: string
  completedAt: string | null
  ageHours: number
  overdue: boolean
  rowVersion: number
  canWork: boolean
  canClaim: boolean
  instance?: { id: string; number: string; flowKey: string; flowName: string; requesterName: string; requesterArea: string | null; startedAt: string; status: string }
}

export interface TimelineEvent { at: string; type: string; title: string; detail: string; taskId?: string }

export interface AttachmentInfo { id: string; name: string; size: number; type: string; fieldKey: string | null; avStatus: string; taskId: string | null; supplierActionId: string | null; uploadedAt: string }

export interface SupplierActionInfo {
  id: string
  kind: string
  action: string
  responsible: string
  dueDate: string | null
  location: string | null
  notes: string | null
  status: 'pending' | 'done' | 'superseded'
  completedBy: string | null
  completedAt: string | null
  completionNotes: string | null
  evidences: AttachmentInfo[]
}

export interface TaskDetail extends TaskSummary {
  description: string | null
  form: { id: string; formKey: string; title: string; schema: FormSchema } | null
  data: Record<string, any>
  draft: Record<string, any> | null
  submission: { id: string; authorName: string; createdAt: string; revision: number } | null
  actionPlan: { mode: 'capture' | 'execute'; actions: SupplierActionInfo[] } | null
  attachments: AttachmentInfo[]
  timeline: TimelineEvent[]
}

export interface InstanceDetail extends InstanceSummary {
  tasks: TaskSummary[]
  submissions: { id: string; stageKey: string; stageName: string; authorName: string; revision: number; createdAt: string }[]
  attachments: AttachmentInfo[]
  supplierActions: SupplierActionInfo[]
  diagram: { bpmnXml: string | null; activeElements: string[]; completedElements: string[]; endElement: string | null }
  pendingCommands: { id: string; command: string; state: string; attempts: number }[]
}

export interface Paged<T> { data: T[]; meta: { page: number; total: number; lastPage: number; perPage?: number } }

// ---------- Configurações ----------
export type SettingStatus = 'configured' | 'incomplete' | 'test_pending' | 'error' | 'disabled'

export interface ValidationCheck { name: string; ok: boolean; code?: string | null; message?: string | null; detail?: string | null; warning?: boolean }

export interface SettingView<T = Record<string, any>> {
  key: string
  slug?: string
  label: string
  status: SettingStatus
  statusDetail: string | null
  version: number
  value: T
  updatedAt: string | null
  updatedBy: string | null
  lastValidation: ({ ok: boolean; code?: string; message?: string; step?: string; checks?: ValidationCheck[]; durationMs?: number; by?: string; version?: number } & Record<string, any>) | null
  lastValidatedAt: string | null
  activatedAt: string | null
  requiresActivation: boolean
}

export interface SecretMeta { configured: boolean; source: string | null; updatedAt: string | null; readable: boolean }

export interface Branding { appName: string; orgName: string; tagline: string; colors: { primary: string; secondary: string; accent: string }; derived?: { onPrimary: string; onSecondary: string; primaryText: string; primaryHover: string }; assets: { main: string | null; compact: string | null; favicon: string | null }; links?: BrandingLinks; version: number }

/** Links institucionais da tela de login (https:// ou mailto:; null = não exibido). */
export interface BrandingLinks { support: string | null; privacy: string | null; help: string | null }

export interface SyncRun {
  id: string; mode: 'full' | 'delta' | 'preview'; trigger: string; status: 'queued' | 'running' | 'completed' | 'partial' | 'failed' | 'blocked'
  transport: string; startedAt: string | null; finishedAt: string | null; durationMs: number | null; totals: Record<string, number>
  errorCode: string | null; errorMessage: string | null; cursorAdvanced: boolean; requestedBy: string | null; createdAt: string; correlationId: string | null
  errorsCount: number; errors?: { type: string; id: string | null; code: string; message: string }[]; log?: { at: string; ms: number; level: 'success' | 'info' | 'warning' | 'error'; step: string; message: string; data?: Record<string, unknown> }[]; explain?: { title: string; cause: string; action: string } | null; plan?: { changes: { action: string; type: string; label: string; hint?: string; access?: { roles: string[]; groups: string[] } }[]; note: string } | null
}

export interface AuditEventRow {
  id: string; occurredAt: string; category: string; action: string; label: string; outcome: 'success' | 'failure' | 'blocked'
  actorType: 'user' | 'system'; actorName: string | null; actorUpn: string | null; entityType: string; entityId: string | null; objectName: string | null
  flowKey: string | null; definitionVersion: number | null; instanceNumber: string | null; processInstanceId: string | null; engineProcessInstanceId: string | null
  stageKey: string | null; taskId: string | null; correlationId: string | null; causationId: string | null
  actorOid?: string | null; formSchemaVersionId?: string | null; idempotencyKey?: string | null; outboxId?: string | null; operation?: string | null; ipAddress?: string | null
  data?: Record<string, any>; diff?: Record<string, { from: any; to: any }> | null; related?: AuditEventRow[]
}

export interface ReportJobView {
  id: string; type: string; format: 'pdf' | 'csv' | 'xlsx'; status: 'queued' | 'rendering' | 'ready' | 'failed' | 'expired'; progress: number
  parameters: Record<string, any>; processInstanceId: string | null; renderer: string | null; template: { id: string; version: number } | null
  errorCode: string | null; error: string | null; fileName: string | null; size: number | null; rows: number | null
  createdAt: string; finishedAt: string | null; expiresAt: string | null
}
