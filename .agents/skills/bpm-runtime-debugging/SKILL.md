---
name: bpm-runtime-debugging
description: Diagnostica falhas entre Vue, Laravel, outbox, workers, Flowable, auditoria e integrações do BPM TMSA. Use para tarefas presas em Processando, comandos PENDING/SENDING/RECONCILIATION_REQUIRED/FAILED, divergências de projeção, notificações ou erros correlacionados.
---

# Diagnóstico de runtime do BPM

Investigue a causa antes de alterar código ou estado. Esta skill não autoriza reenvios, reconciliação, mudanças de configuração ou operações em produção.

## Referências obrigatórias

Leia os documentos relacionados ao sintoma:

- `docs/runbook.md`, seções **Saúde**, **Serviços** e **Comandos úteis**.
- `docs/adr/0001-engine-flowable-rest-e-laravel.md`: Laravel é a única API pública.
- `docs/adr/0005-consistencia-outbox-reconciliacao.md`: estados, idempotência e reconciliação.
- `docs/adr/0010-auditoria-funcional.md`: correlação, atores e redação.
- ADRs específicas de notificações, Graph, AX ou storage quando afetadas.

## Procedimento

1. Delimite organização, ambiente, instância/tarefa, horário, resultado esperado e resultado observado. Não presuma que uma stack local representa homologação ou produção.
2. Comece com leitura: `GET /api/v1/health`, `GET /api/v1/ready`, tela **Administração → Integrações e entregas**, `docker compose ps` e logs limitados de `api`, `queue-worker`, `scheduler` e `flowable-rest`.
3. Siga o fluxo completo: requisição Vue → transação Laravel → auditoria → `engine_command_outbox` → `ProcessEngineCommand` → Flowable → projeção. Para efeitos externos, continue pela fila e integração correspondentes.
4. Correlacione por `correlation_id`, `causation_id`, outbox ID, business key e IDs de instância/tarefa. Diferencie ator humano de ator de sistema.
5. Classifique o estado do outbox antes de propor ação:
   - `PENDING`/`SENDING`: verifique worker, claim, expiração e ordem por instância.
   - `RECONCILIATION_REQUIRED`: resposta ambígua; nunca repita cegamente. Reconcile primeiro por deployment name, business key ou histórico da tarefa.
   - `FAILED`: identifique o 4xx definitivo ou o limite de tentativas antes de corrigir a origem.
   - `CONFIRMED`: investigue projeção e eventos posteriores, não reenvie o comando.
6. Teste uma hipótese por vez e preserve evidências. Depois de três hipóteses refutadas, revise a modelagem do problema em vez de empilhar correções.
7. Reproduza com teste de comportamento pela API pública ou fronteira de domínio apropriada. Consulte os casos de `RuntimeTest` citados na ADR-0005 e acrescente regressão somente para a falha confirmada.

## Barreiras

- Não execute `bpm:outbox`, `bpm:reconcile`, `bpm:notifications`, reenvio manual ou alteração de estado sem autorização explícita para aquele ambiente.
- Não chame Flowable diretamente pela rede pública nem contorne a API Laravel.
- Não exponha tokens, segredos, corpos externos completos, conteúdo de arquivos ou valores de formulário. Exiba somente metadados redigidos e chaves dos campos.
- Não altere nem remova eventos de auditoria; a trilha é append-only.

## Resultado

Relate causa comprovada, evidências, alcance, hipóteses descartadas e a menor correção possível. Separe claramente diagnóstico, ação corretiva proposta e ação efetivamente executada.
