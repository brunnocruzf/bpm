# Guia — Mapeamentos (vínculos) e o MOCK

Este guia explica a aba **Mapeamentos** de **Administração → Integrações e entregas**: o que é o MOCK,
como ligar cada operação do BPM a um endpoint real do catálogo (o "vínculo") e qual transporte vale em
cada situação. Antes deste guia, crie a conexão e os endpoints pelo
[guia de criação de integração](guia-criar-integracao.md). Decisões:
[ADR-0016](../adr/0016-catalogo-de-integracoes-rest.md) e [ADR-0006](../adr/0006-integracao-ax-2012-r3.md).

## O MOCK em detalhe

### O que é e por que existe

O MOCK é o transporte simulado (`MockAxTransport`) das operações do BPM. Ele existe para permitir
desenvolver, demonstrar e testar os fluxos **sem nenhum sistema externo**: não inventa contrato do AX e
nunca sai da rede. Cada operação tem seu transporte configurável por variável de ambiente
(`apps/api/config/bpm.php`), e o padrão de todas é `MOCK`:

| Operação | Variável | Padrão |
|---|---|---|
| `tpr.lookup` | `BPM_AX_TPR_TRANSPORT` | `MOCK` |
| `item.lookup` | `BPM_AX_ITEM_TRANSPORT` | `MOCK` |
| `purchaseOrder.lookup` | `BPM_AX_PO_TRANSPORT` | `MOCK` |
| `supplier.lookup` | `BPM_AX_SUPPLIER_TRANSPORT` | `MOCK` |
| `transferAndBlock` | `BPM_AX_TRANSFER_TRANSPORT` | `MOCK` |

O Compose de `deploy/docker` **não repassa** essas variáveis: enquanto nada for configurado (e não houver
vínculo ativo), tudo roda em MOCK. Os demais valores aceitos (`REST_FACADE`, `AIF_WCF`,
`SQL_READONLY_VIEW`) não estão implementados e respondem "Transporte &lt;t&gt; para &lt;operação&gt; ainda
não configurado (contrato AX pendente)." (código `not_configured`). Escrita via SQL é proibida: "Escrita
no AX via SQL é proibida."

### Dados sintéticos por operação

Os valores são determinísticos, calculados a partir de `crc32` do valor consultado:

- `tpr.lookup` — gera um cliente `C-####` com nomes como "Cliente Exemplo Ltda (fictício)", "Montadora
  Demonstração S.A. (fictício)" e "Indústria Modelo Ltda (fictício)";
- `item.lookup` — descrições como "Chapa de aço 2mm", "Rolamento 6205", "Parafuso M8 x 30", com unidade
  alternando entre `PC` e `KG`;
- `purchaseOrder.lookup` — escolhe um fornecedor pelo hash e devolve a situação "Recebida parcialmente";
- `supplier.lookup` — **só encontra** `F-1001` ("Fornecedor Alfa (fictício)"), `F-1002` ("Fornecedor Beta
  (fictício)") e `F-1003` ("Fornecedor Gama (fictício)");
- `transferAndBlock` — devolve `numeroDiarioTransferencia` no formato `DT-######`,
  `ocdevMarcadaNaOc = true`, `itemBloqueado = true` e `axReferencia` com o valor de `numeroOcdev`. O
  resultado fica em cache permanente por chave de idempotência (`mock-ax:<chave>`), então a mesma
  tentativa nunca gera dois diários diferentes.

### Convenções de teste

- Valor vazio ou começando com `NAOEXISTE` (ex.: `NAOEXISTE123`) → "não encontrado" (a consulta devolve
  vazio);
- Valor começando com `INDISPONIVEL` (só nas consultas) → simula queda: "AX (simulado) indisponível."
  (código `timeout`). A escrita simulada nunca falha.

### Onde o MOCK aparece

- Nas pílulas de "Transporte efetivo" da Visão geral e da aba Mapeamentos (MOCK em âmbar);
- no campo `transport` das respostas de consulta e das execuções registradas;
- no snapshot `metadata.lookups` das submissões e nos relatórios que o exibem.

**O que não usa MOCK**: as consultas publicadas `api:<identificador>` chamam o endpoint real
diretamente, sem vínculo e sem MOCK.

## A aba Mapeamentos

Texto do topo: "Mapeamentos das operações do BPM — Ligue as consultas dos formulários e as tarefas de
integração aos endpoints reais, com de/para de entrada e saída. Sem vínculo ativo vale o transporte
configurado (MOCK)."

### As 5 operações

| Operação | Rótulo exibido | Tipo | Entradas (lado BPM) | Saídas (lado BPM) | Quem consome |
|---|---|---|---|---|---|
| `tpr.lookup` | "Consulta nos formulários: TPR → Cliente" | leitura | `value` ("Valor digitado no formulário") | `customerCode`, `customerName` | componente "Consulta AX", fonte `tpr` |
| `item.lookup` | "Consulta nos formulários: Código do item → Material" | leitura | `value` | `itemDescription`, `unit` | componente "Consulta AX", fonte `item` |
| `purchaseOrder.lookup` | "Consulta nos formulários: Ordem de compra" | leitura | `value` | `supplierCode`, `supplierName`, `status` | componente "Consulta AX", fonte `purchaseOrder` |
| `supplier.lookup` | "Consulta nos formulários: Código do fornecedor → Contato" | leitura | `value` | `supplierName`, `contactName`, `phone`, `email` | componente "Consulta AX", fonte `supplier` |
| `transferAndBlock` | "Tarefa de integração: AX: diário de transferência, marcação da OCDev na OC e bloqueio do item" | escrita | `numeroOcdev`, `codigoItem`, `ordemCompra` | `numeroDiarioTransferencia`, `ocdevMarcadaNaOc`, `itemBloqueado` | etapa do tipo Integração (`ax.transferAndBlock`) |

A tabela tem as colunas "Operação do BPM", "Transporte efetivo", "Endpoint", "De/para" e "Atualizado".

### Pílulas de transporte efetivo

- **"MOCK"** (âmbar) — não há vínculo ativo; vale o transporte configurado (dados simulados);
- **"REST (real)"** (verde) — há vínculo ativo e o endpoint e a conexão estão habilitados;
- **"REST — endpoint/conexão desligado"** (vermelho) — há vínculo ativo, mas o endpoint ou a conexão está
  desabilitado: **a operação falha** (não volta ao MOCK).

### A janela "Mapear", linha a linha

O botão de cada operação abre a janela "Mapear — &lt;rótulo da operação&gt;":

1. **Endpoint** — para consultas, o campo é "Endpoint (leitura)" e só aceita `GET` (o servidor recusa
   outros métodos: "Consultas precisam de endpoint de leitura (GET)."). A opção "Nenhum (usar transporte
   configurado)" limpa o vínculo. Se o endpoint ou a conexão estiver desabilitado, a janela avisa:
   "Endpoint ou conexão desabilitado: com o vínculo ativo, a operação vai falhar até habilitar (não volta
   ao MOCK)."
