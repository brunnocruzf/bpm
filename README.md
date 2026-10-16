# BPM TMSA

Sistema corporativo de gestão de processos (BPM) para substituir o módulo Workflows do SoftExpert no escopo da TMSA. O usuário de negócio monta fluxos **por etapas**, cada tarefa humana tem seu **formulário**, e o sistema gera e executa **BPMN 2.0** com trilha de auditoria, controle de acesso e integrações rastreáveis.

Primeiro fluxo: **PO 159 — Item Reprovado no Recebimento** (publicado pelo seed).

Especificação e protótipo de origem: `.claude/BPMN_TMSA_Arquitetura_e_Plano_Claude_Code (1).md` e `.claude/BPMN_TMSA_Prototipo_Editor.html`. A pasta `.claude/` é material local de referência e não é versionada.

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

## Guia completo: clonar, implantar com Docker e validar o ambiente

Este procedimento prepara uma instalação limpa do BPM TMSA em Docker, desde o clone do Git até a validação dos serviços. O Compose deste repositório é destinado a **desenvolvimento, demonstração e POC em servidor único**; ele não fornece alta disponibilidade nem substitui a arquitetura corporativa de produção.

### 1. Pré-requisitos

- Acesso ao repositório Git e permissão para cloná-lo.
- Docker Desktop (Windows/macOS) ou Docker Engine (Linux), com Docker Compose v2 habilitado e usando containers Linux.
- No Windows, Git for Windows com **Git Bash** ou WSL2, necessário para executar o script que gera o Docker secret local.
- Conexão à internet na primeira compilação, para baixar imagens e dependências.
- Portas `8080` e `8025` livres. A porta `8080` serve a aplicação e a `8025` a interface de e-mail de desenvolvimento (Mailpit).
- Espaço em disco para imagens e volumes persistentes. No Docker Desktop, reserve memória suficiente para compilar e executar PostgreSQL, Flowable, API, worker, storage e frontend (recomendado: pelo menos 6 GB para Docker).

Não é necessário instalar Node.js, PHP, Composer ou PostgreSQL no computador para compilar e executar o ambiente Docker; os builds usam imagens de containers.

### 2. Clonar o projeto

Use a URL Git fornecida pela equipe. Os exemplos abaixo assumem que o diretório local se chamará `bpmn`.

**PowerShell:**

```powershell
git clone <URL_DO_REPOSITORIO> bpmn
Set-Location .\bpmn
```

**Linux, macOS, Git Bash ou WSL:**

```bash
git clone <URL_DO_REPOSITORIO> bpmn
cd bpmn
```

Se for necessário usar uma branch específica, entre no repositório e selecione a branch indicada pela equipe:

```bash
git switch <branch>
```

Confira se está na raiz do projeto (onde ficam `README.md`, `apps/` e `deploy/`) e entre na pasta do Compose:

```bash
cd deploy/docker
```

No PowerShell, o comando equivalente é `Set-Location .\deploy\docker`.

### 3. Configurar o ambiente e os segredos locais

1. Crie o arquivo de ambiente a partir do modelo:

   - PowerShell: `Copy-Item .env.example .env`
   - Linux/macOS/Git Bash/WSL: `cp .env.example .env`

2. Gere uma chave Laravel exclusiva. A partir da pasta `deploy/docker`, execute:

   ```powershell
   docker run --rm php:8.4-cli-alpine php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
   ```

   Copie o valor `base64:...` impresso e substitua o valor de exemplo de `APP_KEY` no arquivo `.env`.

3. Abra `.env` em um editor de texto e substitua **todos** os valores de senha de exemplo por senhas fortes, únicas e não reutilizadas: `POSTGRES_SUPERUSER_PASSWORD`, `BPM_APP_DB_PASSWORD`, `FLOWABLE_DB_PASSWORD`, `FLOWABLE_REST_PASSWORD` e `STORAGE_SECRET_KEY`. Não coloque senhas reais neste README, no Git ou em chamados/logs.

