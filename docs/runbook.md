# Runbook — operação do ambiente Docker (dev/POC)

## Subir do zero

```bash
cd deploy/docker
cp .env.example .env          # troque todos os valores; gere APP_KEY
sh secrets/generate.sh        # chave do cofre de segredos (Docker secret local, fora do Git)
docker compose up --build -d  # COMPOSE_PROFILES=dev (no .env) inclui o Mailpit
docker compose ps             # api, flowable-rest, postgres = healthy
```

Acesse `http://localhost:8080` (login de demonstração). E-mails capturados: `http://localhost:8025` (somente perfil `dev`/`test`).

O container `api` executa migrations, cria o bucket de anexos e (com `SEED_ON_START=true`) cria grupos, usuários fictícios, modelos de relatório padrão, o PO 159 publicado e fluxos de demonstração. O deploy no Flowable é feito pelo `queue-worker` via outbox.

## Saúde

- `GET /api/v1/health` — liveness da API.
- `GET /api/v1/ready` — banco + Flowable (503 se degradado).
- *Administração → Integrações e entregas*: engine, outbox, execuções AX e entregas de e-mail (dead-letter).
- *Configurações*: estado de cada integração (configurada, incompleta, teste pendente, erro, desabilitada) e última validação.

## Serviços

| Serviço | Função |
|---|---|
| `reverse-proxy` | único exposto; `/api` → `api-web`, resto → `web`; `/flowable-rest` bloqueado |
| `api` / `api-web` | PHP-FPM + Nginx interno |
| `queue-worker` | filas `engine` (outbox Flowable), `notifications` (e-mail), `directory` (Graph), `reports` (relatórios), `default` |
| `scheduler` | `bpm:outbox` (10 s), `bpm:external-jobs` (15 s), `bpm:reconcile` (30 s), `bpm:notifications` (30 s), `bpm:directory-sync` (5 min, dispara conforme periodicidade), `bpm:reports-expire` (15 min), `bpm:cleanup-orphans` (1 h), `bpm:audit-retention` (03:30) |
| `flowable-rest` | engine BPMN (rede interna) |
| `postgres` | bancos `bpm_app` e `flowable` |
| `storage` | RustFS (S3): anexos (padrão), logos, relatórios, arquivo de auditoria |
| `azurite` (perfis dev/test) | Emulador do Azure Blob para testar o destino de anexos sem tocar na nuvem |
| `mailpit` | perfil `dev`/`test`: captura de e-mails, sem rota de saída |
| `carbone` | perfil `carbone` (opcional; rede interna, porta não publicada) |

## Comandos úteis

```bash
docker compose exec api php artisan bpm:outbox            # processa comandos pendentes agora
docker compose exec api php artisan bpm:reconcile --all   # ressincroniza todas as instâncias em execução
docker compose exec api php artisan bpm:notifications     # envia/reagenda notificações vencidas
docker compose exec api php artisan bpm:directory-sync --now   # sincronização delta imediata (--full = recarga)
docker compose exec api php artisan bpm:reports-expire    # remove artefatos de relatório vencidos
docker compose logs -f queue-worker scheduler
```

## Login e acesso de emergência (SuperAdmin)

Guia completo: `.github/copilot/references/login/INSTRUCOES_DE_LOGIN.md` (ADR-0015).

```bash
docker compose exec api php artisan bpm:superadmin set-password         # cria/troca a senha (interativo; --generate | --stdin | --password-file)
docker compose exec api php artisan bpm:superadmin status               # habilitado, bloqueio, último acesso, sessões
docker compose exec api php artisan bpm:superadmin unlock               # após bloqueio por tentativas
docker compose exec api php artisan bpm:superadmin revoke-sessions      # derruba sessões abertas
docker compose exec api php artisan bpm:superadmin disable              # desativa (reative com set-password)
```

- Habilitar: `BPM_SUPERADMIN_LOGIN_ENABLED=true` (+ `BPM_SUPERADMIN_ALLOWED_IPS` obrigatório em produção) e `docker compose up -d`.
- Após o uso em produção: trocar a senha ou desativar, voltar `BPM_SUPERADMIN_LOGIN_ENABLED=false` e revisar a Auditoria (Identidade e acesso).
- Visitante (demonstração): `BPM_AUTH_DRIVER=dev` ou `BPM_DEMO_LOGIN_ENABLED=true` + build `WEB_BUILD_MODE=docker-dev`; a API recusa iniciar assim em `APP_ENV=production` (use `BPM_DEMO_URL` para apontar a um ambiente de demonstração separado).

