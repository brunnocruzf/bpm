# Guia — Criar uma integração (conexão, Swagger, endpoints e consulta publicada)

Este guia cobre, campo a campo, o caminho completo para criar uma integração no catálogo REST do BPM
TMSA (**Administração → Integrações e entregas**): cadastrar a conexão, importar o Swagger, completar os
endpoints e publicar uma consulta para os formulários. Decisões de arquitetura:
[ADR-0016](../adr/0016-catalogo-de-integracoes-rest.md) e [ADR-0006](../adr/0006-integracao-ax-2012-r3.md).

> Todos os rótulos de botões, abas e mensagens deste guia foram copiados do código da aplicação.

## Pré-requisitos e permissões

| Item | Detalhe |
|---|---|
| Permissão das abas Conexões, Endpoints e Mapeamentos | `settings.manage` (papel de administração de configurações). A autorização é verificada no servidor em cada rota (`Gate::authorize`), nunca só na tela |
| Aba Visão geral | papel `admin` ou `auditor` |
| Aba Execuções | `settings.manage`, `processes.manage` ou `audit.read` |
| Reconciliar uma execução | `processes.manage` |
| Declaração de hosts (plataforma) | hosts que resolvem para a rede interna e, em produção, todos os hosts precisam estar em `BPM_INTEGRATION_ALLOWED_HOSTS` (lista separada por vírgulas, no ambiente da API — ex.: `deploy/docker/.env` — seguida de `docker compose up -d`) |

Limites da plataforma (valores padrão de `apps/api/config/bpm.php`):

| Limite | Padrão | Variável |
|---|---|---|
| Tamanho máximo da resposta de uma chamada | 2 MB | `BPM_INTEGRATION_MAX_RESPONSE_BYTES` |
| Tamanho máximo do documento OpenAPI | 5 MB | `BPM_INTEGRATION_MAX_OPENAPI_BYTES` |
| Tempo limite padrão da conexão | 15 s (o campo aceita de 2 a 120) | `BPM_INTEGRATION_TIMEOUT` |
| Validade do token de login em cache | 50 min | fixa (`bpm.integrations.token_ttl_minutes`) |
| Exemplo de resposta colado no assistente | 512 KB | fixa no servidor |
| Prévia de importação do Swagger | expira em 30 minutos | fixa no servidor |

A execução das etapas de integração e a verificação diária do Swagger dependem dos containers
`scheduler` e `queue-worker` da stack Docker (veja o [guia de operação](guia-operacao-e-suporte.md)).

## Visão geral do caminho

1. **Conexão** — sistema, URL base, ambiente e autenticação (credencial no cofre, somente escrita).
2. **Importar do Swagger** — prévia das operações; você escolhe o que importar; tudo entra desabilitado.
3. **Endpoints** — completar os parâmetros de saída (exemplo ou teste) e revisar a segurança.
4. **Publicar como consulta** (`api:<identificador>`) para os formulários — ou mapear uma operação do BPM
   (veja o [guia de mapeamentos e MOCK](guia-mapeamentos-e-mock.md)).
5. **Habilitar** endpoint e conexão (escritas exigem liberação com referência de aprovação).
6. **Acompanhar** na aba Execuções.

## Passo 1 — Aba Conexões

Texto do topo da aba: "Sistema, URL base, ambiente e autenticação. A credencial fica no cofre (somente
escrita) e é usada apenas pelo servidor."

### Atalho para a API do AX

Enquanto não existir uma conexão com o identificador `apidax`, a aba mostra o botão **"Conexão da API do
AX (apidax)"**, que só pré-preenche o formulário (nada é gravado até salvar; nenhum seed cria conexão):

| Campo | Valor pré-preenchido |
|---|---|
| Identificador | `apidax` |
| Nome | `API Dynamics AX 2012 (apidax)` |
| Sistema | `AX2012` |
| URL base | `https://apidax.tmsa.ind.br` |
| URL do documento OpenAPI | `https://apidax.tmsa.ind.br/swagger/v1/swagger.json` |
| Método de autenticação | Bearer token (prefixo `Bearer`) |
| Caminho de verificação | `/v1/Financeiro/Moeda` |