4. Para a demonstração local, mantenha os valores compatíveis com o perfil de desenvolvimento, em particular `APP_ENV=local`, `BPM_AUTH_DRIVER=dev`, `WEB_BUILD_MODE=docker-dev`, `SEED_ON_START=true` e `COMPOSE_PROFILES=dev`. Mantenha `BPM_HTTP_BIND=127.0.0.1` se o acesso for somente pelo próprio computador. O modelo usa a porta `8080` para a aplicação e `8025` para o Mailpit.

5. Gere o arquivo local do cofre de segredos. Execute a partir de `deploy/docker` usando Git Bash, WSL ou outro shell POSIX:

   ```bash
   sh secrets/generate.sh
   ```

   O script cria `secrets/bpm_secrets_key` se ele ainda não existir. Esse arquivo é ignorado pelo Git e não deve ser apagado nem substituído sem seguir o procedimento de rotação do [runbook](docs/runbook.md); perder essa chave pode tornar ilegíveis os segredos guardados pela aplicação.

6. Confirme que os arquivos locais não serão versionados. Na raiz do repositório, `git status --short` não deve listar `deploy/docker/.env` nem `deploy/docker/secrets/bpm_secrets_key`.

> O perfil de demonstração inicializa usuários e dados fictícios. Use somente dados fictícios nesse ambiente. Não reutilize essa configuração como produção.

### 4. Conferir a configuração e implantar

Ainda em `deploy/docker`, valide a configuração resolvida do Compose. O comando deve terminar sem erro:

```bash
docker compose config --quiet
```

Confira quais serviços serão executados e faça o deploy, compilando as imagens necessárias:

```bash
docker compose config --services
docker compose up --build -d
```

O Compose inicia PostgreSQL, Flowable, storage, API, servidor web, proxy reverso, worker e scheduler. O perfil `dev` também inicia o Mailpit. A API executa as migrations na inicialização; com `SEED_ON_START=true`, carrega os dados de demonstração e o fluxo PO 159.

Veja o estado dos containers:

```bash
docker compose ps
```

Na primeira inicialização, aguarde alguns minutos. PostgreSQL, Flowable e API devem chegar ao estado `healthy`; os demais serviços devem estar `running`. O backend só fica pronto depois que o banco e o engine BPMN estiverem disponíveis.

### 5. Validar os serviços por HTTP

Execute estes comandos em PowerShell ou Windows Terminal:

```powershell
Invoke-RestMethod http://localhost:8080/api/v1/health | ConvertTo-Json
Invoke-RestMethod http://localhost:8080/api/v1/ready | ConvertTo-Json -Depth 5
(Invoke-WebRequest -Uri http://localhost:8080/ -UseBasicParsing).StatusCode
(Invoke-WebRequest -Uri http://localhost:8080/admin/groups -UseBasicParsing).StatusCode
(Invoke-WebRequest -Uri http://localhost:8080/api/v1/openapi.yaml -UseBasicParsing).StatusCode
```

No Linux/macOS/Git Bash, use `curl -i` com as mesmas URLs. Resultados esperados:

- `/api/v1/health`: HTTP 200 e `status: UP` (processo da API ativo).
- `/api/v1/ready`: HTTP 200, `status: UP`, `database: true` e `engine: true` (banco e Flowable acessíveis pela API).
- `/`, `/admin/groups` e `/api/v1/openapi.yaml`: HTTP 200.

`health` verifica liveness; para confirmar dependências, use também `ready`. O Flowable, PostgreSQL e storage não devem precisar de portas públicas: somente o proxy reverso publica a aplicação. A UI do Mailpit fica em <http://localhost:8025> no perfil de desenvolvimento.

### 6. Validar o ambiente funcionalmente