## Logs das integrações (E-mail e Entra ID)

Em **Configurações → E-mail e notificações** e **Configurações → Entra ID (login / usuários e grupos)** há um **log** com filtros (tipo, origem, período, "somente falhas", busca) e, em cada registro: nível, código, duração, quem disparou, etapas, detalhes, **explicação do erro ("o que significa / o que fazer")** e correlation ID (`GET /api/v1/admin/settings/logs/{email|entra}`, permissão `settings.manage`).

- **E-mail:** cada teste de conexão (etapas: política de rede → conexão/TLS/autenticação, com tempos), cada e-mail de teste e **cada tentativa de entrega** das notificações (resultado, código, motivo, próxima tentativa, Message-ID do provedor), além das alterações da configuração.
- **Entra ID:** descobertas OIDC e testes de login/token; logins Microsoft, saídas e **tokens recusados pela API** (motivo: audience, issuer, scope, tenant, expiração, assinatura... com as claims públicas *não verificadas* tid/aud/iss/ver/scp); importações com **log por etapa** (modo e motivo da carga, páginas de grupos/usuários, vínculos, travas de segurança, papéis recalculados, checkpoint) e erros por registro; mapeamentos e usuários importados/desativados.
- Para rastrear um caso no log técnico (`docker compose logs api queue-worker`), use o **correlation ID** do registro.
- Sem segredos: senhas, tokens, cursores delta e corpo dos e-mails nunca aparecem. O histórico de testes segue o prazo de retenção da auditoria; entregas e execuções seguem os prazos próprios (Configurações → Auditoria e retenção).

## Integrações REST (catálogo / API do AX)

Guia: `docs/integracoes/README.md` (ADR-0016).

- Host interno da API do AX (`apidax.tmsa.ind.br` → 10.x) precisa estar em `BPM_INTEGRATION_ALLOWED_HOSTS` (plataforma); em produção, todos os hosts.
- Erros de chamada: aba **Execuções** (explicação de cada código + correlation ID). `target_blocked` = host não declarado; `credential_invalid` = senha/token do usuário de serviço; `write_not_allowed` = escrita não liberada na conexão.
- **Escrita com resultado incerto** (`unknown`, tempo esgotado após o envio): confira no AX e use **Reconciliar** ("foi feita" com as saídas ou "não foi feita"). Não há repetição automática antes disso.
- Mudança no Swagger: `docker compose exec api php artisan bpm:openapi-check` (também diário) marca endpoints alterados; reimporte pela conexão e revise.
- Rollback: desative o **vínculo** (a operação volta ao transporte configurado, MOCK) ou o endpoint/conexão.

Os guias de `docs/integracoes` são escritos para usuários. Referência técnica (antes nos guias):

| Variável | Padrão | Uso |
|---|---|---|
| `BPM_INTEGRATION_ALLOWED_HOSTS` | — | hosts liberados (lista por vírgula); obrigatório para IP privado e, em produção, para todos |
| `BPM_INTEGRATION_MAX_RESPONSE_BYTES` | 2 MB | resposta máxima de uma chamada (`response_too_large`) |
| `BPM_INTEGRATION_MAX_OPENAPI_BYTES` | 5 MB | documento OpenAPI máximo |
| `BPM_INTEGRATION_TIMEOUT` | 15 s | tempo limite padrão (2–120 na conexão) |
| `BPM_AX_{TPR,ITEM,PO,SUPPLIER,TRANSFER}_TRANSPORT` | `MOCK` | transporte sem vínculo ativo; `REST_FACADE`/`AIF_WCF`/`SQL_READONLY_VIEW` não implementados (`not_configured`); o Compose não repassa |

