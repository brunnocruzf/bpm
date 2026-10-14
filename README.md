# BPM TMSA

Sistema corporativo de gestão de processos (BPM) para substituir o módulo Workflows do SoftExpert no escopo da TMSA. O usuário de negócio monta fluxos **por etapas**, cada tarefa humana tem seu **formulário**, e o sistema gera e executa **BPMN 2.0** com trilha de auditoria, controle de acesso e integrações rastreáveis.

Primeiro fluxo: **PO 159 — Item Reprovado no Recebimento** (publicado pelo seed).

Especificação e protótipo de origem: `.claude/BPMN_TMSA_Arquitetura_e_Plano_Claude_Code (1).md` e `.claude/BPMN_TMSA_Prototipo_Editor.html`.

## Arquitetura

```text
Navegador ── HTTP(S) ──> reverse-proxy (Nginx) ──> web (Vue SPA estático)
                                   └────────────> api-web (Nginx) ──> api (Laravel/PHP-FPM)
                                                                       │  rede interna "backend"
                     queue-worker + scheduler (mesma imagem) ──────────┤
                                                                       ├── flowable-rest (engine BPMN)
                                                                       ├── postgres (bpm_app | flowable)
                                                                       ├── storage (S3: RustFS em dev)
                                                                       ├── mailpit (perfil dev/test)
                                                                       └── carbone (perfil carbone — só com licença aprovada)
   api/worker ── saída controlada ──> Entra ID / Microsoft Graph (app-only, leitura) · SMTP / Exchange Online
```

- **Laravel é a única API pública**; o Flowable REST fica em rede interna e só o backend/worker o acessa (ADR-0001).
- Abertura/conclusão gravam submissão + auditoria + comando no **outbox** na mesma transação; o worker envia ao Flowable e **reconcilia** antes de repetir (ADR-0005).
- **Form.io** (builder/renderer) roda no navegador; schemas e submissões são persistidos e **validados no servidor** (ADR-0003).
- **Login** (ADR-0004/0015): **Microsoft Entra ID** (MSAL no SPA, JWT validado no Laravel) como método principal; **visitante** (ambiente de demonstração com usuários fictícios — nunca em produção) e **SuperAdmin** (usuário e senha de emergência, desabilitado por padrão, IPs restritos em produção). Guia completo: `.claude/login/INSTRUCOES_DE_LOGIN.md`.
- **AX 2012 R3**: adaptador com transporte por operação; nesta entrega só **mock** (nenhum contrato inventado) (ADR-0006).
- **Configurações** (menu exclusivo de `settings.manage`): identidade visual, login Entra, importação de usuários/grupos via Graph, e-mail, auditoria/retenção e relatórios — valores versionados, segredos somente escrita no cofre criptografado (ADR-0013/0014).
- **Auditoria** funcional append-only, tipada e correlacionada (ADR-0010); **notificação por etapa** e outbox de e-mail (ADR-0012); **relatórios** assíncronos com PDF/CSV/XLSX nativos e Carbone opcional (ADR-0011).

## Estrutura

```text
apps/web        Vue 3 + TypeScript + Vite + PrimeVue 4 (MIT) + Pinia + Form.io + bpmn-js + MSAL
apps/api        Laravel 13 (API, domínio, policies, jobs, migrations, testes, openapi.yaml)
apps/flowable   Imagem fixada do Flowable REST 7.2.0 (sem lógica TMSA)
deploy/docker   docker-compose.yml, override de dev, Nginx, init do PostgreSQL
docs            ADRs, modelo PO 159, matriz requisito→teste, pendências, runbook
```

## Subir com Docker (recomendado)

Pré-requisito: Docker Desktop / Docker Engine + Compose.

```bash
cd deploy/docker
cp .env.example .env    # troque TODAS as senhas; gere APP_KEY:
                        # php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
sh secrets/generate.sh  # chave do cofre de segredos (Docker secret local; nunca versionada)
docker compose up --build -d   # COMPOSE_PROFILES=dev inclui o Mailpit
```