2. **"Entrada — destino no endpoint ⇐ origem no BPM"** — tabela "Destino no endpoint" / "Origem" /
   "Constante":
   - os destinos são os **parâmetros de entrada** do endpoint (PATH/QUERY/HEADER) mais os campos de corpo
     `body:<caminho>` importados do Swagger; o campo com placeholder "campo do corpo não documentado
     (ex.: lines[].itemId)" + "Adicionar" permite incluir um campo de corpo manualmente;
   - as origens são `input:<campo>` (uma entrada da operação, ex.: `input:value` ou `input:codigoItem`),
     "Valor constante" (`const:<valor>` — texto fixo, não secreto, até 200 caracteres; recusado em
     parâmetros que pareçam credencial) ou "— não enviar —".
3. **"Saída — campo do BPM ⇐ caminho na resposta"** — tabela "Campo do BPM" / "Caminho na resposta"
   (placeholder "ex.: nome ou contato.email"). A lista de caminhos sugere os campos de saída do endpoint;
   se não houver: "O endpoint ainda não tem campos de saída: defina-os na etapa “Parâmetros de saída”
   (exemplo ou teste) para escolher da lista." — o caminho pode ser digitado mesmo assim.
4. **"Ativar vínculo"** — "a operação passa a chamar o endpoint real". Em escritas, o lembrete: "(escrita
   idempotente: nunca repete um envio concluído; resultado incerto exige reconciliação)".
5. **"Salvar"** ("Salvando...") ou "Cancelar". Mensagens de sucesso: "Vínculo ativo: a operação passa a
   chamar o endpoint real." ou "Vínculo salvo (inativo: continua no transporte configurado)."

Validações do servidor ao salvar: "Operação desconhecida.", "Entrada inválida: &lt;destino&gt; ⇐
&lt;origem&gt;.", "Saída inválida: &lt;campo&gt; ⇐ &lt;caminho&gt;." e, para ativar sem o mínimo: "Para
ativar, escolha o endpoint e mapeie entrada e saída." Cada salvamento é auditado
(`integration.binding_saved`).

## Semântica em execução

Quando uma operação com vínculo ativo é chamada (por uma consulta de formulário ou pela etapa de
integração):

- **constantes do endpoint** (etapa 2 do assistente) são sempre enviadas e são **sempre texto**;
- parâmetro obrigatório sem valor interrompe a chamada: "Parâmetro obrigatório "&lt;nome&gt;" não
  informado." (código `request_invalid`);
- parâmetros PATH são codificados na URL; o corpo é montado campo a campo com a mesma sintaxe de caminhos
  JSON (`body:lines[].itemId` escreve **somente no primeiro item** da lista);
- na resposta, aplica-se o "Caminho do resultado" do endpoint; se o resultado for uma lista, vale o
  **primeiro item**; apenas valores escalares (texto, número, booleano, data) são projetados nos campos;
