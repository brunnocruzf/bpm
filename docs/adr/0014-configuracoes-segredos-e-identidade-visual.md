# ADR-0014 — Configurações, segredos, permissões e identidade visual

- **Status:** aceito

## Configurações tipadas e versionadas

- `app_setting` (uma linha por seção: `branding`, `entra.auth`, `entra.directory`, `email`, `audit.retention`, `reports`) guarda **somente valores não secretos**, `version`, estado (`configured | incomplete | test_pending | error | disabled`), detalhe, última validação sanitizada e quem alterou. Cada gravação gera `app_setting_revision` (valor redigido) e evento `settings.updated` com diff.
- Cada seção é um `SettingType` (validação server-side, normalização, estado calculado, apresentação sem segredos). Validações/testes reais gravam `last_validation` — nunca um "sucesso fixo".
- Organização **única** (TMSA); não há multi-tenancy.

## Segredos

- `SecretStore`: `db:<uuid>` criptografado com AES-256-GCM e chave dedicada (`BPM_SECRETS_KEY_FILE`, Docker secret; `BPM_SECRETS_KEY` só em dev), separada da `APP_KEY`; `key_id` permite rotação (`BPM_SECRETS_PREVIOUS_KEYS` + `php artisan bpm:secrets-rotate`). Alternativa preferida em produção: `file:<nome>` lido de `BPM_SECRETS_DIR` (secret manager/Key Vault CSI/Swarm secrets), com proteção contra path traversal.
- Segredos são **somente escrita**: a API devolve `configured/source/updatedAt`; nunca são logados ou auditados (evento `settings.secret_updated` sem valor).
- A chave em arquivo é lida em tempo de execução (não entra no cache de configuração do Laravel).

## Permissões (Gates/Policies)

`settings.manage` (admin), `processes.manage` (admin, Bpm.ProcessAdmin — reatribuir, reprocessar comandos/notificações), `audit.read` (auditor, admin), `audit.export` (auditor — separado da leitura), `reports.instance` (quem acessa a instância), `reports.operational` (Bpm.Reporter, auditor, admin, process admin), `reports.templates` (admin), `data.sensitive` (Bpm.SensitiveDataReader). Definidas em `config/bpm.php`, registradas em `App\Authorization\Permissions`; `AuditEventPolicy` e `ReportJobPolicy` para recursos. O menu do SPA apenas reflete `/me`.

## Identidade visual

- Nome, subtítulo, logos (principal, compacto, favicon) e cores primária/secundária/destaque. Cores de estado (sucesso/alerta/erro) **não** são configuráveis.
- **Contraste WCAG** validado no servidor e na UI (`App\Branding\Contrast` ⇄ `composables/contrast.ts`). Cores claras (ex.: laranja `#EE753F`) são aceitas porque o sistema **deriva** os tons de apoio: texto sobre a primária/secundária = branco ou tinta escura `#172230`, o que tiver mais contraste (≥ 4,5:1); texto/links/títulos sobre branco = menor escurecimento da primária com ≥ 5:1 (margem para fundos tingidos); hover do botão clareia quando o texto é escuro e escurece quando é branco. Recusa apenas tons médios em que nem branco nem tinta escura atingem 4,5:1 (ex.: `#808080`) e destaque < 3:1 sobre branco (anel de foco/indicadores, WCAG 1.4.11).
- **Tokens CSS** centralizados (`--brand`, `--brand-2`, `--accent`, derivados `--on-brand`, `--on-brand-2`, `--brand-text`, `--brand-hover` e tintas por `color-mix`), paleta e `contrastColor` do PrimeVue atualizados em runtime, `:focus-visible` global com a cor de destaque. O PDF de relatórios usa o tom derivado para títulos. Pré-visualização em escopo isolado (cabeçalho claro/escuro, login) antes de aplicar; "Restaurar tema padrão".
- **Favicon `.ico`:** aceito somente no favicon. `App\Branding\IcoCodec` lê o contêiner de forma estrita (cabeçalho, até 16 frames, offsets dentro do arquivo, frames PNG ou BMP/DIB sem compressão de 1/4/8/24/32 bits com máscara AND, até 256 px) e **regrava** um ICO novo só com frames PNG (16, 32, 48 px e o tamanho de origem, sem ampliar) — nada do arquivo enviado é servido. Limite de entrada 512 KB (ICOs multi-tamanho em BMP são grandes; o regravado fica pequeno). Servido como `image/x-icon`.
- **Upload seguro:** logos somente PNG/WebP (SVG recusado — sem sanitizador de SVG no MVP), conferência por conteúdo (finfo + decodificação), limites de tamanho/dimensões/proporção, antivírus quando configurado e **recodificação via GD** (remove metadados e conteúdo anexado). Armazenado no storage adotado com chave aleatória; servido pelo domínio da aplicação com `nosniff` e CSP restritiva; somente a marca aplicada é pública (rascunhos exigem autenticação); URLs versionadas por hash.

## Logs das integrações (atualização)

- Cada validação/teste passa a ser **histórico** (`setting_validation`, redigido), além da "última validação" em `app_setting`.
- As execuções da importação gravam **log por etapa** (`directory_sync_run.log`, até 400 linhas, só contagens/códigos/mensagens sanitizadas) e cada entrega de e-mail grava **log por tentativa** (`notification_outbox.attempt_log`, últimas 30).
- Tokens Entra recusados pela API geram `auth.token_rejected` (motivo + claims públicas não verificadas; deduplicado por token e limitado a 30/min) — sem o token.
- `App\Settings\IntegrationLog` monta a linha do tempo por área (e-mail | Entra) com paginação exata entre fontes; `App\Support\IntegrationErrors` explica cada código (com variações por contexto). Somente `settings.manage`.

## Consequências

A imagem da API passa a incluir a extensão GD (PNG/WebP/JPEG). Toda alteração de marca, credencial e permissão fica auditada com ator, data e diff seguro.
