import { DISABLED_REASON } from '@/composables/format'

export interface ManagerRef { id: string; name: string; email: string | null; username?: string | null; authSource: string | null }

export interface UserRow {
  id: string
  name: string
  email: string | null
  upn: string | null
  username: string | null
  area: string | null
  department: string | null
  authSource: string | null
  userCategory: string | null
  userType: string | null
  accountEnabled: boolean | null
  disabledReason: string | null
  roles: string[]
  groups: string[]
  isActive: boolean
  invitationPending: boolean
  invitationStatus: 'none' | 'pending' | 'expired' | 'accepted' | null
  locked: boolean
  lastSeenAt: string | null
  directorySyncedAt: string | null
  manager: ManagerRef | null
  managerPending: boolean
  photo: { version: string } | null
}

export type ProfileTarget = Pick<UserRow, 'id' | 'name'>

export interface PageMeta { currentPage: number; lastPage: number; perPage?: number; total: number }

export interface Facets {
  total: number
  source: Record<string, number>
  active: { true: number; false: number }
  category: Record<string, number>
  attention: Record<string, number>
  areas: string[]
  roles: { key: string; label: string }[]
}

export interface UserFilters { q: string; source: string; active: string; category: string; attention: string; area: string; group: string; role: string }

export const emptyFilters = (): UserFilters => ({ q: '', source: '', active: '', category: '', attention: '', area: '', group: '', role: '' })

export interface ActivityItem { action: string; label: string; outcome: string; actor: string | null; occurredAt: string; diff?: Record<string, { from: unknown; to: unknown }> | null }

export interface EntraGroupInfo { objectId: string; name: string; mail: string | null; syncedAt: string | null; grants: { groupKey: string | null; roles: string[]; privileged: boolean }[] }

export interface EntraIntegration {
  oid: string | null; tid: string | null; upn: string | null; userType: string | null; accountEnabled: boolean | null
  directorySyncedAt: string | null; importedBySync: boolean
  photo: { enabled: boolean; status: 'present' | 'absent' | 'error' | 'unchecked'; checkedAt: string | null; errorCode: string | null }
  groups: EntraGroupInfo[]
  sync: { lastSuccessAt: string | null; nextRunAt: string | null; alerts: { code: string; message: string }[] }
}

export interface LocalIntegration {
  invitation: {
    status: 'none' | 'pending' | 'expired' | 'accepted'; sentAt: string | null; expiresAt: string | null; acceptedAt: string | null; invitedBy: string | null
    delivery: { state: string; sentAt: string | null; failedAt: string | null; errorCode: string | null } | null
  }
  credential: { lastLoginAt: string | null; failedAttempts: number; lockedUntil: string | null; passwordChangedAt: string | null }
  sessions: { active: number; lastUsedAt: string | null }
}

export interface UserDetail {
  identity: { photo: { version: string } | null; id: string; name: string; username: string | null; email: string | null; upn: string | null; authSource: string | null; entraOid: string | null; entraTid: string | null }
  organization: { area: string | null; department: string | null; userType: string | null; userCategory: string | null; manager: string | null; managerInfo: ManagerRef | null; managerPending: boolean; directReports: number }
  account: { active: boolean; accountEnabled: boolean | null; disabledReason: string | null; disabledAt: string | null; createdAt: string | null; updatedAt: string | null; lastLoginAt: string | null; lastSeenAt: string | null; lockedUntil: string | null; directorySyncedAt: string | null; invitationPending: boolean }
  access: {
    roles: string[]
    roleSources: { token: string[]; directoryMapping: string[]; manual: string[] }
    groups: string[]
    groupSources: { entraToken: string[]; directoryMapping: string[]; manual: { key: string; name: string; createdAt: string | null; createdBy: string | null }[] }
  }
  integration: { source: string | null; entra: EntraIntegration | null; local: LocalIntegration | null }
  activity: { data: ActivityItem[]; meta: { currentPage: number; lastPage: number; total: number } }
}

/** Situação resumida exibida em pílula (lista e painel). */
export function userState(u: Pick<UserRow, 'isActive' | 'authSource' | 'invitationStatus' | 'locked' | 'disabledReason'>): { label: string; tone: string } {
  if (u.locked) return { label: 'Bloqueado', tone: 'red' }
  if (u.isActive) return { label: 'Ativo', tone: 'green' }
  if (u.authSource === 'local' && u.invitationStatus === 'pending') return { label: 'Convite pendente', tone: 'amber' }
  if (u.authSource === 'local' && u.invitationStatus === 'expired') return { label: 'Convite expirado', tone: 'amber' }
  if (u.disabledReason) return { label: DISABLED_REASON[u.disabledReason] ?? 'Inativo', tone: 'neutral' }
  return { label: 'Inativo', tone: 'neutral' }
}