- Fixos: token de login em cache 50 min (OAuth2: `expires_in − 60 s`, renova uma vez em 401); exemplo de resposta 512 KB; prévia do OpenAPI expira em 30 min; só OpenAPI 3 JSON.
- Agendador: `bpm:external-jobs` a cada 15 s (10 jobs do tópico `tmsa-integration`, trava PT5M); falha → retry PT5M (instância/operação ausente PT2M) consumindo tentativa do Flowable (padrão 3) → dead-letter sem retomada pela UI; exceção não tratada não consome tentativa. `bpm:openapi-check` diário 06:10 UTC.
- Idempotência da escrita: chave `<instância>:<elemento>:<job>` por endpoint; `success` devolve saídas gravadas, `unknown` bloqueia com `reconciliation_required`. HTTP 404 em escrita = sucesso com saídas vazias; 4xx≠404 em consulta vira 503 para o usuário.
- Consultas: `GET /api/v1/lookups/{fonte}` (qualquer autenticado, `throttle:120,1`, valor ≤ 60); a submissão refaz a consulta (`SubmissionProcessor`) e grava `metadata.lookups` (sem máscara).
- Códigos de erro: `target_blocked`, `forbidden`, `request_rejected`, `request_invalid`, `response_too_large`, `write_not_allowed`, `reconciliation_required`, `token_not_found`, `not_verified`, `credential_invalid`, `timeout`, `connection_failed`, `throttled`, `provider_error`, `not_configured`.
- Auditoria: `integration.{completed,failed,connection_saved,connection_tested,secret_updated,openapi_imported,endpoint_contract_updated,endpoint_saved,endpoint_deleted,endpoint_tested,binding_saved,binding_created,binding_deleted,execution_reconciled}`.
- Mapeamentos personalizados ("Novo mapeamento"): linha em `integration_binding` com `custom = true`, `kind` (`lookup` | `task`), `label`, `inputs`/`outputs`; operação, fonte do Consulta AX e integração do fluxo usam a mesma chave `map:<chave>` (`App\Integrations\BpmOperations`). Sem transporte simulado: inativo → `not_configured` (transporte `INATIVO` na Visão geral; o validador do fluxo emite `service_operation_inactive`). Rotas: `POST /admin/integrations/bindings`, `PUT|DELETE /admin/integrations/bindings/{operação}` (exclusão recusada com `binding_in_use` se citado em formulário ou etapa).
- Rastreio: correlation ID nos detalhes da execução e nos logs — `docker compose logs api queue-worker scheduler | grep <id>`.
- Rotas (`/api/v1/admin/integrations/...`): `connections` (+`/{id}/test`, `/openapi/preview|apply`), `endpoints` (+`/infer`, `/{id}/test`), `bindings/{operação}`, `executions` (+`/{id}/reconcile`, exige `processes.manage`). Visão geral: papel admin/auditor; demais: `settings.manage` (execuções também `processes.manage`/`audit.read`). Código: `apps/api/app/Integrations/Rest/*`, `LookupRunner`, `ExternalJobWorker`; testes `IntegrationCatalogTest` (fixture `tests/Fixtures/openapi-ax-sample.json`).

## Comando em RECONCILIATION_REQUIRED / FAILED

1. Verifique `last_error` (mascarado) na tela de integrações ou via `GET /api/v1/admin/engine-commands`.
2. Corrija a causa (engine fora, credencial, BPMN rejeitado).
3. "Reconciliar" reenvia **após consultar o engine** (não duplica instância nem conclusão).

## Sincronização do diretório (Entra / Microsoft Graph)

1. **Pré-requisitos (administrador do Entra):** App Registration separado do login, permissões de aplicação `User.Read.All` + `Group.Read.All` com *admin consent*, credencial por certificado (preferido) ou workload identity federation. Nada de permissões de escrita ou `Directory.Read.All`.
2. **Credencial:** monte o PEM (certificado + chave) como Docker secret/secret manager em `/run/secrets/<nome>` e informe `file:<nome>`, ou cole o PEM (fica criptografado no cofre). Client secret só com referência de aprovação.
3. Configurações → Entra ID — usuários e grupos: tenant, client ID, escopo (grupos selecionados por object ID **ou** "todo o diretório", com confirmação), filtros, periodicidade → **Testar conexão e permissões** → **Prévia (dry run)** → revisar o plano → **Sincronizar agora**. No escopo "todo o diretório" a primeira carga lê todos os usuários e grupos do tenant: rode a prévia e confira os totais antes.
4. **Mapeamentos:** sem mapeamento ninguém recebe acesso. Papéis privilegiados exigem confirmação e justificativa.
5. **Falhas:**
   - `consent_missing` / `credential_invalid` / `credential_missing` / `tenant_invalid`: corrigir no Entra/credencial e testar de novo; o cursor não avançou.
   - `consent_missing` com o app já "com permissões": quase sempre foram cadastradas como **Delegated**. A importação usa client credentials (sem usuário logado) e o token só traz permissões **Application**. O teste mostra o app (nome e client ID) e as permissões de aplicação que vieram no token; corrija em API permissions → Add a permission → Microsoft Graph → *Application permissions* → `User.Read.All`, `Group.Read.All` → *Grant admin consent*. Sem essas permissões, as leituras de teste no diretório não são executadas.
   - `shared_app` (alerta): o app tem outras permissões de aplicação (ex.: `Sites.FullControl.All`). Funciona, mas o segredo desse app fica guardado no BPM — use um App Registration exclusivo e somente leitura.
   - `mock_groups`: grupos de demonstração (`6f1e0c1a-0000-4000-8000-…`) com o transporte Microsoft Graph; o salvamento é recusado. Informe os Object IDs dos grupos reais.
   - `throttled` / `timeout`: a próxima execução retoma do `nextLink` salvo (idempotente).
   - `blocked` (`removal_threshold`): a rodada desativaria/removeria acima da trava (30%). Investigue (grupo errado? diretório em manutenção?) antes de executar recarga completa; ajuste `BPM_DIRECTORY_MAX_REMOVAL_RATIO` só com aprovação.
   - Cursor expirado (7 dias sem sincronizar ou 410 do Graph): a execução faz recarga completa controlada automaticamente.
