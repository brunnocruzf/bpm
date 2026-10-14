# ADR-0012 — Provedor de e-mail, outbox de notificações e notificação por etapa

- **Status:** aceito

## Provedores

Selecionáveis em **Configurações → E-mail e notificações** (`App\Settings\Types\EmailSettings`, `App\Mail\*`):

| Provedor | Uso | Requisitos |
|---|---|---|
| `smtp` | SMTP personalizado: host, porta, STARTTLS exigido (587) ou TLS implícito (465), autenticação, usuário/senha, remetente, reply-to, timeout | senha no cofre; certificado sempre validado |
| `m365_graph` | Exchange Online via Graph `sendMail` (app-only) | permissão de aplicação `Mail.Send` + admin consent; restringir à caixa de envio (RBAC for Applications / Application Access Policy) |
| `m365_smtp_oauth` | SMTP AUTH com OAuth (XOAUTH2) em `smtp.office365.com:587` | `SMTP.SendAsApp`, service principal registrado no Exchange, SMTP AUTH habilitado na caixa |
| `mailpit` | captura local | **recusado em produção** (validação + fábrica) |
| `disabled` | notificações ficam registradas como descartadas | — |

Não há senha pessoal de mailbox. Provedores transacionais (SES, SendGrid, Mailgun, Postmark) entram como novo `MailProvider` após aprovação e instalação do transport oficial (Symfony bridge) com revisão de licença; não foram adicionados pacotes nesta entrega.

## Segurança

- **Segredos** (senha SMTP, certificado/secret do app M365) vão para o `SecretStore` (AES-256-GCM com chave dedicada `BPM_SECRETS_KEY_FILE`) ou referência `file:<nome>` (Docker secret / secret manager montado). A API devolve apenas `configured/source/updatedAt`.
- **Anti-SSRF** (`HostPolicy`): portas permitidas 25/465/587/2525; bloqueio de metadata endpoints, loopback, link-local, redes privadas/reservadas e domínios internos (`.internal`, `.local`), inclusive após resolução DNS; SMTP sem criptografia apenas para relay interno declarado pela plataforma (`BPM_MAIL_PRIVATE_RELAY_HOSTS`, não editável pela UI). Em produção: allowlist de egress no firewall/proxy (DNS rebinding).
- TLS nunca é desligado; erros são classificados em códigos sanitizados (`tls_failure`, `auth_failed`, `connection_failed`, `timeout`, `recipient_rejected`, `throttled`, `consent_missing`...).
- **Testes explícitos**: "Testar conexão" (SMTP: conexão + STARTTLS + AUTH; M365: token e permissão no token) e "Enviar teste" (pela fila, com estado, duração, ID no provedor). Salvar nunca executa teste.

## Outbox de notificações

`notification_outbox`: `queued → sending → sent | retrying | failed (dead-letter) | skipped`, com `idempotency_key` única, tentativas, data da última tentativa, provedor, `provider_message_id`, código de erro sanitizado, `correlation_id`/`causation_id`. Envio só pelo job `SendNotification` (fila `notifications`) com claim atômico; backoff exponencial (1, 2, 4... até 60 min) para falhas transitórias; falha permanente ou tentativas esgotadas vão para `failed`, consultável e reprocessável (`processes.manage`). Varredura do agendador (`bpm:notifications`) recupera jobs perdidos. `Message-ID` determinístico por chave de idempotência. Cada transição gera evento de auditoria.

## Notificação por etapa

Opção da etapa de **destino**: "Enviar e-mail ao responsável quando esta etapa receber uma nova tarefa" (`config.notifyAssignee`, desligada por padrão).

- **Quando:** somente quando o `ProjectionSync` cria a tarefa a partir das tarefas ativas do engine — ou seja, depois da transição BPMN confirmada/reconciliada. Em gateway, apenas a etapa realmente ativada. A etapa de abertura não notifica (é preenchida pelo solicitante). Enfileirada na mesma transação da projeção.
- **Para quem:** responsável efetivo — assignee, ou grupo candidato com política `members` (membros ativos com papel TaskWorker e e-mail, deduplicados) ou `group_mailbox` (caixa do grupo configurada pelo administrador). Nunca endereços vindos de formulário. Sem destinatário elegível: mensagem `skipped` (`no_recipient`), tarefa intacta na inbox.
- **Reatribuição:** opção `onReassign` avisa o novo responsável; a chave `task.assigned:{instância}:{tarefa}:{destinatário}` impede duplicidade.
- **Template fechado:** placeholders `{{organizacao}} {{fluxo}} {{protocolo}} {{etapa}} {{grupo}} {{responsavel}} {{prazo}} {{link}}`; texto puro, sem PHP/JS/Liquid/expressões; `{{link}}` obrigatório; HTML gerado com escape total. O e-mail não inclui valores do formulário nem anexos. Pré-visualização para designers; destinatários e envio de teste apenas para administradores.
- Exportado/importado no BPMN como extensão `tmsa:` (atributos `notifyAssignee`, `notifyPolicy`, `notifyOnReassign` e elementos `tmsa:mailSubject`/`tmsa:mailBody`), ignorada pelo Flowable.

## Consequências

Falha de e-mail nunca desfaz a tarefa nem bloqueia a submissão. O Mailpit sai do Compose padrão e fica no perfil `dev/test`, sem rota de saída (rede interna).
