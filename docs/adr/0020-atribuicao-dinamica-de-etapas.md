# ADR-0020 — Atribuição dinâmica de etapas ("Quem executa?")

- **Status:** aceito
- **Relaciona:** ADR-0005 (outbox e reconciliação), ADR-0009 (modelo de definição e subset BPMN), ADR-0012 (notificações)

## Contexto

Até aqui "Quem executa?" era sempre um grupo: a raia virava `candidate_group` e qualquer membro podia executar. O
FOR-784 precisa de outra regra: a aprovação é **somente** da pessoa definida no formulário (o gestor substituto, se
houver, ou o gestor imediato), e a correção volta só ao solicitante. Outros fluxos terão a mesma necessidade.

O Flowable não participa: ele não autoriza nada por `candidateGroups`, e o `BpmnImporter` proíbe `flowable:assignee`
justamente para não existir regra de atribuição fora do Laravel.

## Decisão

1. **A etapa tem uma regra de atribuição**, em `config.assignment`, resolvida pelo Laravel ao criar a tarefa
   (`ProjectionSync` → `AssignmentResolver::forTask`). Sem a chave vale o comportamento anterior, então as versões já
   publicadas não mudam.

   ```php
   'assignment' => [
       'mode' => 'field',                      // group | field | requester | requester_manager
       'fields' => ['gestorSubstitutoId', 'gestorImediatoId'], // o primeiro preenchido vence
       'fallbackGroup' => 'PONTO_GESTOR',      // obrigatório fora do modo group
       'excludeRequester' => true,
       'excludeFields' => ['colaboradorUserId'],
       'requiredUnless' => ['solicitanteGestor' => 'sim'], // não exige responsável nesse caminho
   ],
   ```

2. **O estado da tarefa não precisa de migration.** Com atribuição direta, `assignee_id` é o usuário resolvido,
   `candidate_group` é nulo e o status é `claimed`. Na contingência, `assignee_id` é nulo, `candidate_group` é o
   `fallbackGroup` e o status é `open`. Com `candidate_group` nulo o grupo da raia não vê a tarefa nem recebe e-mail, e
   a raia vira só um elemento visual do diagrama.

3. **O responsável nunca vem de valor digitado.** Só valem **referências do servidor**: alvos de `fillMap` sem
   componente no formulário, alimentados pelos campos `userId` e `managerUserId` da consulta interna `employee`. Elas
   começam nulas, só recebem valor se a consulta rodar, e o navegador não consegue informá-las. Ficam em `instance.data`
   e **não** vão para o payload da submissão, para o engine, para os relatórios nem para o resultado da consulta devolvido
   ao navegador. `hidden: true` não serve para isso: `ConditionalEvaluator::isVisible` descartaria o valor.

4. **Uma só elegibilidade, três momentos.** UUID válido, usuário existente, `is_active` e `account_enabled` diferentes
   de `false`, papel `task_worker` e sem violar `excludeRequester` e `excludeFields`.
   - **Envio do formulário** (`assertSubmission`): recusa com 422, com o erro no campo de consulta que preencheu a
     referência. Vale para etapas em modo `field` alimentadas pelo formulário atual e respeita `requiredUnless`.
   - **Criação da tarefa** (`forTask`): roda no reconciliador e nunca recusa. Quem ficou inelegível depois do envio
     manda a tarefa para a contingência, com o motivo em `task.assignment_fallback`.
   - **Reatribuição de tarefa sem grupo** (`ineligibility`): substitui a exigência de pertencer ao grupo.

5. **O primeiro campo preenchido vence, sem cascata.** Um substituto inelegível não devolve a etapa ao gestor imediato:
   no envio é 422 e, na execução, contingência.

6. **A regra faz parte da versão.** Fica no XML publicado como `tmsa:assignment` (JSON, como `tmsa:condition`), o que a
   inclui no hash e permite reimportar o BPMN. `candidateGroups` continua sendo gravado, sem efeito sobre a autorização.

7. **Tarefa sem grupo não volta para grupo.** `release` responde 409 `task_directly_assigned`, e a saída é a
   reatribuição por quem tem `processes.manage`. O `RuntimePresenter` expõe `canRelease` e `canReassign`.

8. **O campo automático `requester.matches:<referência>`** devolve `sim` ou `nao` comparando o solicitante com a
   referência. Ele substitui perguntas declaradas pelo usuário, como "você é o gestor?". O valor só existe no envio.

## Validação de publicação

O `FlowValidator` exige modo válido, `fields` só com referências de usuário preenchidas em etapa anterior, `excludeFields`
só com referências de usuário, `requiredUnless` com campos existentes e `fallbackGroup` existente. A etapa de abertura só
aceita o modo `group`, porque quem a preenche é o solicitante. Fora do modo `group` a raia deixa de ser obrigatória.

## Consequências

- **Sem migração de instâncias:** as abertas seguem na versão delas, com aprovação pelo grupo.
- **A contingência precisa ter membros.** Sem eles, a tarefa fica sem ninguém para assumir até a reatribuição, e o prazo
  da etapa continua correndo.
- **A contingência é aprovação por grupo.** Nela, `Access` não aplica `excludeRequester` nem `excludeFields`: um membro
  do grupo que seja o solicitante consegue assumir a tarefa. O mesmo vale para a reatribuição de tarefa com grupo.
- **O modo `requester` exige o papel `task_worker`.** Solicitante só com `Bpm.Initiator` cai na contingência.
- **`requiredUnless` dispensa a validação inteira da etapa** naquele caminho, inclusive a de um substituto informado.
- **Alvos de `fillMap` sem componente passam a ser gravados em `instance.data`** em qualquer fluxo, e não mais
  descartados. Eles seguem fora do payload e do engine.
- **Quem indica o substituto é o solicitante.** Os bloqueios impedem a autoaprovação, mas não a combinação com um colega;
  só um cadastro de delegações resolveria isso.
- **Fora do escopo:** busca de substituto por nome, cadastro de delegações e controles na tela para `excludeFields` e
  `requiredUnless` (preservados ao salvar).
