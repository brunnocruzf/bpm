# BPM TMSA — Instruções de login (todas as formas de acesso)

Este guia acompanha o protótipo `index.html` desta pasta e descreve **como entrar no BPM TMSA** por cada método
disponível, como habilitar/desabilitar cada um e como operá-los com segurança. A decisão de arquitetura está em
`docs/adr/0015-metodos-de-login.md`.

> **Nenhuma senha, token ou segredo é registrado neste documento.** Senhas do SuperAdmin são definidas somente
> pelo comando `bpm:superadmin` e ficam guardadas apenas como hash (Argon2id) no banco.

---

## 1. Visão geral

| Método | Onde fica na tela | Para quem | Credencial | Disponível em produção? |
|---|---|---|---|---|
| **Microsoft Entra ID** (principal) | Botão **Entrar com Microsoft** em `/login` | Todos os colaboradores | Conta corporativa Microsoft (senha/MFA validados pela Microsoft) | **Sim** (único método do dia a dia) |
| **Visitante** — ambiente de demonstração | Link **Acessar como visitante** → `/login/visitante` | Demonstrações, treinamento, homologação funcional | Nenhuma: escolhe-se um **perfil fictício** | **Não** no mesmo ambiente (a API recusa iniciar). Em produção o link pode apontar para um ambiente de demonstração **separado** (`BPM_DEMO_URL`) |
| **SuperAdmin** — acesso de emergência | Link **Acesso SuperAdmin** → `/login/superadmin` | Equipe de TI, quando o Entra estiver indisponível/mal configurado | Usuário e senha locais | Sim, **somente** se habilitado explicitamente e com lista de IPs permitidos |
| Acesso programático (API/testes) | — | Desenvolvimento, testes automatizados, integrações de teste | `Authorization: Bearer <token>` | Somente tokens Entra |

Regras gerais:

- A tela **só mostra** os métodos que o servidor informa em `GET /api/v1/public/auth-config`. Quem aceita ou
  recusa cada token é sempre a API (o navegador não é fonte de verdade de papéis/permissões).
- Cada método só autentica contas da sua origem: um token de visitante (`dev:`) nunca entra como SuperAdmin ou
  como usuário do Entra; a senha do SuperAdmin nunca vale para outra conta.
- Toda entrada, saída, tentativa recusada, bloqueio e troca de senha fica na **Auditoria** (categoria
  *Identidade e acesso*).
- Identidade visual (logo, cores, subtítulo) e os links **Fale com o suporte / Privacidade / Ajuda** vêm de
  **Configurações → Organização e identidade visual** (os links só aparecem quando preenchidos, aceitam `https://`
  ou `mailto:`).

### Endereços

| Tela | URL (ambiente local Docker) |
|---|---|
| Login principal | <http://localhost:8080/login> |
| Visitante | <http://localhost:8080/login/visitante> |
| SuperAdmin | <http://localhost:8080/login/superadmin> |

---

## 2. Microsoft Entra ID (login corporativo)

### 2.1 Como entrar

1. Abra `/login` e clique em **Entrar com Microsoft**.
2. Você é redirecionado para `login.microsoftonline.com`, entra com a conta corporativa (e MFA, se exigido).
3. A Microsoft devolve você ao BPM TMSA já autenticado. Seus papéis vêm dos **App Roles `Bpm.*`** do token e dos
   **mapeamentos de grupos** feitos na sincronização do diretório.
4. Para sair: avatar (canto superior direito) → **Sair** (encerra também a sessão Microsoft deste aplicativo).

O SPA usa MSAL (Authorization Code + PKCE), pede um **access token** para o scope da API (nunca usa o ID token como
bearer) e não possui client secret. O Laravel valida assinatura (JWKS), emissor, audience, tenant, expiração e
scope.

### 2.2 Pré-requisitos no tenant (feitos pela TI — o produto não cria App Registrations)

1. **App Registration da API**: *Expose an API* com o scope `access_as_user` (ex.: `api://<client-id-da-api>/access_as_user`)
   e os **App Roles** `Bpm.Admin`, `Bpm.Designer`, `Bpm.Publisher`, `Bpm.Initiator`, `Bpm.TaskWorker`,
   `Bpm.Auditor`, `Bpm.ProcessAdmin`, `Bpm.Reporter`, `Bpm.SensitiveDataReader` (atribua-os a usuários/grupos).