- Aplicação: <http://localhost:8080>
- E-mails capturados (dev): <http://localhost:8025>
- OpenAPI: <http://localhost:8080/api/v1/openapi.yaml>

A tela de login (`/login`) oferece **Entrar com Microsoft** (quando o Entra estiver configurado) e, abaixo, **Acessar como visitante** e **Acesso SuperAdmin**. O SuperAdmin local é criado com `docker compose exec api php artisan bpm:superadmin set-password` (a senha nunca vai para o `.env`). Todas as formas de login estão em `.claude/login/INSTRUCOES_DE_LOGIN.md`.

No acesso de visitante, escolha um perfil:

| Usuário | Papel | Uso |
|---|---|---|
| Diego Designer | Bpm.Designer | cria/edita fluxos, etapas, formulários e rotas |
| Paula Publicadora | Bpm.Publisher | valida e publica versões |
| Carla — CQ | CQ | abre "Item reprovado no recebimento" (botão **Novo**) |
| Pedro — PPCPM, Clara — Comitê, Gabriel — GEF, Fernanda — Fornecedor, Sérgio — SUP, Fábio — Fiscal, Quésia — Qualidade, Eduardo — Expedição, Fátima — Faturamento | raias do PO 159 | executam as etapas |
| Ana Administradora | Bpm.Admin (+ ProcessAdmin, Reporter) | **Configurações**, grupos/Entra, integrações, reconciliação |
| Gisele — Gestão de processos | Bpm.ProcessAdmin, Bpm.Reporter | reatribuição, reprocessamento de e-mails/comandos, relatórios operacionais |
| Augusto — Auditor | Bpm.Auditor | consulta todas as solicitações, trilha de auditoria e exportação |

Dica para o mock do AX: qualquer TPR/item/OC retorna dados fictícios; valores iniciados por `NAOEXISTE` simulam "não encontrado" e `INDISPONIVEL` simulam AX fora do ar.

## Desenvolvimento local (sem containers para API/SPA)

```bash
# infraestrutura auxiliar publicada só em 127.0.0.1 (Postgres 55432, Flowable 8090, S3 9100, SMTP 1025)
cd deploy/docker && docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d postgres flowable-rest storage mailpit

# API
cd apps/api && cp .env.example .env   # preencha DB_PASSWORD e FLOWABLE_REST_PASSWORD do deploy/docker/.env
composer install && php artisan key:generate && php artisan migrate --seed
php artisan serve                     # http://127.0.0.1:8000
php artisan queue:work --queue=engine,notifications,directory,reports,default   # outro terminal
php artisan schedule:work                       # outro terminal

# SPA
cd apps/web && npm ci && npm run dev  # http://localhost:5173 (proxy /api → 127.0.0.1:8000)
```

## Qualidade

```bash
cd apps/api
php artisan test                         # unidade + API (SQLite em memória, Flowable simulado)
composer analyse                         # Larastan nível 5
composer lint                            # Pint
FLOWABLE_IT_URL=http://127.0.0.1:8090/flowable-rest FLOWABLE_IT_USER=... FLOWABLE_IT_PASSWORD=... \
  php artisan test --testsuite=Integration   # PO 159 ponta a ponta contra Flowable real (use um engine dedicado)

cd apps/web
npm test && npm run build              # Vitest (inclui fronteiras: SPA nunca chama Graph/Flowable/Carbone) + build
```

O teste de integração também pode rodar na rede interna do Compose, sem publicar o Flowable:

```bash
docker run --rm --network bpm-tmsa_backend --entrypoint php -v "$PWD/apps/api:/app" -w /app \
  -e FLOWABLE_IT_URL=http://flowable-rest:8080/flowable-rest -e FLOWABLE_IT_USER=... -e FLOWABLE_IT_PASSWORD=... \
  bpm-tmsa/api:dev vendor/bin/phpunit --testsuite=Integration
```

