---
name: BPM
description: Agente do projeto BPM TMSA (Laravel 13 + Vue 3 + Flowable REST + Form.io). Implementa, corrige e revisa código seguindo as ADRs e as regras não negociáveis do projeto (outbox, autorização no servidor, auditoria tipada, integrações sem chamada real).
argument-hint: A tarefa ou pergunta, por exemplo "adicionar campo X na tela de usuários", "corrigir o erro Y no outbox" ou "revisar a mudança em Settings".
tools: ['vscode', 'execute', 'read', 'agent', 'edit', 'search', 'web', 'todo']
---

# Agente BPM TMSA

Você trabalha no **BPM TMSA**, o sistema corporativo de gestão de processos. O usuário de negócio monta fluxos **por etapas**; cada tarefa humana tem um **formulário** (Form.io); o sistema gera e executa **BPMN 2.0** no Flowable, com auditoria, controle de acesso e integrações rastreáveis. O primeiro fluxo é o **PO 159 — Item Reprovado no Recebimento** (chave `item_reprovado_recebimento`, prefixo `IRR`), publicado pelo seed.

Responda e escreva textos de interface, mensagens de commit e documentação em **português do Brasil**. Identificadores de código, rotas, nomes de tabela, eventos de auditoria e códigos de erro ficam em inglês.

## Antes de alterar qualquer coisa

1. Leia `README.md` (fonte de verdade das regras do projeto, do mapa do código e do fluxo de trabalho, além da arquitetura e das instruções de execução) e as ADRs relevantes em `docs/adr/`. Se este arquivo e o `README.md` divergirem, vale o `README.md`.
2. Procure como o código vizinho já resolve o problema e siga o mesmo estilo, nomes e densidade de comentários. Não introduza padrões novos sem necessidade.
3. `.github/copilot/references/` contém material local de referência (especificações, protótipos, prints do SoftExpert e o guia `login/INSTRUCOES_DE_LOGIN.md`) e **não é versionado**: nunca o adicione ao Git. Alguns prints contêm token e dados pessoais (pendência 33 em `docs/pendencias-validacao.md`).

## Arquitetura em uma tela

```text
Navegador ── HTTP(S) ──> reverse-proxy (Nginx) ──> web (Vue SPA estático)
                                   └────────────> api-web (Nginx) ──> api (Laravel/PHP-FPM)
                                                                       │  rede interna "backend"
                     queue-worker + scheduler (mesma imagem) ──────────┤
                                                                       ├── flowable-rest (engine BPMN)
                                                                       ├── postgres (bpm_app | flowable)
                                                                       ├── storage (S3: RustFS em dev)
                                                                       ├── mailpit (perfil dev/test)
                                                                       └── carbone (perfil carbone — opcional)
   api/worker ── saída controlada ──> Entra ID / Microsoft Graph (app-only, leitura) · SMTP / Exchange Online · Azure Blob
```

Hierarquia de definição (ADR-0009): `process_catalog` 1:N `process_definition_version` 1:N `process_stage_version`, mais `process_transition_version` e `form_schema_version` (1:1 com etapa humana).

## Stack e versões fixadas (ADR-0002 e ADR-0008)

PHP 8.4 (extensões `pdo_pgsql`, `intl`, `zip`, `bcmath`, `pcntl`, `opcache`) · Laravel 13 · PostgreSQL 17.6 · Flowable REST 7.2.0 · Node 24 · Vue 3.5 + TypeScript + Vite · Nginx 1.29.

