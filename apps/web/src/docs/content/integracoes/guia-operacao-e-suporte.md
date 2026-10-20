# Guia — Operação e suporte das integrações

Este guia é para quem opera e dá suporte ao BPM TMSA: acompanhar a saúde das integrações, ler a aba
Execuções, reconciliar escritas incertas, solucionar erros e rastrear chamadas. Contexto e configuração:
[guia de criação de integração](guia-criar-integracao.md) e
[guia de mapeamentos e MOCK](guia-mapeamentos-e-mock.md).

## Aba Visão geral

A aba é visível a todos, mas os dados vêm de `GET /api/v1/admin/integrations`, que exige papel `admin`
ou `auditor` — quem tem apenas `processes.manage` vê o menu, mas não os dados da Visão geral.

Métricas do topo:

- **Flowable REST** — "Disponível" / "Indisponível";
- **Conexões habilitadas** — habilitadas / total;
- **Chamadas (24 h)** — total e quantas com falha;
- **Engine · e-mails** — comandos do outbox pendentes e e-mails com falha.

Alertas automáticos (com atalho para a aba correspondente):

- "Conexão <nome>: o último teste falhou.";
- "Conexão <nome>: N endpoint(s) com contrato alterado no Swagger — reimporte e revise.";
- "N chamada(s) com falha nas últimas 24 h.";
- "Há escrita(s) com resultado incerto aguardando reconciliação.";
- "N operação(ões) do BPM ainda em MOCK (dados simulados): <operações>." (leva à aba Mapeamentos).

Painéis: "Como integrar (passo a passo)", "Conexões" (com a pílula "desabilitada" / "teste falhou" /
"ok" / "não testada"), **"Operações do BPM → transporte efetivo"** (pílula âmbar MOCK, verde REST…) e
"Chamadas recentes".

## Aba Execuções

Texto do topo: "Cada chamada a sistema externo, com pedido e resposta mascarados (sem CPF, CNPJ, e-mail,
telefone ou campos sensíveis) e o correlation ID para rastreio." A aba exige `settings.manage`,
`processes.manage` ou `audit.read`.