Há também o botão **"Nova conexão"** para qualquer outro sistema.

### Campos da conexão

| Campo | Rótulo na tela | Observações |
|---|---|---|
| Identificador | "Identificador" | único; placeholder `apidax` |
| Nome | "Nome" | exibição |
| Sistema | "Sistema" | placeholder `AX2012, Senior, ...` |
| Ambiente | "Ambiente" | Desenvolvimento, Homologação ou Produção |
| URL base | "URL base" | placeholder `https://apidax.tmsa.ind.br` |
| OpenAPI | "URL do documento OpenAPI (swagger.json)" | hint: "Use o JSON (ex.: `/swagger/v1/swagger.json`), não a página `/swagger/index.html`." |
| Autenticação | "Método de autenticação" | ver tabela abaixo |
| Tempo limite | "Tempo limite (s)" | mínimo 2, máximo 120; padrão 15 |
| Verificação | "Caminho de verificação (GET leve usado no “Testar conexão”)" | placeholder `/v1/Financeiro/Moeda` |
| Habilitação | "Conexão habilitada (chamadas reais pelo servidor)" | desabilitada, nenhuma chamada real sai |
| Escritas | "Liberar escritas (POST/PUT/DELETE) — exige aprovação" | ver seção de escritas abaixo |
| Aprovação | "Referência da aprovação (chamado/ata)" | obrigatória para liberar escritas |
| Credencial | "Token / Senha / Chave da API / Senha do usuário de serviço / Client secret — somente escrita" | o rótulo muda conforme o método |
| Segredo montado | "Ou segredo montado" | referência `file:<nome>`, placeholder `file:apidax_token` |

### Os 6 métodos de autenticação

Rótulos e comportamento (o que o servidor envia):

| Método (rótulo) | O que o servidor envia | Campos específicos |
|---|---|---|
| "Sem autenticação" | "Nenhum cabeçalho de autenticação (APIs públicas internas)." | — |
| "Bearer token (Authorization: Bearer …)" | token fixo em `Authorization: Bearer <token>`; é o caso da API do AX (apidax); pode colar com ou sem o prefixo "Bearer " | "Prefixo do valor" (padrão `Bearer`) |
| "Basic (usuário e senha)" | `Authorization: Basic <usuário:senha em base64>` | "Usuário" + senha |
| "Chave de API em cabeçalho" | a chave em um cabeçalho próprio, com prefixo opcional | "Nome do cabeçalho" (placeholder `X-API-Key`), "Prefixo (opcional)" |
| "Login do usuário de serviço → token" | o servidor faz `POST <caminho do login>` com usuário e senha **no corpo** (nunca na URL), guarda o token em cache criptografado (validade padrão 50 min) e envia `Authorization: Bearer <token>`; em HTTP 401, renova o token uma única vez | "Caminho do login", "Usuário de serviço", "Caminho do token na resposta (vazio = detectar)", "Validade do token (min)" |
| "OAuth2 client credentials" | o servidor pede um token ao endpoint OAuth2 (client ID + secret no corpo), guarda em cache até `expires_in − 60 s` e envia `Authorization: Bearer <token>` | "URL do endpoint de token (https)", "Client ID", "Scope (opcional)" |

