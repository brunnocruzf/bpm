# ADR-0005 — Consistência distribuída: outbox, idempotência e reconciliação

- **Status:** aceito

Laravel e Flowable **não compartilham transação ACID**. Nenhuma tela afirma atomicidade entre os dois.

## Fluxo

1. Abertura/conclusão grava, **na mesma transação local**: submissão validada, contexto de negócio, auditoria e comando em `engine_command_outbox` (`DEPLOY | START | COMPLETE`) com chave idempotente.
2. Após o commit, o job `ProcessEngineCommand` (fila `engine`) faz *claim* atômico (`PENDING/RECONCILIATION_REQUIRED/SENDING expirado → SENDING`) e envia ao Flowable.
3. Estados: `PENDING → SENDING → CONFIRMED`, ou `RECONCILIATION_REQUIRED` (timeout/5xx/conexão — **ambíguo**) ou `FAILED` (4xx definitivo ou limite de tentativas).
4. **Antes de repetir**, o processador **reconcilia**: deploy por `deploymentName`; start por `businessKey` (= id da instância TMSA); conclusão verificando se a tarefa saiu do runtime e consta concluída no histórico.
5. Comandos da mesma instância são processados em ordem. Backoff exponencial; reenvio manual em *Administração → Integrações e engine*.
6. A UI mostra "Processando" (instância `starting`, tarefa `completing`) até a confirmação.
7. Projeção (`task_projection`) sincronizada após cada confirmação, por job adiado (external worker é assíncrono no Flowable) e pelo reconciliador periódico (`bpm:reconcile`, 30 s).

## Idempotência na borda

`Idempotency-Key` obrigatória em `POST /process-instances` e aceita em `POST /tasks/{id}/complete` (mesma chave + mesmo conteúdo = mesma resposta; conteúdo diferente = 422). Concorrência em tarefas por `rowVersion`/`If-Match` e lock de linha.

## Testes

- `RuntimeTest::test_lost_start_response_is_reconciled_without_duplicating_the_instance`
- `RuntimeTest::test_lost_complete_response_is_reconciled_from_history`
- `RuntimeTest::test_start_requires_idempotency_key_and_replays_same_response`
- `RuntimeTest::test_concurrent_claim_and_double_completion_are_rejected_with_conflict`

## Atualização (customizações fase 2)

- Comandos do outbox carregam `causation_id` (evento de auditoria que os originou); o worker grava os eventos do engine como ator de sistema com o mesmo correlation ID.
- O outbox de **notificações** segue o mesmo padrão (estados, idempotência, retry/backoff, dead-letter) — [ADR-0012](0012-email-e-notificacoes.md). E-mails de "nova tarefa" só são enfileirados quando a projeção registra a tarefa criada pelo engine.
- Sincronização do diretório e relatórios usam filas próprias (`directory`, `reports`); nenhuma chamada a Graph, SMTP ou Carbone acontece dentro da requisição do usuário.