6. **Fotos de perfil:** ligadas por padrão (Configurações → Entra ID — usuários e grupos → "Importar a foto do perfil"). Usam a mesma permissão `User.Read.All`; o teste de conexão passa a conferir a leitura de foto. O comando `bpm:directory-photos` roda de hora em hora (verificação por etag, lote de 200); para forçar, use "atualizar agora" no painel do usuário. Desligar a opção apaga as fotos armazenadas na próxima execução do comando e interrompe o serviço delas imediatamente. Usuário sem foto no Entra ID (404) mostra iniciais. O transporte fictício gera fotos sintéticas para 3 usuários de teste (incrementar a chave de cache `bpm:mock-photo-rev` simula a troca de foto).
7. **Rollback:** desabilite a sincronização (os usuários continuam como estão); remova/desative mapeamentos para retirar papéis concedidos pelo diretório (recalculados na hora). Nada é apagado; contas desativadas pelo diretório voltam a ativas quando reabilitadas no Entra.

## E-mail

1. Configurações → E-mail e notificações: escolha o provedor, remetente/reply-to e credencial (somente escrita). Em produção o Mailpit é recusado.
2. **Testar conexão** (sem envio) e depois **Enviar teste** para o seu endereço. Resultado, tempo, código de erro sanitizado e ID no provedor ficam registrados.
3. Relay interno sem TLS: só se declarado em `BPM_MAIL_PRIVATE_RELAY_HOSTS` pela plataforma.
4. **Dead-letter:** Administração → Integrações e entregas → "Entregas de e-mail" → filtrar falhas → corrigir causa → **Reprocessar**. Tarefas nunca dependem do envio.
5. **Rollback:** selecione o provedor anterior (cada gravação é uma versão auditada) ou `disabled` (notificações ficam registradas como descartadas).

## Relatórios

- Gerados no worker (fila `reports`); arquivos privados no storage com expiração (Configurações → Auditoria e retenção → "Arquivos de relatório (horas)", padrão 24 h). Download sempre pela API (autorizado e auditado).
- Falha `render_timeout`: reduza o escopo/filtros; limites `BPM_REPORTS_MAX_ROWS`, `BPM_REPORTS_MAX_BYTES`, `BPM_REPORTS_TIMEOUT`.
- **Carbone (uso liberado pelo responsável do projeto, sem revisão de licença — ADR-0011):** gere o par de chaves (`docker run --rm carbone/carbone-ee:slim-5.15.4 generate-keys`), salve `secrets/carbone_key.pub`, gere o token (`generate-token`) e monte-o para a API (`BPM_CARBONE_TOKEN_FILE`), defina `BPM_CARBONE_ENABLED=true`, suba com `COMPOSE_PROFILES=dev,carbone`, selecione o renderer em Configurações → Relatórios, crie e ative um modelo Carbone e use "Testar serviço Carbone". **Rollback:** volte o renderer para "Nativo" (o perfil pode continuar parado).

## Auditoria e retenção

