# ADR-0006 — Integração com Dynamics AX 2012 R3

- **Status:** aceito (contratos AX pendentes)

## Decisão

- Um único adaptador (`App\Integrations\Ax\AxGateway`) com **transporte configurável por operação**: `MOCK | REST_FACADE | AIF_WCF | SQL_READONLY_VIEW` (`config/bpm.php`, variáveis `BPM_AX_*_TRANSPORT`).
- Nesta entrega **somente MOCK está implementado**, com dados sintéticos. Os demais transportes respondem "não configurado": **nenhum contrato AX foi inventado**.
- Escritas de negócio (diário de transferência, marcação OCDev na OC, bloqueio do item) **nunca** usam SQL; o gateway bloqueia `SQL_READONLY_VIEW` para operações de escrita.
- Toda chamada gera `integration_execution` (request/response mascarados, resultado, duração, tentativa, correlation ID, idempotency key).
- Consultas sob demanda (`GET /lookups/{source}`) para UX; o servidor **reconsulta ao salvar** e grava snapshot/origem/horário na submissão.
- Service tasks BPMN usam **external worker** do Flowable (tópico `tmsa-integration`): o worker Laravel executa a operação com chave idempotente e conclui/falha o job com retentativa limitada. Indisponibilidade não preenche valores silenciosamente.

## Atualização (catálogo de integrações REST — ADR-0016)

- A integração principal com o AX passa a ser a **API REST do AX** (`https://apidax.tmsa.ind.br`, OpenAPI v1/v2), cadastrada no catálogo de integrações: os endpoints são importados do Swagger (contrato real, sem campos inventados) e cada operação abaixo pode ser **vinculada** a um endpoint com de/para de entrada e saída. Sem vínculo ativo, continua o transporte configurado (MOCK).
- Escritas pela API exigem aprovação na conexão, são idempotentes e, com resultado incerto (tempo esgotado após o envio), aguardam reconciliação manual — ver [ADR-0016](0016-catalogo-de-integracoes-rest.md).

## Operações catalogadas

| Operação | Uso no PO 159 | Transporte atual |
|---|---|---|
| `tpr.lookup` | 01 — TPR → Cliente | MOCK |
| `item.lookup` | 01 — Código → descrição | MOCK |
| `purchaseOrder.lookup` | 01 — OC → fornecedor | MOCK |
| `supplier.lookup` | 11 — fornecedor/contato/telefone/e-mail | MOCK |
| `transferAndBlock` | service task antes da 40 (diário, OCDev, bloqueio) | MOCK |

Convenções do mock para testes manuais: valores iniciados por `NAOEXISTE` → não encontrado; por `INDISPONIVEL` → AX fora do ar.

## Próximos passos

Receber do time AX: build/CU, portas AIF/WCF ou façade REST (OpenAPI/WSDL), autenticação, ambientes de teste, views read-only aprovadas e regras de idempotência. Implementar um transporte por contrato, com testes de timeout, indisponibilidade, duplicidade e atualização parcial.
