/** Tipos do catálogo de integrações REST (Administração → Integrações e entregas; ADR-0016). */
export interface Explain { title: string; cause: string; action: string }

export interface Connection {
  id: string; key: string; name: string; system: string; baseUrl: string; environment: 'desenvolvimento' | 'homologacao' | 'producao'; enabled: boolean
  timeoutSeconds: number; authType: AuthType
  authConfig: { loginPath?: string; userName?: string; userField?: string; passwordField?: string; tokenPath?: string; tokenTtlMinutes?: number; headerName?: string; prefix?: string; tokenUrl?: string; clientId?: string; scope?: string; testPath?: string }
  credential: { configured: boolean; source: string | null; updatedAt: string | null; readable: boolean }
  openapiUrl: string | null; openapiTitle: string | null; openapiVersion: string | null; openapiImportedAt: string | null; openapiCheckedAt: string | null
  allowWrites: boolean; writesApproval: string | null
  lastTest: { ok: boolean; verified?: boolean; code: string; message: string; durationMs: number; checks: { name: string; ok: boolean; code?: string | null; message?: string | null; detail?: string | null }[]; by?: string } | null
  lastTestedAt: string | null; lastTestExplain: Explain | null
  endpointsCount: number; enabledEndpointsCount: number; changedEndpoints: number
  policy: { ok: boolean; message: string | null }; hostDeclared: boolean; updatedAt: string | null
}

export type AuthType = 'none' | 'bearer_token' | 'basic' | 'api_key_header' | 'login' | 'oauth2_client_credentials'

export interface Param { name: string; in: 'path' | 'query' | 'header'; type: string; required: boolean; mode: 'input' | 'constant'; constant: string | null; description: string | null }
export interface Field { path: string; label: string; type: string; sensitive: boolean; hint?: string | null; required?: boolean; description?: string | null }

export interface EndpointRow {
  id: string; key: string; name: string; tag: string | null; method: string; path: string; enabled: boolean; kind: 'read' | 'write'
  source: 'swagger' | 'manual'; contractStatus: 'ok' | 'changed' | 'removed'; version: number
  connection: { id: string; key: string; name: string; system: string; enabled: boolean; allowWrites: boolean; environment: string }
  lookupExposed: boolean; boundTo: string | null; hasResponseFields: boolean; paramsCount: number; warningsCount: number; updatedAt: string | null
}

export interface Endpoint extends EndpointRow {
  description: string | null; operationId: string | null; params: Param[]; bodyFields: Field[]; responseFields: Field[]; responseSample: unknown
  responseIsList: boolean; resultPath: string | null
  lookup: { expose: boolean; label?: string | null; inputParam?: string | null; fields?: string[]; allowSensitive?: boolean }
  timeoutSeconds: number | null; warnings: string[]; usedInForms: number; lookupSourceKey: string; url: string; bindings: { operation: string; enabled: boolean }[]
}

export interface PreviewOperation {
  method: string; path: string; tag: string; name: string; description: string | null; operationId: string | null; suggestedKey: string
  params: Param[]; bodyFields: Field[]; responseFields: Field[]; responseIsList: boolean; flags: string[]; warnings: string[]
  status: 'new' | 'changed' | 'unchanged'; endpointId: string | null; enabled: boolean
}

export interface Preview {
  previewId: string; bytes: number; sameAsImported: boolean
  meta: { title: string; version: string; openapi: string; operations: number; reads: number; writes: number; security: { name: string; type: string | null; in: string | null; header: string | null }[]; servers: string[]; tags: string[] }
  operations: PreviewOperation[]; removed: { endpointId: string; key: string; method: string; path: string; enabled: boolean }[]; warnings: string[]
}

/**
 * Operação do BPM ligável a um endpoint. Fixas (PO 159): consultas 'lookup' e a escrita 'write' do AX, com transporte
 * configurado (MOCK) sem vínculo. Personalizadas (custom, "map:<chave>"): 'lookup' (consulta nos formulários) ou
 * 'task' (etapa automática do fluxo); sem vínculo ativo ficam inativas (não há dados simulados).
 */
export interface BindingOp {
  operation: string; label: string; kind: 'lookup' | 'write' | 'task'; inputs: Record<string, string>; outputs: Record<string, string>; configuredTransport: string | null
  custom: boolean; description: string | null; usage: { forms: number; stages: number } | null
  lookupSource?: string; serviceOperation?: string
  binding: null | { endpointId: string | null; endpointKey: string | null; endpointName: string | null; connection: string | null; enabled: boolean; inputMap: Record<string, string>; outputMap: Record<string, string>; updatedAt: string | null; usable: boolean }
}

export const KIND_LABEL: Record<BindingOp['kind'], string> = { lookup: 'consulta nos formulários', write: 'etapa automática · escrita', task: 'etapa automática do fluxo' }

export interface Execution {
  id: string; at: string; system: string; operation: string; transport: string; endpoint: string | null; result: string; httpStatus: number | null; attempt: number
  durationMs: number; correlationId: string | null; processInstanceId: string | null; idempotencyKey: string | null; request: unknown; response: unknown; explain: Explain | null
}

export const METHOD_TONE: Record<string, string> = { GET: 'blue', POST: 'amber', PUT: 'amber', PATCH: 'amber', DELETE: 'red' }
export const ENV_LABEL: Record<string, string> = { desenvolvimento: 'Desenvolvimento', homologacao: 'Homologação', producao: 'Produção' }
export const AUTH_LABEL: Record<string, string> = {
  none: 'Sem autenticação',
  bearer_token: 'Bearer token (Authorization: Bearer …)',
  basic: 'Basic (usuário e senha)',
  api_key_header: 'Chave de API em cabeçalho',
  login: 'Login do usuário de serviço → token',
  oauth2_client_credentials: 'OAuth2 client credentials',
}

/** Explicação curta de cada método (o que o servidor envia). */
export const AUTH_HELP: Record<string, string> = {
  none: 'Nenhum cabeçalho de autenticação (APIs públicas internas).',
  bearer_token: 'Token fixo enviado como “Authorization: Bearer <token>”. É o caso da API do AX (apidax). Pode colar com ou sem o “Bearer ”.',
  basic: 'Envia “Authorization: Basic <usuário:senha em base64>”.',
  api_key_header: 'Envia a chave em um cabeçalho próprio (ex.: X-API-Key), com prefixo opcional.',
  login: 'O servidor faz login (POST com usuário e senha no corpo), guarda o token em cache criptografado e envia “Authorization: Bearer <token>”.',
  oauth2_client_credentials: 'O servidor pede um token ao endpoint OAuth2 (client ID + secret no corpo), guarda em cache até expirar e envia “Authorization: Bearer <token>”.',
}
export const RESULT_TONE = (r: string) => (r === 'success' ? 'green' : r === 'not_found' ? 'neutral' : r === 'unknown' || r === 'reconciliation_required' ? 'amber' : 'red')
export const RESULT_LABEL: Record<string, string> = { success: 'Sucesso', not_found: 'Não encontrado', unknown: 'Resultado incerto', reconciled_retry: 'Reconciliado (repetir)' }
