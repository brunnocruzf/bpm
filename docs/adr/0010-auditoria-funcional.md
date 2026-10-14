# ADR-0010 — Trilha de auditoria funcional (append-only, tipada e correlacionada)

- **Status:** aceito
- **Contexto:** a customização exige trilha pesquisável, separada dos logs técnicos, com diff redigido, correlação entre Laravel, Flowable, Graph, AX, fila/outbox, notificações e relatórios, e controles contra alteração/apagamento.

## Decisão

- **Serviço central** `App\Audit\AuditService` (atalho `AuditLogger::record`). Todo evento usa um nome estável do **catálogo tipado** `App\Audit\AuditCatalog` (categoria + rótulo); um teste de arquitetura falha se o código gravar evento fora do catálogo.
- **Modelo** (`audit_event`, colunas novas anuláveis): organização, categoria, resultado (`success|failure|blocked`), ator humano (`actor_id`, Entra `oid`, UPN/nome capturados) **ou** ator de sistema identificado (`AuditContext`: worker do outbox, sincronização, notificações — nunca finge usuário), objeto (tipo/ID/nome), contexto BPM (`flow_key`, versão, protocolo, `processInstanceId` do Flowable, etapa, tarefa, schema), `correlation_id`, `causation_id`, idempotency key, outbox ID, operação HTTP e IP (configurável; user agent desligado por padrão), `data` (metadados redigidos) e `diff` estruturado.
- **Correlação:** o correlation ID da requisição viaja com o comando do outbox e com a mensagem de e-mail; o evento que enfileira (`task.completed`, `instance.created`, `version.published`) vira `causation_id` do comando e dos eventos confirmados pelo engine. Eventos vindos do engine são gravados pelo `ProjectionSync`/`EngineCommandProcessor`, idempotentes por natureza (projeção só cria o que ainda não existe). Não há atomicidade distribuída: o evento do engine só é gravado após a confirmação/reconciliação.
- **Na mesma transação local** do domínio (submissão, comando, outbox, configuração).
- **Redação central** (`Masker`): por chave (senha, token, secret, credential, license, deltaLink, PII de contato) e por padrão de valor (JWT, `Bearer`, PEM, credencial em URL, `$deltatoken`). `AuditDiff` registra só "alterado" para chaves sensíveis. Nunca: tokens, segredos, Authorization, conteúdo de arquivo, corpos externos completos, valores de formulário (só chaves de campos).
- **Append-only em duas camadas:** o model recusa update/delete e um **trigger** no PostgreSQL (e no SQLite dos testes) recusa `UPDATE`, `DELETE` e `TRUNCATE`. A única exceção é o expurgo de retenção, que abre o guarda (`audit_purge_guard`) dentro da própria transação após arquivar.
- **Retenção:** nenhum prazo legal é inventado. Sem prazo aprovado (referência + aprovador), nada é expurgado. Com prazo, `bpm:audit-retention` (diário) exporta lotes para JSONL gzip + manifesto SHA-256 no storage, confere o arquivo e só então expurga, registrando `audit.retention_purged`.
- **Acesso:** `audit.read` (consulta, detalhe, linha do tempo correlacionada) e `audit.export` (CSV/JSON, limitado, com evento `audit.exported` gravado antes do envio) — permissões separadas; não existem rotas de alteração/exclusão.
- **Migração sem travar:** colunas anuláveis (metadata-only no PostgreSQL) e índices `CREATE INDEX CONCURRENTLY` (migração fora de transação).

## Alternativas consideradas

- **Encadeamento criptográfico (hash-chain):** não adotado agora. Exige serialização canônica, ordem global de escrita (gargalo/lock), rotação e verificação periódica, e não substitui controle de acesso, backup nem armazenamento imutável (WORM). Recomendado como evolução junto do arquivamento em storage com Object Lock.
- **Logs técnicos (Monolog/Pail) como trilha:** recusado — misturam debug/stack trace e não têm garantias de retenção/consulta.

## Consequências

Volume maior de eventos (notificações e confirmações do engine geram eventos próprios); índices por período, categoria, ator, protocolo e correlação cobrem as consultas da tela. Backup/arquivamento imutável e retenção do arquivo exportado são responsabilidade da infraestrutura (runbook).