Validações do servidor: o caminho do login precisa ser relativo ("Caminho relativo começando com "/"
(ex.: /v1/User/login).") e o caminho de verificação também ("Caminho relativo de um GET leve, começando
com "/" (ex.: /v1/Financeiro/Moeda).").

### Credencial somente escrita

- A credencial é guardada criptografada no cofre (`db:`) ou referenciada como segredo montado
  (`file:<nome>`). A tela avisa: "Guardado criptografado no cofre; nunca volta para a tela nem para os
  logs."
- Quando já existe credencial, o campo mostra "•••••• (definida — preencha para substituir)"; salvar com
  o campo vazio preserva o valor atual.
- Credenciais nunca são parâmetros do endpoint nem vão na URL. O assistente de endpoints recusa
  constantes que pareçam credencial ("Credenciais não são parâmetros."; "Token/senha/Authorization ficam
  na autenticação da conexão (...) e são enviados em cabeçalho pelo servidor — nunca como constante ou na
  URL."). Chave/token na *query string* não é suportado de propósito.
- A substituição da credencial gera o evento de auditoria `integration.secret_updated`
  ("Credencial de integração substituída").

### Testar conexão

O botão **"Testar conexão"** (durante o teste: "Testando...") executa as verificações no servidor e o
cartão da conexão passa a mostrar um dos estados:

- "Último teste: verificado" — com o **caminho de verificação** preenchido, o servidor faz um GET real;
  HTTP 401/403 viram "credencial recusada";
- "Último teste: credencial não verificada" — sem caminho de verificação, o teste só confere se a
  credencial existe (nunca é um sucesso presumido);
- "Último teste: falhou" — com a mensagem, a duração, o horário, quem executou e, quando há, o bloco
  "O que fazer:" com a ação recomendada.

O hint do campo resume: "Sem ele, o teste só confere se a credencial existe (fica “não verificada”). Com
ele, o servidor faz uma chamada real e detecta token inválido (401/403)." Cada teste é auditado
(`integration.connection_tested`).

### Política de rede (TargetPolicy)

O cartão mostra "Política de rede" com "host declarado pela plataforma", "permitido" ou a mensagem de
bloqueio. Texto da tela: "Hosts declarados pela plataforma: ... Hosts que resolvem para a rede interna
(como `apidax.tmsa.ind.br`) e, em produção, todos os hosts precisam estar em
`BPM_INTEGRATION_ALLOWED_HOSTS`."

Regras aplicadas pelo servidor (`TargetPolicy`) em todo destino configurável:

- URL com esquema `https` (ou `http` somente para host declarado), sem usuário/senha na URL;
- endereços de metadata, loopback e hosts bloqueados são proibidos; redirecionamentos não são seguidos;
- hosts que resolvem para IP privado precisam estar declarados; em "produção" (`APP_ENV=production`),
  todo host precisa estar declarado.

Mensagens possíveis: "URL inválida (use https://host/caminho, sem usuário/senha na URL).", "Somente
https é permitido (http só para host declarado pela plataforma).", "Host &lt;host&gt; não está declarado
pela plataforma (BPM_INTEGRATION_ALLOWED_HOSTS)." e "O host &lt;host&gt; aponta para a rede interna
(&lt;ip&gt;): ele precisa ser declarado pela plataforma em BPM_INTEGRATION_ALLOWED_HOSTS."

### Liberar escritas

Por padrão toda conexão é somente leitura ("bloqueadas (somente leitura)"). Para endpoints POST/PUT/DELETE:

1. Marque "Liberar escritas (POST/PUT/DELETE) — exige aprovação".
2. Informe a "Referência da aprovação (chamado/ata)" — o cartão passa a exibir "liberadas (<referência>)".

Sem essa liberação, a chamada de escrita falha no servidor com "Escrita não liberada na conexão &lt;id&gt;
(exige aprovação)." (código `write_not_allowed`). Escritas também não podem ser testadas em conexão de
ambiente "Produção" (veja "Testar endpoint" no passo 3).

## Passo 2 — Importar do Swagger

Na conexão, o botão de importação abre a janela **"Importar do Swagger — &lt;nome da conexão&gt;"**:

- **"URL do swagger.json"** — o documento é baixado pelo servidor (respeitando a política de rede; tempo
  limite de 20 s); a URL informada pode atualizar a URL guardada na conexão;
- **"ou arquivo .json"** — alternativa de upload, quando o Swagger não é acessível da rede do servidor;
- **"Gerar prévia"** ("Lendo..." durante a leitura) — nada é gravado até você confirmar;
- **"Importar selecionadas"** ("Importando...") e **"Cancelar"**.

A própria janela explica: "O documento é baixado pelo servidor (respeitando a política de rede) e nada é
gravado até você confirmar. Versões: troque `v1` por `v2` na URL para importar a outra versão da API."

### Restrições do documento

- Somente **OpenAPI 3 em JSON**. Swagger 2.0 é recusado: "Swagger 2.0 não é suportado: exporte em OpenAPI
  3 (ex.: Swashbuckle "SerializeAsV2 = false")."
- JSON inválido: "O arquivo não é um JSON válido (use o swagger.json, não a página do Swagger UI)."
- Documento sem a estrutura mínima: "Documento sem "openapi: 3.x" e "paths"."
- Acima de 5 MB: "Documento OpenAPI acima do limite de tamanho."
- A prévia **expira em 30 minutos** — depois disso, gere outra.

### A prévia

A prévia resume o documento em pílulas ("N operações", "N leitura(s)", "N escrita(s)", "N novo(s)",
"N alterado(s)", "N inalterado(s)", "N removido(s) do documento", "segurança: ...") e lista as operações
por grupo, cada uma com a situação "Novo", "Contrato alterado" ou "Inalterado". Filtros: "Buscar por
caminho, nome ou operationId...", "Todos os grupos", "Qualquer situação" (Novos / Alterados / Inalterados)
e tipo ("Leitura e escrita", "Somente leitura (GET)", "Somente escrita").

- **Seleção padrão**: operações novas ou alteradas, somente leitura (GET), excluindo as que recebem
  credencial na URL (flag `credential_in_query`).
- Documento igual ao último importado: "Documento idêntico ao último importado."
- Operações que saíram do documento: "Não estão mais no documento (serão marcados como “removido”, sem
  apagar): ..."
- Cada operação sugere um identificador (derivado de `operationId`, grupo, caminho e método).

Alertas por operação (textos exatos):

- "Recebe senha/token na URL (query string): inseguro, não use para autenticação."
- "Escrita no sistema externo: fica desabilitada até liberação da conexão (aprovação) e não é repetida
  automaticamente sem reconciliação."
- "A documentação não descreve a resposta: cole um exemplo (Parâmetros de saída) ou teste em
  homologação." (é o caso do Swagger do AX hoje)
- "Operação marcada como obsoleta (deprecated)."

### O que é importado

- Métodos `GET`, `POST`, `PUT`, `PATCH` e `DELETE`; parâmetros `path`, `query` e `header` do caminho e da
  operação — **exceto o cabeçalho `Authorization`** (credencial fica na conexão).
- Corpo da requisição (`requestBody` JSON) **achatado** em caminhos: objetos viram `a.b`; objetos dentro
  de listas viram `lista[].campo` (profundidade máxima 6, até 300 campos).
- Resposta: o primeiro schema JSON encontrado entre os status `200`, `201` e `default`; se for uma lista,
  o endpoint é marcado como "resposta em lista". Sem schema documentado, o endpoint fica sem campos de
  saída (complete no passo 3).
- Tudo entra **desabilitado**.

### Reimportação (detecção de mudança por hash)

O servidor guarda um hash SHA-256 do contrato de cada endpoint (método, caminho, parâmetros, corpo e
resposta). Ao reimportar:

- endpoints novos entram desabilitados; inalterados não são regravados;
- endpoints alterados são atualizados **preservando** nome, constantes, campos de saída inferidos,
  publicação como consulta e vínculos — e ficam marcados como "contrato alterado" até revisão;
- endpoints que sumiram do documento são apenas **marcados como "removido"**, nunca apagados.

O comando `php artisan bpm:openapi-check` roda agendado **diariamente às 06:10 (UTC)** e refaz essa
comparação para conexões habilitadas com URL de OpenAPI, apenas **marcando** contratos alterados/removidos
(não aplica nada). Após revisar um endpoint marcado, use a caixa **"Revisei: manter como está"** na etapa 1
do assistente. A importação e as atualizações de contrato são auditadas (`integration.openapi_imported`,
`integration.endpoint_contract_updated`).

## Passo 3 — Aba Endpoints (fontes de dados)

A aba lista todos os endpoints com filtros ("Filtros de pesquisa": "Identificador, nome ou caminho",
"Conexão", "Grupo", "Situação" — Habilitados / Desabilitados / "Consulta nos formulários" / "Contrato
alterado/removido" — e "Tipo": "Leitura e escrita", "Leitura (GET)", "Escrita") e as colunas "Situação",
"Identificador", "Nome", "Serviço", "Grupo", "Origem", "Saída" e "Uso". Pílulas: "Habilitado" /
"Desabilitado", "Swagger" / "Manual", "definida" / "pendente" (saída), "consulta" / "escrita" / "escrita
bloqueada", "contrato alterado" / "contrato removido".

**Remoção bloqueada**: ao remover, a confirmação avisa "Remover o endpoint "<id>"? Vínculos ativos e
formulários que usam a consulta impedem a remoção." — ou seja, o endpoint em uso não pode ser removido.

### O assistente em 5 etapas

Abrir um endpoint (ou criar um manual) abre o assistente: **"Dados do serviço"**, **"Parâmetros de
entrada"**, **"Parâmetros de saída"**, **"Segurança e uso"** e **"Resumo"**, com os botões "Anterior",
"Próximo", "Finalizar" ("Salvando...") e "Fechar". Ao salvar: "Endpoint salvo."

#### Etapa 1 — Dados do serviço

- "Identificador *" (placeholder `Fornecedores.PorCnpj`), "Nome *", "Conexão", "Grupo (tag)"
  (placeholder `Fornecedores`), "Habilitado".
- "Método" e "Caminho (URL do serviço = URL base da conexão + caminho) *" (placeholder
  `/v1/Fornecedores/{CPF_CNPJ}`). Hint: "Parâmetros entre chaves (ex.: `{filter}`) viram parâmetros de
  caminho automaticamente." Em endpoints importados: "Método e caminho vêm do Swagger (operationId ...);
  para mudar, reimporte."
- "Tempo de espera (s) — vazio = da conexão (15 s)" e "Descrição". A tela mostra a "URL completa:".
- Se o contrato mudou no Swagger: "Contrato alterado no Swagger." / "Contrato removido no Swagger." com a
  orientação "Reimporte pela conexão para atualizar parâmetros/corpo, ou marque como revisado." e a caixa
  **"Revisei: manter como está"**.

#### Etapa 2 — Parâmetros de entrada

Tabela com as colunas "Identificador", "Parâmetro" (PATH, QUERY ou HEADER), "Tipo" (STRING, INTEGER,
NUMBER, BOOLEAN, DATE), "Obrig.", "Origem do valor" e "Valor constante". Origens possíveis:

- **"Entrada (formulário/processo)"** — o valor vem de quem chama (a consulta do formulário ou o vínculo
  da operação do BPM);
- **"Constante"** — valor fixo **não secreto**, sempre enviado; credenciais são recusadas aqui
  ("credencial: use a conexão").

"Adicionar parâmetro" e "remover" permitem ajustes manuais. O corpo da requisição importado aparece em
"Corpo da requisição (do Swagger)" com o aviso "O preenchimento do corpo é feito no vínculo com a
operação do BPM (Mapeamentos), campo a campo."

#### Etapa 3 — Parâmetros de saída

Texto da etapa: "Como no SoftExpert, os tipos podem vir de um exemplo de resposta. Aqui o exemplo é
guardado só como estrutura (tipos e formatos), sem os valores reais."

- "Caminho do resultado (vazio = raiz)" (placeholder `data ou result.items`) — quando a lista/objeto útil
  está dentro de um envelope;
- "A resposta é uma lista (usa o primeiro item nas consultas)";
- "Exemplo de resposta (JSON)" + **"Inferir campos do exemplo"** — até 512 KB ("Exemplo grande demais
  (máx. 512 KB)."; "JSON inválido."; "Caminho inválido (ex.: data ou result.items)."); sucesso: "N
  campo(s) inferido(s). Revise rótulos e marque o que é sensível.";
- **"Testar no servidor"** — "Chamada real pela conexão (...). Use em homologação; a resposta volta
  mascarada." Endpoint novo precisa ser salvo antes ("Salve o endpoint antes de testar.");
- A tabela de campos ("Caminho", "Rótulo", "Tipo", "Sensível") permite revisar o resultado; "Usar os N
  campo(s) desta resposta" aproveita os campos inferidos de um teste. Vazia: "Sem campos de saída. Cole
  um exemplo ou teste o endpoint."

**Sintaxe de caminhos JSON** (usada em campos de saída, caminho do resultado e mapeamentos): `a.b` desce
em objetos; `lista[].campo` e `lista[0].campo` leem **o primeiro item** da lista; `$` é a raiz;
maiúsculas/minúsculas são toleradas. Caminhos são validados por formato (letras, números, `_`, `.`, `[]`,
`-`, `$`, até 200 caracteres).

**Testar endpoint**: chama a API de verdade pelo servidor. Para endpoints de **escrita**, é preciso
marcar a confirmação "Entendo que este teste grava no sistema externo (bloqueado em produção).", a
conexão precisa ter escritas liberadas, o ambiente não pode ser "Produção", e o teste **não é
idempotente** (é uma gravação real). Durante o teste: "Chamando...". O teste é auditado
(`integration.endpoint_tested`).

#### Etapa 4 — Segurança e uso

Mostra a "Autenticação" (da conexão), a "Política de rede" ("destino permitido" ou o motivo do bloqueio),
o "Tipo" ("Leitura"/"Escrita") e, para escritas, "escritas liberadas na conexão (...)" ou "escritas
BLOQUEADAS na conexão (somente leitura)". Bloco "Registro": "Cada chamada fica em Execuções com
pedido/resposta mascarados (CPF, CNPJ, e-mail, telefone e campos marcados como sensíveis)."

**Publicação como consulta** (só leitura): marque "Disponibilizar como fonte de consulta no editor de
formulários (componente “Consulta AX/API”)" e preencha:

- "Rótulo no editor" — como a fonte aparece no editor de formulários;
- "Parâmetro que recebe o valor digitado" ("Selecione...") — o parâmetro de entrada que recebe o valor da
  consulta;
- "Campos que podem preencher o formulário" — os campos de saída liberados; campos sensíveis exigem a
  confirmação "Confirmo que o formulário pode exibir dados sensíveis (...).";
- A tela mostra a **"Chave da fonte no editor: api:&lt;identificador&gt;"** e o lembrete "O servidor refaz
  a consulta ao salvar o formulário."
- "Escritas não podem ser consulta; use-as no vínculo de uma tarefa de integração (Mapeamentos)."

#### Etapa 5 — Resumo

Consolida "Identificador", "Serviço", "Situação" ("Habilitado"/"Desabilitado"), "Entrada", "Saída" e
"Formulários" ("não publicado" quando aplicável). Revise e clique "Finalizar".

## Passo 4 — Habilitar e usar

1. Habilite o endpoint (etapa 1) e a conexão ("Conexão habilitada (chamadas reais pelo servidor)").
2. A consulta publicada `api:<identificador>` já pode ser usada no componente "Consulta AX" do editor de
   formulários — veja o [guia de consumo em formulários](guia-consumir-formulario.md).
3. Para as operações fixas do PO 159 (consultas `tpr`/`item`/`purchaseOrder`/`supplier` e a escrita
   `transferAndBlock`), crie o vínculo na aba Mapeamentos — veja o
   [guia de mapeamentos e MOCK](guia-mapeamentos-e-mock.md).
4. Acompanhe as chamadas na aba Execuções — veja o [guia de operação e suporte](guia-operacao-e-suporte.md).