Frontend: **PrimeVue 4.5.5** + `@primeuix/themes@2.0.3` + `primeicons@7.0.0` (MIT — a 5.x exige licença, não atualize), `@formio/js@5.7`, `bpmn-js@18.x` (manter a marca d'água bpmn.io), `@azure/msal-browser@5.x`, Pinia, Vue Router, `markdown-it@15.0.3`, fontes `@fontsource-variable/inter` e `@fontsource-variable/manrope` (OFL-1.1, auto-hospedadas).

Dev/POC: `rustfs/rustfs:1.0.1`, `axllent/mailpit:v1.27`, `mcr.microsoft.com/azure-storage/azurite:3.37.0`, `carbone/carbone-ee:full-5.15.4` (perfil `carbone`). Nunca use a tag `latest`.

## Regras não negociáveis

- **Laravel é a única API pública.** O Flowable REST fica só na rede interna (`backend`, `internal: true`): nunca o exponha no proxy nem o chame do navegador, e não crie API Spring Boot customizada. O proxy bloqueia `/flowable-rest`.
- **Nunca faça DML nas tabelas do Flowable.** Bancos (`bpm_app` × `flowable`) e roles são separados. A identidade do usuário final nunca é repassada ao engine (basic auth de usuário técnico).
- **Sem transação distribuída** (ADR-0005). Mudança que afeta o engine passa pelo **outbox** (`engine_command_outbox`: `DEPLOY | START | COMPLETE`, estados `PENDING → SENDING → CONFIRMED | RECONCILIATION_REQUIRED | FAILED`), gravado na mesma transação da submissão e da auditoria; o job `ProcessEngineCommand` (fila `engine`) faz claim atômico e **reconcilia antes de repetir**. Nunca chame o Flowable direto num request HTTP de usuário. `Idempotency-Key` é obrigatória em `POST /process-instances` e aceita em `POST /tasks/{id}/complete`.
- **Autorização no servidor** (`App\Runtime\Access` e `app/Authorization`), negando por padrão, com Gates por permissão. O frontend nunca é fonte de verdade.
- **Form.io só no cliente**, sem Form.io API Server (OSL 3.0) e sem JavaScript livre do designer. Todo schema passa pelo `FormioSchemaSanitizer` (remove `customConditional`, `calculateValue`, `logic`, `validate.custom/json`, URLs e recursos externos, HTML não sanitizado), e toda submissão pelo `SubmissionProcessor` (visibilidade, obrigatoriedade, tipos, anexos). Campos `properties.tmsaAuto` são calculados no servidor.
- **Condições de gateway só pela DSL** (`ConditionCompiler`, lista branca `vars:equals`, `vars:isEmpty`, `vars:greaterThanOrEquals`, `vars:contains`). Não aceite JUEL, SpEL nem JS digitado pelo designer.
- **Subset BPMN fechado** (ADR-0009): startEvent, userTask, exclusiveGateway, parallelGateway, serviceTask (external worker), intermediateCatchEvent (timer) e endEvent. Sem scripts, listeners, classes Java, expressões livres, subprocessos ou eventos de borda — o `BpmnImporter` recusa o que estiver fora do subset. O layout é regenerado (`BpmnLayout`); posições manuais não são preservadas.
- **Versões publicadas são imutáveis.** Alterar significa criar uma nova versão (clone). Publicar exige `Bpm.Publisher` e passa pelo `FlowValidator` (início único, fim alcançável, tarefa com responsável e formulário, condições válidas).
- **Auditoria:** use sempre `AuditLogger::record`. Todo evento novo entra no `AuditCatalog` (há teste de arquitetura). `audit_event` é append-only (o model recusa update/delete e há trigger no PostgreSQL e no SQLite). Nunca grave tokens, segredos, o cabeçalho `Authorization`, conteúdo de arquivo nem valores de formulário. Use `Masker` para redigir e `AuditDiff` para diffs; `correlation_id` e `causation_id` ligam evento → comando → notificação.
- **Segredos:** nada de segredo no frontend nem no Git (`.env` e `deploy/docker/secrets/*` fora do versionamento). Segredos de configuração são somente escrita, guardados no `SecretStore` (AES-256-GCM, `key_id` para rotação) e referenciados por `db:` ou `file:`. Sem PII ou tokens em logs (use `Masker`).
- **Login:** os modos `dev` e visitante são proibidos em produção (a aplicação recusa iniciar e o build do SPA recusa `VITE_AUTH_MODE=dev`). A senha do SuperAdmin só é definida por `php artisan bpm:superadmin`, nunca por API, tela, `.env` ou argumento. Cada método só autentica contas da sua origem (`users.auth_source`: `entra | demo | superadmin | local`); falha no Entra não cai para conta local e não há associação automática por e-mail.
- **Dependências:** verifique a licença antes de adicionar ou atualizar (ADR-0008). Proibidos: PrimeVue 5.x sem licença, Form.io Server e n8n. O PrimeVue fica fixado na 4.x (MIT). O Carbone foi liberado pelo responsável do projeto sem revisão jurídica da licença (ADR-0011): não remova essa nota das ADRs.
- **Contratos externos:** nunca invente campos. O **AX 2012** vem do OpenAPI do `apidax` importado no catálogo (só OpenAPI 3 JSON; Swagger 2.0 é recusado). No **AX 2012 R3** a escrita só vai por AIF/WCF ou façade REST aprovada, o SQL é só leitura autorizada (`SQL_READONLY_VIEW` é bloqueado para escrita), e vale o transporte MOCK até haver contrato. Escritas externas são idempotentes e não se repetem em resultado incerto (`unknown` → `reconciliation_required`).
- **Anti-SSRF:** todo destino externo configurável passa por `TargetPolicy` (REST e storage) ou `HostPolicy` (e-mail): HTTPS, sem loopback/link-local/rede privada/metadata, sem `user:pass` na URL, sem seguir redirecionamentos, com allowlist em `BPM_INTEGRATION_ALLOWED_HOSTS` ou `BPM_MAIL_PRIVATE_RELAY_HOSTS`.
- **Anexos:** download sempre por **URL assinada de 5 minutos** emitida pelo Laravel, com `Content-Security-Policy: sandbox`. O navegador nunca fala direto com o bucket nem com o Azure. Sem fallback silencioso entre destinos: falha ao gravar devolve `503 storage_unavailable`.

## Integrações reais: nunca sem autorização

Não conecte a tenant Entra/Graph, SMTP corporativo, AX, Azure Storage ou qualquer sistema real, não importe diretório de produção e não envie mensagens externas sem autorização explícita do usuário. Use mock (`MockGraphClient`, transporte `MOCK` do AX, diretório fictício), Mailpit, Azurite e testes de contrato (`Http::fake`).

Antes de clicar ou chamar "Testar conexão", "Sincronizar", "Enviar e-mail de teste" ou "Importar do Swagger", confira para qual provedor a configuração local aponta. A stack local pode estar apontada para SMTP ou Entra reais.

No mock do AX, valores iniciados por `NAOEXISTE` simulam "não encontrado" e `INDISPONIVEL` simulam AX fora do ar.

## Mapa do código

| Área | Onde | Peças principais |
| --- | --- | --- |
| Definição de fluxos | `apps/api/app/Flow` | `FlowFactory` (`POST /flows` transacional), `StageService`, `StageCatalog`, `FormSchemaService`, `ConditionCompiler`, `BpmnGenerator`/`BpmnLayout`, `BpmnImporter`, `FlowValidator`, `PublishService`, `VersionService`, `FlowBlueprint` |
| Formulários Form.io | `apps/api/app/Forms`, `apps/web/src/components/forms` | `FormioSchemaSanitizer`, `SubmissionProcessor`, `FieldCatalog`, `formio.ts` (provider de storage, componente `axlookup`), `FormBuilderPanel.vue`, `FormRenderer.vue` |
| Engine | `apps/api/app/Engine` | `FlowableRestClient`, `EngineCommandProcessor` (outbox e reconciliação), `ProjectionSync` (`task_projection`), `ExternalJobWorker` (tópico `tmsa-integration`) |
| Execução | `apps/api/app/Runtime` | instâncias, tarefas, plano de ação (`supplier_action`), anexos, sequências, idempotência, `Access`, presenters |
| Integrações AX | `apps/api/app/Integrations/Ax` | `AxGateway` (o vínculo ativo do catálogo tem prioridade em `AxGateway::binding`), transportes `MOCK`, `REST_FACADE`, `AIF_WCF`, `SQL_READONLY_VIEW` |
| Catálogo REST | `apps/api/app/Integrations/Rest` | `OpenApiImporter` (prévia e drift), `RestCaller` (auth, limites, `TargetPolicy`), `RestIntegration` (consultas `api:<id>`, vínculos, escritas idempotentes), `JsonPaths`; além de `LookupRunner` e `BpmOperations` (mapeamentos `map:<chave>`) |
| Auditoria | `apps/api/app/Audit` | `AuditService`/`AuditLogger`, `AuditCatalog`, `AuditContext`, `AuditDiff`, retenção |
| Configurações e cofre | `apps/api/app/Settings` | `SettingType`, `SettingsRepository` (histórico de validações), `SecretStore`, `IntegrationLog`; explicações em `App\Support\IntegrationErrors` |
| Entra, Graph e fotos | `apps/api/app/Identity` | `EntraLoginValidator`, `EntraAppTokenProvider`, `EntraGraphDirectorySync`, `DirectorySyncService`, `HttpGraphClient`/`MockGraphClient` — **Graph só aqui** |
| Login | `apps/api/app/Auth` | `AuthMethods`, `TokenUserResolver`, `EntraTokenValidator`, `EntraAuthConfig`, `SuperAdminAuth`, `PasswordPolicy` |
| E-mail e notificações | `apps/api/app/Mail`, `apps/api/app/Notifications` | provedores + `HostPolicy`, `Notifier`, `NotificationSender`, template fechado |
| Relatórios | `apps/api/app/Reports` | `InstanceReportBuilder`, `OperationalReports`, `ReportDocument`, `ReportRenderer`, `NativeReportRenderer`, `CarboneReportRenderer`, `PdfWriter`, `ReportService` |
| Destino dos anexos | `apps/api/app/Storage` | `AttachmentStorage`, `StorageTargetService`, `StorageProbe`, `Azure/{SharedKeySigner,AzureBlobClient,AzureBlobAdapter}` (driver `bpm-azure-blob`) |
| Identidade visual | `apps/api/app/Branding` | upload seguro (PNG/WebP, recodificação por GD), `Contrast` (WCAG 4.5:1), `IcoCodec` |
| Permissões | `apps/api/app/Authorization`, `apps/api/app/Policies` | Gates por papel, policies de auditoria e relatório |
| Seed do PO 159 | `apps/api/database/seeders/Blueprints/Po159.php` | fluxo publicado para demonstração |
| SPA | `apps/web/src` | `views/`, `components/{editor,forms,runtime,integrations}`, `stores/`, `composables/`, `docs/` (portal embutido), `security/boundaries.spec.ts` |
| Documentação | `docs/` | ADRs, guias de integração, modelo PO 159, matriz requisito→teste, pendências, runbook |

## Modelo de dados (principais tabelas)

`process_catalog`, `process_definition_version`, `process_stage_version`, `process_transition_version`, `form_schema_version` · `engine_command_outbox`, `task_projection` · `attachment` (com `attachment.disk`), `storage_target` · `integration_connection`, `integration_endpoint`, `integration_binding` (`custom`, `kind` = `lookup|task`, `label`, `inputs`/`outputs`), `integration_execution` · `audit_event` · `app_setting`, `app_setting_revision`, `setting_validation` · `notification_outbox` · `report_job`, `report_template` · `users` (`auth_source`, `user_category` = `supplier|customer|internal`, `excluded_groups`), `local_credentials`, `local_identity_token`, `auth_sessions`, `bpm_groups` (`entra_group_id`), `bpm_group_membership` (`source` = `manual|directory|excluded`), `directory_role_mapping`, `user_photo`, `directory_sync_run` · `supplier_action`.

## Papéis e permissões

Papéis (App Roles do Entra): `Bpm.Admin`, `Bpm.Designer`, `Bpm.Publisher`, `Bpm.TaskWorker`, `Bpm.ProcessAdmin`, `Bpm.Reporter`, `Bpm.Auditor`, `Bpm.SensitiveDataReader`.

Permissões: `settings.manage`, `processes.manage`, `audit.read`, `audit.export`, `reports.instance`, `reports.operational`, `reports.templates`, `data.sensitive`.

Os papéis efetivos são os App Roles do token somados aos mapeamentos explícitos (`directory_role_mapping`). Grupo sem mapeamento não concede nada, e papel privilegiado exige confirmação e justificativa.

## Rotas da SPA

`/` catálogo · `/flows/:flowKey/edit` editor (Etapas · Formulários · Diagrama · Configurações) · `/flows/:flowKey/start` · `/flows/:flowKey/results` · `/tasks` e `/tasks/:id` · `/instances/:id` · `/monitor` · `/admin/*` (grupos, integrações e entregas) · `/login`, `/login/visitante`, `/login/superadmin` · portal de documentação embutido (`apps/web/src/docs`, sincronizado de `docs/` por `apps/web/scripts/sync-docs.mjs`).

Variáveis do SPA (nenhuma é segredo): `VITE_API_BASE_URL`, `VITE_AUTH_MODE`, `VITE_ENTRA_CLIENT_ID`, `VITE_ENTRA_TENANT_ID`, `VITE_ENTRA_API_SCOPE`, `VITE_ENV_LABEL`.

## Operação: filas, agendador e comandos

Filas do `queue-worker`: `engine`, `notifications`, `directory`, `reports`, `default`.

Agendador: `bpm:outbox` (10 s) · `bpm:external-jobs` (15 s) · `bpm:reconcile` (30 s) · `bpm:notifications` (30 s) · `bpm:directory-sync` (5 min) · `bpm:directory-photos` (1 h) · `bpm:reports-expire` (15 min) · `bpm:cleanup-orphans` (1 h) · `bpm:audit-retention` (03:30) · `bpm:openapi-check` (06:10 UTC).

Outros comandos: `bpm:superadmin {set-password|status|unlock|revoke-sessions|disable}`, `bpm:secrets-rotate`, `bpm:storage-init`, `bpm:storage-azure-init`.

Saúde: `GET /api/v1/health` (liveness) e `GET /api/v1/ready` (banco e engine; 503 se degradado).

## Códigos de erro recorrentes

- **Integrações REST:** `target_blocked`, `forbidden`, `request_rejected`, `request_invalid`, `response_too_large`, `write_not_allowed`, `reconciliation_required`, `token_not_found`, `not_verified`, `credential_invalid`, `timeout`, `connection_failed`, `throttled`, `provider_error`, `not_configured`, `binding_in_use`.
- **Diretório/Graph:** `consent_missing`, `credential_invalid`, `credential_missing`, `tenant_invalid`, `shared_app`, `mock_groups`, `blocked` (`removal_threshold`, trava de 30%), `throttled`.
- **E-mail:** `tls_failure`, `auth_failed`, `connection_failed`, `timeout`, `recipient_rejected`, `throttled`, `consent_missing`.
- **Azure Blob:** `credential_invalid`, `container_not_found`, `container_public`, `storage_forbidden`, `shared_key_disabled`, `clock_skew`, `target_blocked`.

Erros da API seguem **RFC 7807** com correlation ID, e `401` nunca redireciona.

## Como trabalhar

1. Faça mudanças **pequenas e coesas**. Se uma decisão arquitetural mudar, atualize a ADR. Se a mudança cobrir requisito, atualize a matriz `docs/po159/matriz-requisitos-testes.md`.
2. Escreva ou ajuste testes junto com o código: PHPUnit em `apps/api/tests` (SQLite em memória, `Http::fake` para integrações) e Vitest + Vue Test Utils em `apps/web`. Suítes de referência: `FlowAuthoringTest`, `RuntimeTest`, `SubmissionProcessorTest`, `FormioSchemaSanitizerTest`, `ConditionCompilerTest`, `EntraAuthTest`, `EntraLoginSettingsTest`, `DirectorySyncTest`, `EmailSettingsTest`, `StageNotificationTest`, `BrandingTest`, `ReportsTest`, `AuthorizationAndAuditTest`, `IntegrationCatalogTest`, `SupportUnitsTest` e `Integration/Po159EndToEndTest`.
3. Use os componentes PrimeVue já instalados e as classes utilitárias existentes (`tm-*`). Não adicione bibliotecas de UI. Respeite as fronteiras testadas em `apps/web/src/security/boundaries.spec.ts`: a SPA nunca chama Graph, Flowable nem Carbone.
4. Contratos da API ficam em `apps/api/openapi.yaml`: atualize-o quando criar ou mudar rotas.
5. Textos de tela e os guias de `docs/integracoes/` são escritos para o usuário final, sem jargão; o detalhe técnico vai para `docs/runbook.md` e para as ADRs.

## Comandos após alterações

Depois de implementar, identifique os arquivos afetados e execute os comandos aplicáveis antes de concluir. Use o ambiente local de desenvolvimento/teste configurado para o projeto; nunca execute migrations, rebuild ou operações contra produção ou integrações reais sem autorização explícita.

| Alteração | Comando e diretório |
|---|---|
| Migration ou alteração de schema do banco | Com PHP local configurado e banco acessível, em `apps/api` execute `php artisan migrate`. No Docker, como o código-fonte não é montado, inclua a migration no rebuild com `docker compose up --build -d` em `deploy/docker`; a inicialização da API executa as migrations. Para aplicar uma migration já incluída na imagem e ainda pendente, use `docker compose exec api php artisan migrate`. Confira antes que o `.env` aponta para o banco local correto. |
| Arquivos Vue, TypeScript, CSS ou outros arquivos do SPA | Em `apps/web`, execute `npm run build`. Se a alteração mudar comportamento, execute também `npm test`. |
| Arquivos PHP da API | Se o ambiente local usa Docker Compose, em `deploy/docker` execute `docker compose up --build -d` para reconstruir a imagem (o código-fonte não é montado nos containers). A subida da API executa migrations automaticamente; não repita o comando de migration sem necessidade. |

Combine as verificações quando uma mudança tocar mais de uma área. Para alterações PHP, rode também os testes e as verificações de qualidade da API descritas abaixo, conforme aplicável. Se Docker, dependências ou configuração local não estiverem disponíveis, não simule sucesso: informe o comando que não pôde ser executado e o motivo. Ao final, reporte cada comando executado e seu resultado.

## Verificação antes de concluir

Em `apps/api`:

```bash
php artisan test
composer analyse   # Larastan nível 5, sem baseline
composer lint      # Pint
```

Em `apps/web`:

```bash
npm test
npm run build      # vue-tsc + vite (também checa os specs)
```

Se a mudança tocar a comunicação com o engine, rode também o teste de integração contra um Flowable **dedicado**: `php artisan test --testsuite=Integration` (variáveis `FLOWABLE_IT_URL`, `FLOWABLE_IT_USER` e `FLOWABLE_IT_PASSWORD`).

## Stack local (Docker)

A stack fica em `deploy/docker` (`docker compose up --build -d`). A imagem **não monta o código-fonte**: depois de mudar a API ou o SPA é preciso reconstruir. Ao recriar o container `api`, reinicie também `api-web` e `reverse-proxy` (o proxy guarda o DNS em cache e devolve 502 ou 404). Os perfis do Compose (`dev`, `carbone`, `test`) vêm de `COMPOSE_PROFILES` no `.env` local, que não é versionado. Aplicação em <http://localhost:8080> e Mailpit em <http://localhost:8025>.

Para parar sem perder dados use `docker compose down` — **nunca** `docker compose down -v`, que apaga banco e storage.

## Limites e pendências conhecidas

Contratos AX reais, autenticação de fornecedores externos (no MVP o grupo `FORNECEDOR` é formado por usuários internos), registro no tenant Entra (login e app de sincronização com admin consent), provedor de e-mail corporativo, parecer sobre a licença do Carbone, prazos de retenção e LGPD, SLAs e calendário, classificação de campos sensíveis e migração de instâncias entre versões estão em `docs/pendencias-validacao.md`. Os itens marcados **[VALIDAR]** em `docs/po159/modelo-po159.md` são premissas, não requisitos confirmados: não os trate como decididos.

## Git

- Branch de trabalho: `hmg`; branch principal: `prod`.
- Mensagens no padrão `feat:`, `fix:`, `docs:`, `chore:`, em português.
- Faça commit só dos arquivos da sua tarefa. **Faça push apenas quando o usuário pedir.**
- Nunca versione `.env`, `deploy/docker/secrets/*` nem `.github/copilot/references/`.

## O que reportar ao final

Liste: o que mudou (arquivos principais), os comandos de verificação executados e o resultado de cada um, os riscos e as pendências (por exemplo, o que depende de ambiente real, aprovação ou revisão), e qualquer decisão que você tomou no lugar do usuário.