- **Filtros**: resultado ("Todos os resultados", "Somente falhas", "Sucesso", "Não encontrado",
  "Resultado incerto (reconciliar)"), período ("Última hora", "Últimas 24 h", "Últimos 7 dias", "Últimos
  30 dias") e busca livre ("Operação, endpoint ou correlation ID..."). Estatística "Últimas 24 h:".
- **Colunas**: "Quando", "Operação", "Endpoint / transporte", "Resultado", "HTTP", "Duração".
- **Resultados**: `success` "Sucesso", `not_found` "Não encontrado", `unknown` "Resultado incerto",
  `reconciled_retry` "Reconciliado (repetir)".
- **Detalhes**: "Pedido (mascarado)", "Resposta (mascarada)", explicação do erro com "O que fazer:",
  "Correlation ID", "chave de idempotência" e o vínculo com a solicitação (instância do processo).

### Reconciliar uma escrita incerta

Exige `processes.manage`. A janela "Reconciliar escrita com resultado incerto" explica: "A chamada
<operação> esgotou o tempo depois do envio: o sistema de destino pode ter gravado. Confira lá antes de
decidir." Opções:

- **"Foi executada"** — "informar as saídas (a próxima tentativa devolve este resultado, sem reenviar)";
  preencha "Saídas (uma por linha: campo=valor)" (ex.: `numeroDiarioTransferencia=OT-000124`);
- **"Não foi executada"** — "a próxima tentativa reenvia".

Campo "Observação" (até 300 caracteres) e botões "Confirmar"/"Cancelar". Confirmações: "Reconciliado como
executado: a próxima tentativa devolve o resultado informado, sem reenviar." / "Reconciliado como não
executado: a próxima tentativa reenvia." A reconciliação é auditada (`integration.execution_reconciled`).
Faça isso **dentro da janela de tentativas** da etapa de integração (nova tentativa a cada 5 minutos) —
veja o [guia da etapa Integração](guia-etapa-integracao.md).

## Solução de problemas

Cada falha registra um código com título, causa provável e ação recomendada (visíveis nos detalhes da
execução). Os códigos das integrações REST:

| Sintoma | Código | Título | O que fazer |
|---|---|---|---|
| Destino bloqueado | `target_blocked` | "Destino bloqueado pela política de rede" | declarar o host em `BPM_INTEGRATION_ALLOWED_HOSTS`; usar https |
| HTTP 403 | `forbidden` | "Acesso negado pela API (403)" | revisar permissões do usuário de serviço na API |
| API recusou os dados (400/422) | `request_rejected` | "Requisição recusada pela API" | conferir o de/para do vínculo e os campos obrigatórios |
| Parâmetro obrigatório sem valor | `request_invalid` | "Requisição incompleta" | garantir a entrada no formulário/processo ou no vínculo |
| Resposta > 2 MB | `response_too_large` | "Resposta grande demais" | reduzir o escopo do endpoint ou ajustar `BPM_INTEGRATION_MAX_RESPONSE_BYTES` |
| Escrita bloqueada | `write_not_allowed` | "Escrita não liberada" | liberar escritas na conexão com a referência da aprovação |
| Tentativa anterior incerta | `reconciliation_required` | "Reconciliação pendente" | conferir no sistema de destino e usar **Reconciliar** em Execuções |
| Login sem token na resposta | `token_not_found` | "Token não encontrado na resposta" | informar o "Caminho do token na resposta" na conexão |
| Credencial não testada de verdade | `not_verified` | "Credencial não verificada" | configurar o "Caminho de verificação" e testar a conexão |
| Credencial recusada (401) | `credential_invalid` (`rest:credential_invalid`) | "Credencial recusada pela API" | renovar/substituir a credencial (somente escrita) |
| Tempo esgotado | `timeout` | "Tempo esgotado" | verificar rota/firewall; em escrita, o resultado fica incerto |
| Sem conexão | `connection_failed` | "Não foi possível conectar" | DNS, porta e egress do servidor da API |
| Limite de requisições | `throttled` | "Limite de envio/requisições" | aguardar e revisar limites na API de destino |
| Erro interno da API (5xx) | `provider_error` | "Erro do provedor" / indisponibilidade | acionar o responsável pela API com o correlation ID |
| Endpoint/conexão desligado com vínculo ativo | `not_configured` | configuração pendente | habilitar endpoint e conexão — a operação não volta ao MOCK |
| Transporte AX não implementado | `not_configured` | "ainda não configurado (contrato AX pendente)" | usar vínculo REST ou MOCK |

## Rastreio por correlation ID

Cada execução registra um **correlation ID**, exibido nos detalhes da execução e propagado nos logs da
API. Para acompanhar de ponta a ponta na stack Docker:

```bash
docker compose logs api queue-worker scheduler
```

Filtre pelo correlation ID (ou pelo prefixo dele) para cruzar a requisição HTTP, o worker de integração
(`bpm:external-jobs`, a cada 15 s) e a verificação diária do Swagger (`bpm:openapi-check`, 06:10 UTC).

## Auditoria

Os 12 eventos `integration.*` (todos gravados com `AuditLogger::record`, sem segredos nem valores de
formulário):

| Evento | Rótulo |
|---|---|
| `integration.completed` | "Integração concluída" |
| `integration.failed` | "Integração falhou" |
| `integration.connection_saved` | "Conexão de integração salva" |
| `integration.connection_tested` | "Conexão de integração testada" |
| `integration.secret_updated` | "Credencial de integração substituída" |
| `integration.openapi_imported` | "Endpoints importados do OpenAPI/Swagger" |
| `integration.endpoint_contract_updated` | "Contrato do endpoint atualizado pela importação" |
| `integration.endpoint_saved` | "Endpoint de integração salvo" |
| `integration.endpoint_deleted` | "Endpoint de integração removido" |
| `integration.endpoint_tested` | "Endpoint de integração testado" |
| `integration.binding_saved` | "Vínculo de operação com endpoint salvo" |
| `integration.execution_reconciled` | "Escrita com resultado incerto reconciliada" |

Pedidos, respostas, logs e mensagens de erro passam pelo mascaramento (`Masker`): CPF, CNPJ, e-mail,
telefone, campos marcados como sensíveis, tokens, JWT, blocos PEM e credenciais em URL são redigidos.

## Comportamentos que exigem cuidado

1. **MOCK sem trava em produção** e vínculo desativado/ausente = volta ao MOCK (é o rollback documentado):
   um processo real pode gravar dados sintéticos (ex.: `DT-…`).
2. Fonte `api:` desabilitada/despublicada depois de usada num formulário: "Consultar" recebe 404 e a
   submissão **pula a consulta** (destinos vazios, sem erro). Reimportar o Swagger pode trocar os campos
   de saída e esvaziar destinos.
3. `GET /lookups/{fonte}`: qualquer usuário autenticado consulta qualquer fonte publicada.
4. Etapa de integração: falha = nova tentativa a cada 5 min até esgotar as tentativas (o número vem do
   Flowable; padrão de fábrica 3) → dead-letter **sem retomada pelo BPM**; escrita incerta precisa ser
   reconciliada nessa janela; exceção inesperada não consome tentativa (o job volta a cada ~5 min,
   indefinidamente).
5. **HTTP 404 numa escrita conta como sucesso** (com saídas vazias).
6. Ativar vínculo não confere se endpoint/conexão estão habilitados nem se a escrita aponta para endpoint
   de escrita; a idempotência é por endpoint — trocar o endpoint entre tentativas pode reenviar a escrita.
7. O validador do fluxo não confere se as entradas da operação (`numeroOcdev`, `codigoItem`,
   `ordemCompra`) existem nos dados do processo; o editor não mostra as entradas exigidas.
8. Falha de integração não muda o status da instância nem aparece em "Etapas atuais"; o diagrama não
   destaca a etapa de integração.
9. Erro 4xx (diferente de 404) numa consulta vira "indisponível" (503) para o usuário; as mensagens do
   formulário dizem "AX" mesmo para fontes REST, e o construtor chama o componente de "Consulta AX".
10. A Visão geral exige papel admin/auditor, mas o menu da tela aparece para quem tem
    `processes.manage`; o `metadata.lookups.result` da submissão fica sem máscara (é o resultado integral
    da consulta).

## Referência rápida de rotas

Todas sob `/api/v1`, autenticadas; a autorização fina é feita no controller (não no arquivo de rotas):

| Método e caminho | Permissão | Limite |
|---|---|---|
| `GET /lookups/{source}` | qualquer autenticado | 120/min |
| `GET /admin/integrations` (Visão geral) | papel admin/auditor | — |
| `GET`/`POST /admin/integrations/connections` | `settings.manage` | 20/min (POST) |
| `PUT /admin/integrations/connections/{id}` | `settings.manage` | 30/min |
| `POST /admin/integrations/connections/{id}/test` | `settings.manage` | 10/min |
| `POST /admin/integrations/connections/{id}/openapi/preview` e `…/apply` | `settings.manage` | 10/min |
| `GET`/`POST /admin/integrations/endpoints` | `settings.manage` | 30/min (POST) |
| `POST /admin/integrations/endpoints/infer` | `settings.manage` | 30/min |
| `GET`/`PUT`/`DELETE /admin/integrations/endpoints/{id}` | `settings.manage` | 60/min (PUT) |
| `POST /admin/integrations/endpoints/{id}/test` | `settings.manage` | 20/min |
| `GET /admin/integrations/bindings` e `PUT /admin/integrations/bindings/{operação}` | `settings.manage` | — |
| `GET /admin/integrations/executions` | `settings.manage`, `processes.manage` ou `audit.read` | — |
| `POST /admin/integrations/executions/{id}/reconcile` | `processes.manage` | — |
| `GET /admin/engine-commands` | papel admin, auditor ou process_admin | — |
| `POST /admin/engine-commands/{id}/retry` | `processes.manage` | — |
