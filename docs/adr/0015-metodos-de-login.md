# ADR-0015 — Métodos de login: Entra ID, visitante (demonstração) e SuperAdmin (emergência)

- **Status:** aceito
- **Relaciona:** ADR-0004 (identidade Entra), ADR-0013 (login × sincronização), ADR-0014 (identidade visual)
- **Guia operacional:** `.claude/login/INSTRUCOES_DE_LOGIN.md`

## Contexto

A tela de login passou a seguir o protótipo `.claude/login/index.html` (painel de acesso + foto). O produto precisa
de três formas de entrada: o login corporativo Microsoft Entra ID, um acesso de **visitante** ao ambiente de
demonstração e um acesso **SuperAdmin** por usuário e senha. Antes, o modo era único e fixado no build
(`VITE_AUTH_MODE`) e no driver da API (`BPM_AUTH_DRIVER=dev|entra`).

O documento de assets do protótipo recomendava "não incluir login local por senha enquanto a decisão do produto
for autenticação corporativa Entra ID". A decisão do produto mudou (pedido explícito de acesso SuperAdmin); por
isso o login local é tratado como **acesso de emergência (break-glass)**, desabilitado por padrão e com controles
compensatórios.

## Decisão

1. **Métodos simultâneos, decididos pela plataforma (env), não pela tela.** `App\Auth\AuthMethods` informa o que
   está disponível para a requisição; `GET /public/auth-config` devolve `methods {entra, demo, demoUrl, superadmin}`.
   O SPA apenas reflete; a API aceita/recusa cada token.
2. **Resolução por formato do token, restrita à origem da conta** (`users.auth_source = entra | demo | superadmin`):
   `dev:<username>` → somente contas fictícias (nunca SuperAdmin/Entra); `bpmsa_…` → sessão SuperAdmin;
   demais → access token Entra (JWKS). Entra passa a ser aceito sempre que houver configuração (ativada na tela ou
   do ambiente), inclusive em ambientes de demonstração.
3. **Visitante** = usuários fictícios do seed. Habilitado por `BPM_AUTH_DRIVER=dev` (legado) ou
   `BPM_DEMO_LOGIN_ENABLED=true` e incluído no SPA só em builds `VITE_AUTH_MODE=dev`. **Proibido em produção**
   (API recusa iniciar; build recusa). Em produção o link pode apontar para um ambiente de demonstração
   **separado** (`BPM_DEMO_URL`, somente http/https).
4. **SuperAdmin (break-glass):**
   - Desabilitado por padrão (`BPM_SUPERADMIN_LOGIN_ENABLED`); em produção exige `BPM_SUPERADMIN_ALLOWED_IPS`
     (a API recusa iniciar sem a lista). Fora da lista, o método não aparece e o endpoint responde 404.
   - Senha definida **somente** por `php artisan bpm:superadmin set-password` (interativo oculto, `--stdin`,
     `--password-file` ou `--generate`), nunca por API/tela/.env/argumento. Política: ≥ 14 caracteres, 3 classes,
     sem o nome de usuário. Hash Argon2id (`local_credentials`); nunca logada ou auditada.
   - Tentativas: limite por usuário+IP (5/15 min) e por IP (20/15 min) → 429; 10 falhas consecutivas → bloqueio
     de 30 min; resposta **genérica** para usuário inexistente/senha errada/bloqueio/inatividade; verificação
     contra hash fictício quando a conta não existe (tempo constante).
   - Sessão: token opaco aleatório (32 bytes), somente o SHA-256 em `auth_sessions`; expiração absoluta (4 h) e
     por inatividade (30 min); máximo 3 sessões; revogada no logout, na troca de senha, na desativação, no
     bloqueio e quando o método é desabilitado. Bearer (não cookie) — sem CSRF; guardado em `sessionStorage`
     da aba, como o cache do MSAL.
   - Papéis administrativos fixos em configuração (`Bpm.Admin`, `Designer`, `Publisher`, `ProcessAdmin`,
     `Reporter`, `Auditor`); **sem** `Bpm.SensitiveDataReader`. Faixa permanente na interface durante a sessão.
   - Auditoria: `auth.login` (method), `auth.login_failed`, `auth.superadmin_locked`, `auth.logout`,
     `auth.superadmin_password_set|unlocked|disabled`, `auth.sessions_revoked`. Tentativas não autenticadas têm
     ator de sistema "tentativa de login (não autenticado)"; o usuário digitado só é gravado se for o SuperAdmin.
5. **Tela de login:** layout do protótipo com tokens da identidade visual (cores, logo, subtítulo), links
   institucionais configuráveis (`branding.links.support|privacy|help`, somente `https://`/`mailto:`), fontes
   Inter/Manrope **auto-hospedadas** (OFL-1.1, pacotes `@fontsource-variable/*` — sem Google Fonts em runtime, CSP
   inalterada) e foto Unsplash redimensionada localmente com crédito visível. Redirecionamento pós-login só para
   caminhos internos.

## Consequências

- O mesmo ambiente pode oferecer Entra + visitante + SuperAdmin; produção oferece Entra (+ SuperAdmin restrito).
- A conta SuperAdmin é uma conta local fora do ciclo de vida do Entra: exige procedimento operacional (habilitar
  temporariamente, IP restrito, trocar senha após uso, revisar auditoria) — ver guia.
- O bloqueio por falhas permite que alguém na rede permitida bloqueie temporariamente o SuperAdmin; mitigado pela
  lista de IPs e pelo comando `unlock`.
- O IP verificado depende dos proxies confiáveis; atrás de mais de um proxy, o ingress deve sobrescrever o
  `X-Forwarded-For` e, idealmente, restringir as rotas do SuperAdmin na borda.