1. Abra <http://localhost:8080/login> ou <http://localhost:8080/login/visitante> e escolha **Acessar como visitante**. Entre com **Ana Administradora** para verificar áreas administrativas. Os perfis fictícios e seus usos estão descritos na tabela de demonstração acima.
2. Acesse **Administração → Usuários e grupos** e confirme que a página carrega. Verifique as abas de usuários, grupos/raias e papéis. A aba de usuários tem filtros à esquerda, a lista no centro e, ao selecionar alguém, o painel com origem, integração (Entra ID ou conta local), acesso efetivo e atividade. Os dados iniciais aparecem quando `SEED_ON_START=true`.
3. Para validar o engine e o worker, entre como **Carla — CQ** (ou outro perfil de operação), inicie o fluxo de demonstração **PO 159 — Item Reprovado no Recebimento**, envie uma solicitação fictícia e confirme que a tarefa aparece na caixa de tarefas da raia responsável. Use somente valores de teste; as consultas ao AX neste ambiente são simuladas.
4. Para validar e-mail local, entre como administradora, use a opção de teste de e-mail nas configurações e confira a mensagem em <http://localhost:8025>. O Mailpit captura mensagens e não as envia para destinatários externos.
5. Confira worker e scheduler e investigue erros repetidos:

   ```bash
   docker compose logs --tail=100 api api-web web queue-worker scheduler
   ```

6. Confirme novamente `docker compose ps` e consulte `/api/v1/ready`. Para detalhes de operação, backup, sincronização, logs e rollback, siga o [runbook](docs/runbook.md).

### 7. Configurar o SuperAdmin local (opcional)

No modelo local, o método SuperAdmin está habilitado. Defina uma senha interativamente, sem colocá-la em linha de comando nem no `.env`:

```bash
docker compose exec api php artisan bpm:superadmin set-password
```

Depois, entre em <http://localhost:8080/login/superadmin>. Consulte o guia de login (`.claude/login/INSTRUCOES_DE_LOGIN.md`, local) para políticas, operações e uso seguro. O visitante é apenas para demonstração; não o habilite em produção.

### 8. Atualizar, parar e preservar dados

Para atualizar a cópia clonada, faça backup conforme o runbook, atualize a branch e reconstrua os serviços:

```bash
# a partir da raiz do repositório
git pull --ff-only
cd deploy/docker
docker compose up --build -d
docker compose ps
```

Para parar o ambiente sem apagar os dados persistentes:

```bash
docker compose down
```

**Não use `docker compose down -v` para uma parada normal:** a opção `-v` remove volumes, inclusive banco de dados e arquivos do storage. A restauração de backup e a remoção deliberada de dados devem seguir um procedimento aprovado.

### 9. Nota sobre produção

Este Compose é um ambiente de desenvolvimento/POC em servidor único. Antes de qualquer produção, a equipe de plataforma deve definir HTTPS e proxy confiável, domínio e URLs públicas, credenciais em secret manager, login Entra, `APP_ENV=production`, `WEB_BUILD_MODE=production`, visitante desativado, restrições de IP do SuperAdmin, backup/restauração testados, observabilidade, firewall/egress e estratégia de alta disponibilidade. Não exponha diretamente as portas internas nem reutilize senhas, dados fictícios ou chaves do ambiente local. Os requisitos e procedimentos operacionais estão no [runbook](docs/runbook.md) e em Instruções de login (`.claude/login/INSTRUCOES_DE_LOGIN.md`, local).

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
| Usuários e grupos (Graph) | Transporte "Diretório fictício" + "Usar grupos do diretório fictício" → Prévia → Sincronizar | App de sincronização separado, certificado montado (`file:<nome>`), grupos de teste, prévia antes da 1ª carga; fotos de perfil importadas por padrão (desligável) com a mesma permissão `User.Read.All` | idem com grupos reais; periodicidade; mapeamentos revisados | Sim: `User.Read.All` + `Group.Read.All` (Application) com **admin consent** |
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