- A trilha é append-only (trigger recusa UPDATE/DELETE/TRUNCATE). Consulta e exportação: tela **Auditoria** (`audit.read` / `audit.export`).
- Retenção só com prazo aprovado (Configurações → Auditoria e retenção). O expurgo diário arquiva em `audit-archive/AAAA/MM/*.jsonl.gz` + `.manifest.json` (SHA-256) no storage antes de apagar. Copie o arquivo para armazenamento imutável (Object Lock/WORM) conforme a política corporativa.
- Verificar um arquivo: `sha256sum audit-*.jsonl.gz` deve conferir com o manifesto.

## Rotação da chave do cofre de segredos

```bash
# 1) gere a nova chave e preserve a anterior
cp secrets/bpm_secrets_key secrets/bpm_secrets_key.old
head -c 32 /dev/urandom | base64 > secrets/bpm_secrets_key
# 2) informe a chave antiga temporariamente (BPM_SECRETS_PREVIOUS_KEYS=<conteúdo de .old>) e reinicie api/worker/scheduler
docker compose up -d api queue-worker scheduler
docker compose exec api php artisan bpm:secrets-rotate
# 3) remova BPM_SECRETS_PREVIOUS_KEYS, reinicie e apague o arquivo .old
```

Sem a chave, os segredos do cofre ficam ilegíveis: inclua o arquivo da chave no backup **separado** do banco.

## Backup e restauração (dev)

```bash
docker compose exec postgres pg_dump -U postgres -Fc bpm_app  > bpm_app.dump
docker compose exec postgres pg_dump -U postgres -Fc flowable > flowable.dump
# restaurar: pg_restore -U postgres -d <db> --clean <arquivo>
```

## Armazenamento de anexos (Azure Blob)

1. **Pré-requisitos (plataforma/Azure):** conta de armazenamento dedicada ao BPM, contêiner **privado**, Shared Key permitido na conta, soft delete/versionamento conforme a retenção, firewall liberando o servidor e o host `<conta>.blob.core.windows.net` declarado em `BPM_INTEGRATION_ALLOWED_HOSTS`.
2. Configurações → Armazenamento de anexos → Azure Blob: conta, contêiner, prefixo, chave (ou `file:<nome>` de segredo montado — recomendado), referência da aprovação → **Salvar** → **Testar** → **Ativar**. Só os **novos** anexos mudam de destino; a tabela "Onde estão os anexos" mostra o uso por destino.
3. **Rotação da chave:** gere a chave 2 no portal, informe-a aqui e salve, teste e ative (mesma conta/contêiner/prefixo = mesmo destino; anexos antigos seguem legíveis); depois regenere a chave 1.
4. **Falhas:** `credential_invalid` (conta/chave), `container_not_found`, `container_public`, `storage_forbidden` (firewall/permissão), `shared_key_disabled` (Azure Policy), `clock_skew` (NTP), `target_blocked` (declarar o host). Cada código é explicado no log da tela. Upload com destino fora do ar devolve 503 (sem gravar em outro lugar).
5. **Rollback:** "Voltar ao padrão" faz os novos anexos voltarem ao disco do ambiente; os anexos no Azure continuam legíveis enquanto a chave existir. **Não apague** a chave/conta enquanto houver anexos nela. Arquivos **não são migrados** automaticamente.
6. **Desenvolvimento:** `docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d azurite`, `BPM_AZURITE_BLOB_ENDPOINT=http://azurite:10000/devstoreaccount1` na API, endpoint "Emulador" na tela (chave pública documentada do Azurite) e `php artisan bpm:storage-azure-init` para criar o contêiner. Teste de integração: `AZURITE_IT_ENDPOINT=http://127.0.0.1:10000/devstoreaccount1 php artisan test --testsuite=Integration --filter=AzuriteBlobTest`.

Faça backup dos dois bancos **e** do volume `storagedata` (e, com Azure, da política de soft delete/versionamento da conta) no mesmo ponto no tempo; guarde a chave do cofre em local separado. Teste a restauração periodicamente. Em produção, use banco gerenciado/HA e política de backup imutável/offsite.

## Rollback de versão da aplicação

As migrations novas são aditivas (colunas anuláveis e tabelas novas). Para voltar a imagem anterior: `docker compose up -d` com a tag anterior; as tabelas novas são ignoradas. `php artisan migrate:rollback` remove os triggers de auditoria e as tabelas da fase 2 — use apenas com backup e aprovação (apaga configurações, execuções e relatórios).

## Limitações

Compose em servidor único não oferece alta disponibilidade, failover nem DR; serve para desenvolvimento e piloto restrito (§11.4).