- em **consultas**, HTTP 404 ou resposta vazia significam "não encontrado" (a consulta devolve vazio, sem
  erro de transporte);
- em **escritas**, a idempotência é por chave **e por endpoint**: se já existe execução `success` com a
  mesma chave de idempotência naquele endpoint, o servidor devolve as saídas gravadas **sem reenviar**;
  se existe execução `unknown` (tempo esgotado após o envio), a repetição é bloqueada com "Uma tentativa
  anterior teve resultado incerto (tempo esgotado após o envio): confira no sistema de destino e
  reconcilie antes de repetir." (código `reconciliation_required`) até alguém reconciliar na aba
  Execuções. Trocar o endpoint do vínculo entre tentativas pode reenviar a escrita.

## Transporte efetivo (o que vale em cada situação)

```mermaid
flowchart TD
    A[Chamada da operação do BPM] --> B{Vínculo ativo?}
    B -- Sim --> C{Endpoint e conexão habilitados?}
    C -- Sim --> D[RestIntegration → RestCaller → TargetPolicy → API externa]
    C -- Não --> E[Falha: "Endpoint ou conexão desabilitado" - não volta ao MOCK]
    B -- Não --> F[Transporte configurado - padrão MOCK: dados simulados]
```

Regras:

- **O vínculo ativo sempre vence** o transporte configurado — inclusive em produção.
- Vínculo ativo com endpoint/conexão desligado **falha** ("Endpoint &lt;id&gt; ou conexão &lt;id&gt;
  desabilitado.", código `not_configured`); não há retorno silencioso ao MOCK.
- Sem vínculo ativo (inexistente ou desmarcado), vale o transporte configurado — o padrão é MOCK.
- **O vínculo é global**: vale para todos os fluxos e formulários que usam a operação (não é por fluxo
  nem por ambiente de execução).
- **Rollback para MOCK**: desmarque "Ativar vínculo" e salve. A operação volta imediatamente ao
  transporte configurado. ⚠ Isso também significa que um vínculo desativado por engano faz um processo
  real gravar dados sintéticos (ex.: `DT-…`) — veja "Comportamentos que exigem cuidado" no
  [guia de operação e suporte](guia-operacao-e-suporte.md).

## Exemplos ilustrativos (fixture de teste)

Os exemplos abaixo usam a fixture sintética
[`apps/api/tests/Fixtures/openapi-ax-sample.json`](../../apps/api/tests/Fixtures/openapi-ax-sample.json).
**São ilustrativos**: o Swagger real do AX não descreve as respostas, então os caminhos de saída devem
vir dos campos inferidos do **seu** endpoint (exemplo ou teste em homologação). Não invente campos.

### Consulta: `supplier.lookup` ⇄ `GET /v1/Fornecedores/{CPF_CNPJ}`

A fixture descreve a operação `ListaFornCNPJ` (grupo "Fornecedores", "Lista um Fornecedor") com o
parâmetro PATH obrigatório `CPF_CNPJ`. Um vínculo possível:

| Destino no endpoint | Origem |
|---|---|
| `CPF_CNPJ` (PATH) | `input:value` |

| Campo do BPM | Caminho na resposta (exemplo fictício) |
|---|---|
| `supplierName` | `nome` |
| `contactName` | `contato.nome` |
| `phone` | `contato.telefone` |
| `email` | `contato.email` |

### Escrita: `transferAndBlock` ⇄ `POST /v1/Financeiro/CriarOrdemTransferencia`

A fixture descreve a operação `CriarOT` (grupo "Financeiro", "Criar Ordem De Transferencia") com corpo
JSON `OrdemDeTransferencia` (`inventLocationIdFrom`, `inventLocationIdTo`, `shipDate`, `transferId` e a
lista `lines[]` com `itemId`, `lineNum`, `transferQty`). Um vínculo possível (a conexão precisa ter
escritas liberadas):

| Destino no endpoint | Origem |
|---|---|
| `body:transferId` | `input:numeroOcdev` |
| `body:lines[].itemId` | `input:codigoItem` |
| `body:lines[].transferQty` | `const:1` |
| `body:lines[].lineNum` | `const:1` |

| Campo do BPM | Caminho na resposta (exemplo fictício) |
|---|---|
| `numeroDiarioTransferencia` | `transferId` |
| `ocdevMarcadaNaOc` | `ocMarcada` |
| `itemBloqueado` | `bloqueado` |

> Pendência real: qual operação (ou conjunto de operações) do AX executa de fato "diário de
> transferência + marcação da OCDev na OC + bloqueio do item" ainda aguarda validação com o time do AX
> (ver `docs/pendencias-validacao.md`). Até lá, vale o transporte MOCK.

## Leitura seguinte

- Consumir as consultas em formulários: [guia de consumo em formulários](guia-consumir-formulario.md).
- Consumir a escrita na etapa de integração: [guia da etapa Integração](guia-etapa-integracao.md).
- Acompanhar execuções e reconciliar escritas: [guia de operação e suporte](guia-operacao-e-suporte.md).
