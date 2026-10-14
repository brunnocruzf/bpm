# ADR-0001 — Laravel como API de produto e Flowable REST como engine BPMN separado

- **Status:** aceito (POC validada em 13/10/2026)
- **Contexto:** especificação "BPMN TMSA — Arquitetura e plano" §3.1. Opções avaliadas: n8n (licença Sustainable Use restringe uso como produto), ProcessMaker (BPM pronto, mas deployment/licença comercial a confirmar), Spring Boot com Flowable embutido (transação única, porém stack Java para todo o produto), Laravel + Flowable REST.

## Decisão

- **Laravel** é a única API pública: catálogo, usuários/papéis, autorização contextual, formulários e submissões, versionamento, auditoria, integrações AX, inbox/projeções.
- **Flowable Open Source REST 7.2.0** executa BPMN (tarefas humanas, gateways, timers, external worker) em container próprio, **somente na rede interna** (`backend`, `internal: true`). Nenhuma rota do proxy aponta para o engine.
- Laravel acessa o Flowable com **credencial técnica** (basic auth) mantida em secret; identidade do usuário final nunca é repassada como credencial ao engine.
- Laravel **nunca** faz DML nas tabelas do engine (bancos e roles PostgreSQL distintos).

## Validação na POC

Contra o container real (ver `apps/api/tests/Integration/Po159EndToEndTest.php`): deploy de BPMN, start com `businessKey`, `candidateGroups`, `dueDate` ISO, gateways com `vars:equals/isEmpty/greaterThanOrEquals/contains`, external worker (`external-job-api`), timer por data, paralelismo com sincronização, histórico (`endActivityId`) e busca por `businessKey`/`deploymentName` para reconciliação.

Achados registrados:
- `deploymentName` só é aceito como **query string** no upload multipart do Flowable 7.2.
- Literais JUEL escapam com `\\` e `\'` (não `''`).
- A conclusão de job de external worker continua o processo **de forma assíncrona** → sincronização adiada + reconciliador.
- `actuator/health` exige autenticação.

## Consequências

- Dois runtimes (PHP e Java) para operar; sem transação ACID entre Laravel e Flowable → ADR-0005 (outbox/reconciliação).
- Não se afirma equivalência funcional com o SoftExpert Workflows.