2. **App Registration da SPA**: plataforma **Single-page application** com Redirect URI igual à origem do BPM
   (ex.: `http://localhost:8080` no ambiente local; `https://bpm.<empresa>` em produção) e permissão delegada para
   o scope da API (com consentimento).
3. (Opcional) claim de grupos no token e claim de área (`department` por padrão).

### 2.3 Como configurar no BPM (escolha uma opção)

**Opção A — pela tela (recomendada):** entre como administrador (SuperAdmin ou usuário com `Bpm.Admin`) →
**Configurações → Entra ID — login**:

1. Preencha tenant, client ID da SPA, Redirect URIs, audience e scope da API.
2. **Validar descoberta** (OIDC real do tenant).
3. **Testar login** (abre um popup da Microsoft e valida um token real contra a configuração candidata).
4. **Ativar**. A partir daí o botão **Entrar com Microsoft** usa esses parâmetros. **Desativar** volta à
   configuração do ambiente.

**Opção B — pelo ambiente:** API com `BPM_ENTRA_TENANT_ID` e `BPM_ENTRA_AUDIENCE` (ver `deploy/docker/.env`) e
build do SPA com `VITE_ENTRA_CLIENT_ID`, `VITE_ENTRA_TENANT_ID` e `VITE_ENTRA_API_SCOPE`
(`apps/web/.env.production`, valores públicos — não são segredos).

> No ambiente local atual já existe uma configuração Entra **ativada** pela tela, por isso o botão aparece
> habilitado. Se nenhuma configuração existir, o botão fica desabilitado com a mensagem
> "Login Microsoft ainda não configurado neste ambiente" — use o SuperAdmin para configurá-lo.

### 2.4 Problemas comuns

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| Botão **Entrar com Microsoft** desabilitado | Nenhuma configuração Entra ativada nem variáveis de build | Configurar (2.3) entrando como SuperAdmin |
| Erro `AADSTS50011` (redirect URI) | Origem do BPM não cadastrada como Redirect URI **SPA** | Cadastrar a origem exata (protocolo, host e porta) |
| Volta da Microsoft mas cai de novo no login (401) | Audience/scope/tenant do token não conferem com a configuração da API | Revisar audience/scope e rodar **Testar login** |
| Entra, mas sem menus | Usuário sem App Roles `Bpm.*` e sem mapeamento de grupo | Atribuir App Roles ou mapear o grupo em Configurações → Entra ID — diretório |
| "Acesso bloqueado" | Conta desativada (ex.: removida/desabilitada no diretório) | Reativar no Entra e sincronizar |
| Falha ao iniciar o login em `http://<servidor>` | O MSAL exige contexto seguro (HTTPS ou `localhost`) | Publicar com HTTPS |

---

## 3. Visitante — ambiente de demonstração

### 3.1 Como entrar

1. Em `/login`, clique em **Acessar como visitante** (ou abra `/login/visitante`).
2. Escolha um **perfil fictício**. Cada raia do PO 159 tem um usuário próprio:

| Perfil | Papéis / raia | Uso típico |
|---|---|---|
| Ana Administradora | Admin, Designer, Publisher, Auditor, ProcessAdmin, Reporter | Configurações, grupos, integrações |
| Gisele — Gestão de processos | ProcessAdmin, Reporter, Initiator | reatribuição, reprocessamento, relatórios |
| Diego Designer | Designer, Initiator | cria/edita fluxos e formulários |
| Paula Publicadora | Publisher, Designer | valida e publica versões |
| Augusto — Auditor | Auditor | trilha de auditoria e exportação |
| Carla — CQ Recebimento | raia CQ | abre "Item reprovado no recebimento" |
| Pedro (PPCPM), Clara (Comitê), Gabriel (GEF), Fernanda (Fornecedor), Sérgio (SUP), Fábio (Fiscal), Quésia (Qualidade), Eduardo (Expedição), Fátima (Faturamento) | raias do PO 159 | executam as etapas |

3. Para trocar de perfil: avatar → **Sair** → **Acessar como visitante** novamente.

