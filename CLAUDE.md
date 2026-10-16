# CLAUDE.md — BPM TMSA

Leia `README.md` e as ADRs em `docs/adr/` antes de alterar a arquitetura. A especificação original está em `.claude/BPMN_TMSA_Arquitetura_e_Plano_Claude_Code (1).md`. A pasta `.claude/` é material local de referência (não versionada).

## Regras do projeto (não negociáveis)

- **Laravel é a única API pública.** Flowable REST só em rede interna; nunca expor no proxy nem chamar do navegador. Não criar API Spring Boot customizada.
- **Sem transação distribuída.** Mudanças que afetam o engine passam pelo outbox (`engine_command_outbox`) gravado na mesma transação da submissão/auditoria; o worker reconcilia antes de repetir. Nunca chamar o Flowable direto num request HTTP de usuário.
- **Nunca DML nas tabelas do Flowable**; bancos/roles separados.
- **AX 2012:** contratos vêm do OpenAPI da API do AX (`apidax`) importado no catálogo — nunca inventar campos; saída sem documentação só por exemplo/teste. Credencial nunca como parâmetro de endpoint; escritas bloqueadas até aprovação, idempotentes e sem repetição em resultado incerto.
- **AX 2012 R3 (legado):** não inventar contratos. Escrita só por AIF/WCF ou façade REST aprovada; SQL apenas leitura autorizada. Sem chamada a AX real/produção sem autorização explícita. Use o transporte MOCK até receber contratos.
- **Form.io client-side**, sem Form.io API Server, sem JavaScript livre do designer. Todo schema passa pelo `FormioSchemaSanitizer`; toda submissão pelo `SubmissionProcessor` (validação no servidor).
- **Condições de gateway** só pela DSL (`ConditionCompiler`). Nada de JUEL/SpEL/JS digitado pelo designer.
- **Autorização no servidor** (`App\Runtime\Access`), negar por padrão; o frontend não é fonte de verdade.
- Modo de autenticação `dev` / visitante é proibido em produção (a aplicação recusa iniciar). Login: Entra ID + visitante + SuperAdmin de emergência (ADR-0015); a senha do SuperAdmin só é definida por `php artisan bpm:superadmin` (nunca por API, tela, `.env` ou argumento) e cada método só autentica contas da sua origem (`users.auth_source`).
- Sem segredos no frontend ou no Git (`.env` fora do versionamento). Sem PII/tokens em logs (use `Masker`).
- Versões publicadas são imutáveis; alterar = nova versão.
- Dependências: verificar licença antes de adicionar/atualizar (ver ADR-0008 — PrimeVue fixado na 4.x MIT; Carbone só com aprovação — ADR-0011).
- Entra/Graph, SMTP corporativo e Carbone: não conectar a tenant/servidor real, não importar diretório de produção nem enviar mensagens externas sem autorização; use mock, Mailpit e testes de contrato.
- Configurações/integrações: permissões Graph mínimas (somente leitura, app-only), nada de permissão de escrita no diretório; segredos somente escrita; testes de conexão sempre reais (nunca "sucesso fixo").

## Mapa do código

- `apps/api/app/Flow` — definição: `FlowFactory` (POST /flows transacional), `StageService`, `FormSchemaService`, `ConditionCompiler`, `BpmnGenerator`/`BpmnLayout`, `BpmnImporter`, `FlowValidator`, `PublishService`, `VersionService`, `FlowBlueprint` (seeds).
- `apps/api/app/Forms` — Form.io: sanitizer, avaliador de condicionais, processador de submissões, campos automáticos, catálogo de extensões, builder de schemas para seeds/testes.
- `apps/api/app/Engine` — `FlowableRestClient`, `EngineCommandProcessor` (outbox + reconciliação), `ProjectionSync`, `ExternalJobWorker`.
- `apps/api/app/Runtime` — instâncias, tarefas, plano de ação, anexos, sequências, idempotência, autorização, presenters.
- `apps/api/app/Integrations/Ax` — gateway + transportes (MOCK); vínculo ativo do catálogo tem prioridade (`AxGateway::binding`).
- `apps/api/app/Integrations/Rest` — catálogo de integrações REST (ADR-0016): `OpenApiImporter` (Swagger → endpoints, prévia/drift), `RestCaller` (auth/limites/`TargetPolicy` anti-SSRF), `RestIntegration` (consultas `api:<id>`, vínculos, escritas idempotentes com reconciliação), `JsonPaths` (inferência/mascaramento). Guia: `docs/integracoes/README.md`.
- `apps/api/app/Audit` — `AuditService`/`AuditCatalog` (eventos tipados — todo evento novo entra no catálogo), `AuditContext` (ator de sistema/causa), `AuditDiff`, retenção. Use sempre `AuditLogger::record`; nunca grave tokens, segredos ou valores de formulário.
- `apps/api/app/Settings` — configurações tipadas/versionadas (`SettingType`, `SettingsRepository` — grava o histórico de validações), `SecretStore` (cofre; segredos só por referência `db:`/`file:`) e `IntegrationLog` (log de e-mail/Entra para Configurações; explicações em `App\Support\IntegrationErrors`). Logs nunca levam senha, token, cursor delta ou corpo de e-mail.
- `apps/api/app/Identity` — Entra: `EntraLoginValidator` (descoberta/teste de token), `EntraAppTokenProvider` (client credentials), `EntraGraphDirectorySync` (adapter Graph) e `DirectorySyncService` (domínio do sync: checkpoint só ao fim, travas, prévia). Chamadas Graph somente aqui (nunca em controllers ou no SPA).
- `apps/api/app/Mail` + `app/Notifications` — provedores de e-mail (`HostPolicy` anti-SSRF), outbox (`Notifier`, `NotificationSender`), template fechado e destinatários da notificação por etapa.
- `apps/api/app/Reports` — `ReportRenderer` (nativo/Carbone), PDF próprio, CSV/XLSX, relatório por instância (snapshots) e operacionais; `ReportService` (jobs, expiração, download auditado).
- `apps/api/app/Auth` — `AuthMethods` (métodos e travas), `TokenUserResolver` (token → usuário por formato), `EntraTokenValidator`/`EntraAuthConfig`, `SuperAdminAuth` + `PasswordPolicy` (acesso de emergência, sessões opacas). Guia: `.claude/login/INSTRUCOES_DE_LOGIN.md`.
- `apps/api/app/Authorization` + `app/Policies` — permissões (Gates) por papel e policies de auditoria/relatório.
- `apps/api/database/seeders/Blueprints/Po159.php` — PO 159.
- `apps/web/src` — views (login com Entra/visitante/SuperAdmin em `LoginView.vue` + `auth/index.ts`, catálogo, editor, abertura, tarefa, instância, acompanhar, respostas, admin), `components/forms/formio.ts` (provider de storage, componente `axlookup`, opções do builder), `components/editor/*`.

## Fluxo de trabalho

1. PRs pequenos; atualize ADR quando uma decisão mudar e a matriz `docs/po159/matriz-requisitos-testes.md` quando cobrir requisito.
2. Antes de concluir: `php artisan test`, `composer analyse`, `composer lint` (apps/api); `npm test`, `npm run build` (apps/web).
3. Mudança em comunicação com o engine: rode também o teste de integração contra um Flowable **dedicado** (`--testsuite=Integration`).
4. Reporte alterações, comandos executados, riscos e pendências.
