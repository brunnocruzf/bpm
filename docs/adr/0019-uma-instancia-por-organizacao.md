# ADR-0019 — Uma instância por organização

- **Status:** aceito
- **Relaciona:** ADR-0004 (identidade Entra ID), ADR-0006 (integração AX), ADR-0014 (configurações e segredos)

## Contexto

O BPM atendia só a TMSA. A IMS precisa entrar com processos, formulários, acessos, configurações (Entra ID, e-mail,
identidade visual) e integrações (AX, outro DataAreaId) próprios. Avaliamos duas alternativas:

- **A — DataAreaId (multiempresa lógico):** uma stack, com a coluna de empresa em todas as tabelas, no estilo do AX.
- **B — Uma instância por organização:** uma stack Compose por organização, com as mesmas imagens.

O código não tem o conceito de empresa: são 46 tabelas e 39 models sem scope, 79 consultas `DB::` diretas, cerca de 20
chaves únicas globais e Flowable sem `tenantId`. O documento de arquitetura previa "não presumir multi-tenant no MVP".
A estimativa para A foi de 20 a 30 dias úteis. Ela inclui escopo e testes de isolamento em todos os endpoints, empresa
em jobs, outbox e scheduler, usuário↔empresa com seletor, configurações e cofre por empresa e `tenantId` no Flowable.
Além disso, A deixa um risco permanente de vazamento entre empresas. A estimativa para B foi de cerca de 3 dias úteis.

## Decisão

1. **Cada organização é uma instância isolada.** São banco, engine, storage, cofre e volumes próprios, num projeto
   Compose `bpm-<org>` com `.env.<org>`. A TMSA continua no `.env`, no projeto `bpm-tmsa`. O `deploy/docker/orgs.sh`
   opera todas as organizações (up, pull, build, ps, backup).
2. **A organização da instância vem do ambiente:** `BPM_ORGANIZATION_KEY` e `BPM_ORGANIZATION_NAME`, além de
   `BPM_APP_NAME`, que vira `APP_NAME`. Esses valores são usados na auditoria (`organization_key`), nos padrões da
   identidade visual, nos assuntos e rodapés de e-mail e nos textos da interface. O web lê nome e identidade visual da
   API em tempo de execução, e uma única imagem serve a todas as organizações.
3. **Identificadores técnicos não mudam:** namespace `tmsa:` no BPMN, `tmsaAuto`, tópico `tmsa-integration`,
   `storage: 'tmsa'`. Eles não são vistos pelo usuário, e renomeá-los quebraria definições publicadas.
4. **O seed só cria raias, PO 159 e fluxos de demonstração na TMSA.** As outras organizações começam vazias: o
   SuperAdmin cria os administradores, e os processos entram por importação BPMN.
5. **O DataAreaId do AX é uma configuração da instância.** Ele vai como parâmetro constante no catálogo de integrações
   (ADR-0016), sem código.

## Consequências

- Isolamento forte, com falha, manutenção, backup e restauração independentes. É possível atualizar uma organização
  antes da outra.
- Cada organização acrescenta cerca de 4 GB de RAM (soma dos limits do Compose) e um ciclo de deploy e migração.
- Quem atua em mais de uma organização usa uma URL por organização (o SSO do Entra mantém o login transparente). Não há
  tela nem relatório operacional consolidado; para BI, os bancos das instâncias são lidos separadamente.
- Processos comuns são copiados por export/import BPMN e mantidos em cada instância.
- **Reavaliar (migrar para A)** se surgir um requisito de operar várias empresas na mesma tela ou caixa de tarefas, um
  relatório operacional consolidado, ou mais de cerca de 5 organizações. As 33 tabelas com PK UUID permitem fundir os
  bancos sem colisão de IDs.