**Os usuários e dados são fictícios — não insira informações reais.** O AX é simulado (MOCK) e os e-mails ficam
presos no Mailpit (<http://localhost:8025>).

### 3.2 Como habilitar / desabilitar

| Onde | Configuração |
|---|---|
| API | `BPM_AUTH_DRIVER=dev` (legado) **ou** `BPM_DEMO_LOGIN_ENABLED=true` |
| SPA | build `WEB_BUILD_MODE=docker-dev` (ou `npm run dev`), que usa `VITE_AUTH_MODE=dev` |
| Desabilitar | `BPM_AUTH_DRIVER=entra` e `BPM_DEMO_LOGIN_ENABLED=false` (o link some e `GET /dev/users` passa a responder 404) |

Travas de segurança:

- Com `APP_ENV=production` a **API recusa iniciar** se o visitante estiver habilitado, e o **build de produção do
  SPA recusa** `VITE_AUTH_MODE=dev`.
- Em produção, para manter o link "visitante", aponte-o para um **ambiente de demonstração separado**:
  `BPM_DEMO_URL=https://demo.bpm.<empresa>` (somente `http(s)://`). O link passa a abrir essa URL.

---

## 4. SuperAdmin — acesso de emergência (usuário e senha)

Conta local para **restabelecer o acesso** quando o Entra ID estiver indisponível ou ainda não configurado
(ex.: configurar o próprio login Entra). Não é para uso diário.

- Papéis: `Bpm.Admin`, `Bpm.Designer`, `Bpm.Publisher`, `Bpm.ProcessAdmin`, `Bpm.Reporter`, `Bpm.Auditor`
  (configurável em `config/bpm.php → auth.superadmin.roles`). **Não** recebe `Bpm.SensitiveDataReader`.
- Durante a sessão aparece uma faixa amarela fixa: *"Sessão SuperAdmin (acesso de emergência)… Expira às HH:MM"*.

### 4.1 Habilitar

No `deploy/docker/.env` (ou no secret manager do ambiente):

```env
BPM_SUPERADMIN_LOGIN_ENABLED=true
# obrigatório em produção (a API recusa iniciar sem isso): IPs/CIDRs separados por vírgula
BPM_SUPERADMIN_ALLOWED_IPS=10.20.0.0/16,192.168.10.15
```

Aplique com `docker compose up -d` (recria `api`, `queue-worker` e `scheduler`). O link **Acesso SuperAdmin** só
aparece quando o método está habilitado **e** o IP de quem acessa é permitido.

> O IP verificado é o visto pelo Laravel através dos proxies confiáveis (X-Forwarded-For do proxy de borda). Com
> mais de um proxy na frente (ingress corporativo), garanta que o ingress **sobrescreva** o X-Forwarded-For e
> restrinja também `/login/superadmin` e `/api/v1/auth/superadmin/login` na borda.

### 4.2 Criar o SuperAdmin / definir a senha

A senha **nunca** é definida pela tela, pela API, pelo `.env` ou por argumento de linha de comando (histórico do
shell). Use **uma** das formas abaixo (rodam dentro do container da API):

```bash
cd deploy/docker

# 1) Interativo (senha digitada oculta, duas vezes) — recomendado
docker compose exec api php artisan bpm:superadmin set-password

# 2) Gerar senha forte aleatória (exibida UMA única vez; guarde no cofre de senhas da organização)
docker compose exec api php artisan bpm:superadmin set-password --generate

# 3) A partir de um arquivo de secret montado no container (automação)
docker compose exec api php artisan bpm:superadmin set-password --password-file=/run/secrets/bpm_superadmin_password

# 4) Pela entrada padrão (automação; o arquivo local não é copiado para o container)
docker compose exec -T api php artisan bpm:superadmin set-password --stdin < arquivo-com-a-senha
```

Opções: `--username=<usuário>` (padrão `superadmin`; não pode coincidir com um perfil de demonstração) e
`--name="<nome exibido>"`.

Política de senha: mínimo **14** caracteres, pelo menos **3 tipos** (minúsculas, maiúsculas, números, símbolos),
sem conter o nome de usuário e com variedade de caracteres. Definir uma nova senha **revoga todas as sessões**
abertas e desbloqueia a conta.

### 4.3 Como entrar

1. Em `/login`, clique em **Acesso SuperAdmin** (ou abra `/login/superadmin`).
2. Informe **Usuário** (`superadmin`, salvo se criado com outro `--username`) e **Senha** → **Entrar**.
3. Para sair: avatar → **Sair** (a sessão é revogada no servidor).

### 4.4 Proteções

| Proteção | Comportamento (padrão) | Ajuste |
|---|---|---|
| Resposta genérica | Usuário inexistente, senha errada, conta bloqueada ou inativa recebem a mesma mensagem | — |
| Limite de tentativas | 5 tentativas por usuário+IP (e 20 por IP) em 15 min → HTTP 429 | `BPM_SUPERADMIN_MAX_ATTEMPTS` |
| Bloqueio da conta | 10 falhas consecutivas → bloqueio por 30 min (sessões revogadas) | `BPM_SUPERADMIN_LOCKOUT_THRESHOLD`, `BPM_SUPERADMIN_LOCKOUT_MINUTES` |
| Sessão | Token opaco aleatório; só o hash SHA-256 fica no banco; expira em 4 h (absoluto) ou 30 min sem uso; máx. 3 sessões simultâneas | `BPM_SUPERADMIN_SESSION_MINUTES`, `BPM_SUPERADMIN_IDLE_MINUTES` |
| Senha | Hash Argon2id (bcrypt como fallback); nunca logada nem auditada | — |
| Desabilitar o método | `BPM_SUPERADMIN_LOGIN_ENABLED=false` invalida também as sessões abertas | — |
| Auditoria | `auth.login` (method=superadmin), `auth.login_failed`, `auth.superadmin_locked`, `auth.logout`, `auth.superadmin_password_set`, `auth.superadmin_unlocked`, `auth.superadmin_disabled`, `auth.sessions_revoked` | — |

O usuário digitado numa tentativa recusada só é registrado se corresponder ao SuperAdmin (evita gravar uma senha
digitada por engano no campo de usuário); caso contrário fica apenas uma referência por hash. Tentativas não
autenticadas aparecem como ator *"tentativa de login (não autenticado)"*, nunca como o titular da conta.

### 4.5 Operação

```bash
docker compose exec api php artisan bpm:superadmin status           # situação, bloqueio, último acesso, sessões
docker compose exec api php artisan bpm:superadmin unlock           # desbloqueia após tentativas
docker compose exec api php artisan bpm:superadmin revoke-sessions  # derruba sessões abertas
docker compose exec api php artisan bpm:superadmin disable          # desativa a conta (reative com set-password)
```

### 4.6 Procedimento de emergência recomendado (produção)

1. Abrir chamado/incidente e obter autorização.
2. Habilitar `BPM_SUPERADMIN_LOGIN_ENABLED=true` com `BPM_SUPERADMIN_ALLOWED_IPS` restrito à estação/rede da TI.
3. Definir/obter a senha (cofre de senhas; ou `set-password --generate`).
4. Entrar, corrigir o problema (ex.: Configurações → Entra ID — login) e **sair**.
5. Trocar a senha (`set-password`) e/ou `disable`; voltar `BPM_SUPERADMIN_LOGIN_ENABLED=false`.
6. Revisar na **Auditoria** (Identidade e acesso + Configurações) tudo o que foi feito durante a sessão.

### 4.7 Ambiente local (Docker) desta entrega

- `BPM_SUPERADMIN_LOGIN_ENABLED=true` no `deploy/docker/.env` (sem restrição de IP, permitido fora de produção).
- O SuperAdmin `superadmin` foi criado com senha **gerada** (`--generate`), informada apenas na conversa de
  entrega. Troque-a: `docker compose exec api php artisan bpm:superadmin set-password`.

---

## 5. Acesso programático (API, testes e scripts)

Base: `http://localhost:8080/api/v1` · contrato: `GET /api/v1/openapi.yaml`.

| Método | Header | Observações |
|---|---|---|
| Entra ID | `Authorization: Bearer <access token do scope access_as_user>` | Único aceito em produção |
| Visitante | `Authorization: Bearer dev:<username>` (ex.: `dev:admin`, `dev:cq`) | Só com visitante habilitado; só perfis fictícios |
| SuperAdmin | `Authorization: Bearer bpmsa_…` | Obtido em `POST /auth/superadmin/login` |

```bash
# perfis disponíveis do visitante (404 quando desabilitado)
curl -s http://localhost:8080/api/v1/dev/users

# quem sou eu / qual método
curl -s -H "Authorization: Bearer dev:admin" http://localhost:8080/api/v1/me   # → "auth": {"method": "demo"}

# SuperAdmin: login (envie a senha por arquivo JSON, não na linha de comando), uso e saída
curl -s -H "Content-Type: application/json" --data @login.json http://localhost:8080/api/v1/auth/superadmin/login
#   login.json = {"username":"superadmin","password":"…"}  → {"token":"bpmsa_…","expiresAt":"…","idleMinutes":30,…}
curl -s -H "Authorization: Bearer bpmsa_…" http://localhost:8080/api/v1/me
curl -s -X POST -H "Authorization: Bearer bpmsa_…" http://localhost:8080/api/v1/auth/logout   # 204, revoga
```

Nos testes PHPUnit use o helper `$this->as('<username>')` (token de visitante) — `apps/api/tests/TestCase.php`.

---

## 6. Desenvolvimento local fora do Docker (vite + artisan serve)

- API (`apps/api/.env`): `BPM_AUTH_DRIVER=dev` (visitante) e, se quiser o SuperAdmin,
  `BPM_SUPERADMIN_LOGIN_ENABLED=true`; depois `php artisan migrate --seed` e
  `php artisan bpm:superadmin set-password`.
- SPA: `npm run dev` usa `apps/web/.env.development` (`VITE_AUTH_MODE=dev`). O botão Microsoft funciona se houver
  configuração Entra ativada na API ou `VITE_ENTRA_*` definidos (Redirect URI `http://localhost:5173`).

---

## 7. Checklist de produção

- [ ] `APP_ENV=production`, `BPM_AUTH_DRIVER=entra`, `BPM_DEMO_LOGIN_ENABLED=false`, `WEB_BUILD_MODE=production`.
- [ ] HTTPS na borda; Redirect URIs de produção cadastradas no App Registration da SPA.
- [ ] Login Entra validado e **ativado** (Configurações → Entra ID — login) ou `BPM_ENTRA_*`/`VITE_ENTRA_*` definidos.
- [ ] `BPM_DEMO_URL` apontando para o ambiente de demonstração separado (opcional).
- [ ] SuperAdmin **desabilitado** por padrão; quando habilitado, com `BPM_SUPERADMIN_ALLOWED_IPS` e procedimento 4.6.
- [ ] Links institucionais (suporte, privacidade, ajuda) preenchidos em Configurações → Organização e identidade visual.
- [ ] Foto do painel e logo revisados pela marca/jurídico (ver `ASSETS_E_TIPOGRAFIA.md`).

## 8. Referência de variáveis

| Variável | Padrão | Efeito |
|---|---|---|
| `BPM_AUTH_DRIVER` | `entra` (Compose: `dev`) | `dev` habilita o visitante (legado) |
| `BPM_DEMO_LOGIN_ENABLED` | `false` | Habilita o visitante (proibido em produção) |
| `BPM_DEMO_URL` | vazio | Link "visitante" para outro ambiente |
| `BPM_SUPERADMIN_LOGIN_ENABLED` | `false` (Compose local: `true`) | Habilita o SuperAdmin |
| `BPM_SUPERADMIN_ALLOWED_IPS` | vazio | IPs/CIDRs permitidos (obrigatório em produção) |
| `BPM_SUPERADMIN_SESSION_MINUTES` | `240` | Expiração absoluta da sessão |
| `BPM_SUPERADMIN_IDLE_MINUTES` | `30` | Expiração por inatividade |
| `BPM_SUPERADMIN_MAX_ATTEMPTS` | `5` | Tentativas por usuário+IP em 15 min |
| `BPM_SUPERADMIN_LOCKOUT_THRESHOLD` / `_MINUTES` | `10` / `30` | Bloqueio por falhas consecutivas |
| `BPM_ENTRA_TENANT_ID`, `BPM_ENTRA_AUDIENCE` | vazio | Entra pelo ambiente (alternativa à tela) |
| `VITE_AUTH_MODE` (build do SPA) | `entra` em produção | `dev` inclui o visitante no SPA (proibido no build de produção) |
| `VITE_ENTRA_CLIENT_ID`, `VITE_ENTRA_TENANT_ID`, `VITE_ENTRA_API_SCOPE` | — | Parâmetros públicos do MSAL quando não há configuração ativada |

## 9. Onde está no código

- API: `apps/api/app/Auth/AuthMethods.php` (métodos e travas), `SuperAdminAuth.php` (login/sessões/bloqueio),
  `PasswordPolicy.php`, `TokenUserResolver.php` (resolve o token por formato), `app/Console/Commands/SuperAdminCommand.php`,
  rotas `POST /auth/superadmin/login`, `POST /auth/logout`, `GET /public/auth-config`; testes em
  `tests/Feature/LoginMethodsTest.php`.
- SPA: `apps/web/src/views/LoginView.vue` (tela), `src/auth/index.ts` (métodos e sessão), `src/auth/msal.ts`,
  rotas `/login`, `/login/visitante`, `/login/superadmin`; imagens e créditos em `src/assets/login/`.
