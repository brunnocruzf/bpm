# ADR-0004 — Identidade: Microsoft Entra ID (MSAL + JWT validado no Laravel)

- **Status:** aceito (configuração do tenant pendente — ver `docs/pendencias-validacao.md`)

## Decisão

- SPA usa **MSAL Browser** com Authorization Code + PKCE (sem client secret) e pede access token para o scope da API (`api://<api>/access_as_user`). ID token nunca é usado como bearer.
- Laravel (`App\Auth\EntraTokenValidator`) valida **assinatura (JWKS, RS256)**, issuer, audience, tenant permitido, expiração, `scp` e presença de `oid`. Rejeita algoritmo diferente (teste de "alg confusion").
- Usuário mapeado por `oid + tid` (nunca e-mail). Papéis = app roles `Bpm.*`; grupos Entra → raias via `bpm_groups.entra_group_id`. O frontend não é fonte de verdade de permissões (vêm de `/me`).
- Autorização contextual no servidor (`App\Runtime\Access`): catálogo, instância (solicitante, participante, auditor/admin), tarefa (assignee ou grupo candidato + `Bpm.TaskWorker`). Nega por padrão; IDs de outros usuários retornam 404.
- **Modo `dev`** (usuários fictícios, `Bearer dev:<username>`): somente desenvolvimento/POC. A API recusa iniciar com `BPM_AUTH_DRIVER=dev` em `APP_ENV=production`; o build de produção do SPA recusa `VITE_AUTH_MODE=dev`.

## Pendências

Registrar as aplicações SPA e API no tenant, definir app roles, grupos por raia, claim de área (`department` por padrão) e a política para fornecedores externos (ADR futura — §6.2 da especificação).

## Atualização (customizações fase 2)

- Parâmetros do login configuráveis em **Configurações → Entra ID — login** com ativação somente após descoberta OIDC e teste real de token; sincronização de usuários/grupos via Graph é uma integração **separada** (app-only, somente leitura). Detalhes em [ADR-0013](0013-entra-login-e-sincronizacao.md).
- Métodos de login simultâneos — Entra ID, visitante (o antigo modo `dev`, agora restrito a contas fictícias) e SuperAdmin de emergência (usuário e senha locais, desabilitado por padrão): ver [ADR-0015](0015-metodos-de-login.md). A trava de produção do modo `dev` continua valendo para o visitante.
- Papéis efetivos = App Roles do token ∪ mapeamentos explícitos do diretório. Novos papéis: `Bpm.ProcessAdmin`, `Bpm.Reporter`, `Bpm.SensitiveDataReader`; permissões granulares em [ADR-0014](0014-configuracoes-segredos-e-identidade-visual.md).