## Habilitar integrações (local/mock → homologação → produção)

| Integração | Local (mock) | Homologação | Produção | Depende de admin do tenant? |
|---|---|---|---|---|
| Login Entra | `BPM_AUTH_DRIVER=dev` (visitante com usuários fictícios; o botão Microsoft funciona se o Entra estiver configurado) + SuperAdmin local | Configurações → Entra ID — login: parâmetros da SPA/API, **Validar descoberta**, **Testar login**, **Ativar**; build `WEB_BUILD_MODE=production` com `BPM_AUTH_DRIVER=entra` | idem, com Redirect URIs de produção | Sim: App Registrations SPA + API, scope exposto, consentimento, App Roles |
| Usuários e grupos (Graph) | Transporte "Diretório fictício" + "Usar grupos do diretório fictício" → Prévia → Sincronizar | App de sincronização separado, certificado montado (`file:<nome>`), grupos de teste, prévia antes da 1ª carga | idem com grupos reais; periodicidade; mapeamentos revisados | Sim: `User.Read.All` + `Group.Read.All` (Application) com **admin consent** |
| E-mail | Mailpit (perfil `dev`) | SMTP corporativo/relay ou M365 (Graph `Mail.Send` / SMTP OAuth) com caixa de teste | idem; Mailpit bloqueado; egress allowlist | M365: sim (permissão + restrição de caixa no Exchange) |
| Relatórios | Renderer nativo | Nativo; Carbone só no perfil `carbone` após aprovação | idem | Não (jurídico/segurança para o Carbone) |
| AX | `MOCK` | API REST do AX (`apidax`): Integrações e entregas → Conexão → **Importar do Swagger** → saída por exemplo/teste → Mapeamentos (declarar o host em `BPM_INTEGRATION_ALLOWED_HOSTS`) | idem, conexão de produção; escritas só com aprovação | Não (time AX/plataforma) — guia `docs/integracoes/README.md` |

**Rollback:** cada configuração é versionada — volte aos valores anteriores (ou desabilite a integração) pela própria tela; o login volta à configuração do ambiente com **Desativar**; mapeamentos de diretório podem ser desativados (papéis recalculados na hora). Detalhes no `docs/runbook.md`.

## Documentação

- `docs/adr/` — decisões (engine, versões, formulários, identidade, outbox, AX, storage, licenças, subset BPMN, auditoria, relatórios/Carbone, e-mail/notificações, Entra login × Graph, configurações/segredos/identidade visual, métodos de login)
- `docs/integracoes/README.md` — catálogo de integrações REST (estilo SoftExpert), importação do Swagger da API do AX, mapeamentos e execuções
- `.claude/login/INSTRUCOES_DE_LOGIN.md` — todas as formas de login (Entra, visitante, SuperAdmin, API) e operação do acesso de emergência
- `docs/po159/modelo-po159.md` — mapeamento das atividades do PO 159 e premissas **[VALIDAR]**
- `docs/po159/matriz-requisitos-testes.md` — rastreabilidade requisito → teste
- `docs/pendencias-validacao.md` — o que precisa de decisão antes do aceite
- `docs/runbook.md` — operação, reconciliação, sincronização do diretório, e-mail, relatórios, retenção, rotação de segredos, backup e rollback

## Limites desta entrega

Contratos AX reais, autenticação de fornecedores externos, registro no tenant Entra (login e app de sincronização com admin consent), provedor de e-mail corporativo, aprovação da licença do Carbone, SLAs/calendário e prazos de retenção dependem de decisões listadas em `docs/pendencias-validacao.md`. Nesta entrega Entra/Graph, SMTP corporativo e Carbone foram exercitados apenas com mocks, Mailpit e testes de contrato. Compose em servidor único é para desenvolvimento/piloto; produção exige plataforma com HA, secrets manager, backup testado e observabilidade.
