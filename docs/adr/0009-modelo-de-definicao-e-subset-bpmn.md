# ADR-0009 — Modelo de definição (Fluxo → Versão → Etapas → Formulários) e subset BPMN

- **Status:** aceito

## Decisão

- `process_catalog 1:N process_definition_version 1:N process_stage_version`; transições em `process_transition_version`; `form_schema_version` 1:1 com etapa humana (FK única). Etapas são a fonte de verdade do rascunho; o **BPMN 2.0 XML é gerado** (`BpmnGenerator`, com raias e DI por layout automático) e **congelado** na publicação com hash (BPMN + hashes de formulários).
- `POST /flows` cria, em uma transação: fluxo, versão 1 rascunho, Início, "Etapa 1" (tarefa humana) com "Formulário da Etapa 1" vazio associado, Fim e as transições Início → Etapa 1 → Fim. Falha em qualquer parte reverte tudo (teste `FlowAuthoringTest`).
- Subset suportado: `startEvent` simples, `userTask` (candidateGroups = raia, formKey, dueDate por SLA), `exclusiveGateway` (condições em DSL — ADR-0003/ConditionCompiler — + caminho padrão), `parallelGateway` (divisão/sincronização), `serviceTask` external-worker com operação do catálogo, `intermediateCatchEvent` timer (duração ISO ou data de campo), `endEvent` (resultado de negócio, opcional terminate), `laneSet`.
- O modelador bpmn-js (aba Diagrama) permite edição avançada; "Aplicar edições ao rascunho" reimporta pelo `BpmnImporter`, que **recusa** scripts, listeners, classes Java/expressões, condições livres, subprocessos, eventos de borda etc., e preserva formulários pelo `element_id`. Layout é regenerado (posições manuais não são preservadas no MVP).
- Validação de publicação (`FlowValidator`): início único, fim, tarefa humana com responsável e formulário com campos, saídas/entradas, alcançabilidade e caminho até o fim, condições referenciando campos existentes e opções válidas, tipos de campo consistentes entre formulários, timers/integrações/resultados válidos.
- Publicação exige `Bpm.Publisher` (opcionalmente usuário distinto do autor — `BPM_REQUIRE_DISTINCT_PUBLISHER`). Versões publicadas são imutáveis; alterações exigem nova versão (clone). Instâncias permanecem na versão em que iniciaram. Migração de instâncias entre versões: fora do MVP.

## Atualização — exclusão de rascunhos

- Fluxo **nunca publicado** (todas as versões em rascunho, sem solicitações nem comandos do engine) pode ser excluído, com confirmação digitando o identificador; etapas, rotas e formulários saem em cascata e a auditoria guarda o resumo (`flow.deleted`).
- Em fluxo publicado, só o **rascunho** em edição pode ser descartado (`version.draft_discarded`); versões publicadas continuam imutáveis e nunca são apagadas.
