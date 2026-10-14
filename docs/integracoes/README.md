# Integrações REST — guia (Administração → Integrações e entregas)

Catálogo de integrações inspirado nas **Fontes de dados REST do SoftExpert** (prints em `.claude/rest/`),
usado principalmente para a **API do Dynamics AX 2012** (`https://apidax.tmsa.ind.br`, Swagger em
`/swagger/index.html`, documentos `/swagger/v1/swagger.json` e `/swagger/v2/swagger.json`).
Decisão: `docs/adr/0016-catalogo-de-integracoes-rest.md`.

## De SoftExpert para o BPM

| SoftExpert (Fonte de dados REST) | BPM TMSA |
|---|---|
| Identificador, Nome, Habilitado | Endpoint: identificador, nome, habilitado (aba **Endpoints**, etapa 1) |
| URL do serviço com `{parâmetros}` | Conexão (URL base) + caminho do endpoint com `{parâmetros}` (viram parâmetros PATH) |
| Tempo de espera (minutos) | Tempo limite em segundos (conexão; o endpoint pode sobrescrever) |
| Credencial | **Autenticação da conexão** (token, login do usuário de serviço ou chave de API), valor no cofre — nunca no endpoint |
| Parâmetros de entrada (método, identificador, valor constante, PATH/QUERY, tipo) | Etapa 2: igual, com **valor de entrada** ou **constante não secreta**; HEADER também; credencial como constante é recusada |
| Parâmetros de saída (exemplo de resposta → tipos) | Etapa 3: colar exemplo **ou** testar no servidor → inferência; guarda só a estrutura (sem valores reais), marca campos sensíveis |
| Segurança / Resumo | Etapas 4 e 5: autenticação, política de rede, escrita liberada ou não, publicação como consulta nos formulários, resumo |
| Uso nos formulários do SoftExpert | Consulta `api:<identificador>` no componente de consulta do editor de formulários, ou **Mapeamento** das operações do PO 159 |

> ⚠ No print `Captura de tela 2026-10-14 0033172.png` há um **token Bearer real** cadastrado como
> parâmetro constante de QUERY (e o print `003356` mostra dados pessoais de uma colaboradora no exemplo
> de resposta). Recomenda-se **revogar/rotacionar esse token** na API `api.tmsa.ind.br`, remover ou
> tarjar os prints e, no SoftExpert, mover o token para a credencial em cabeçalho.

## Passo a passo (AX 2012)

1. **Plataforma** — declarar o host (ele resolve para a rede interna 10.x):
   `BPM_INTEGRATION_ALLOWED_HOSTS=apidax.tmsa.ind.br` no ambiente da API (ex.: `deploy/docker/.env`) e
   `docker compose up -d`.
2. **Conexão** — aba *Conexões* → **Conexão da API do AX (apidax)** (pré-preenchida) → ajuste ambiente,
   usuário de serviço e senha (somente escrita) → Salvar → **Testar conexão** (faz só o login).
   Autenticação recomendada: *Login do usuário de serviço → token* (`POST /v1/User/login` com JSON
   `{userName, password}`; o token fica em cache criptografado). **Não use** `/Access` (senha na URL).
3. **Importar do Swagger** — botão na conexão → URL `…/swagger/v1/swagger.json` (ou `v2`, ou upload do
   arquivo) → **Gerar prévia**: operações por grupo, status *novo/alterado/inalterado*, alertas → marque
   o que importar (por padrão: leituras novas/alteradas) → **Importar selecionadas**. Tudo entra
   **desabilitado**.
4. **Completar a saída** (o Swagger do AX não descreve respostas) — aba *Endpoints* → abrir o endpoint →
   etapa *Parâmetros de saída*: cole um exemplo de resposta (pode ser fictício, com a mesma estrutura) e
   clique **Inferir campos**, ou **Testar endpoint** em homologação. Ajuste rótulos e marque o que é
   sensível. Defina *Caminho do resultado* se a lista vier dentro de um objeto (ex.: `data`).
5. **Usar no BPM**:
   - **Consulta nos formulários** — etapa *Segurança e uso* → publicar, escolher o parâmetro que recebe
     o valor digitado e os campos que podem preencher o formulário. No editor de formulários, o
     componente de consulta passa a listar `api:<identificador>`.
   - **Operações do PO 159** — aba *Mapeamentos* → *Mapear*: escolha o endpoint, ligue a entrada (ex.:
     `CPF_CNPJ ⇐ Valor digitado`; em escritas, `body:lines[].itemId ⇐ codigoItem`) e a saída (ex.:
     `supplierName ⇐ nome`) → *Ativar vínculo*.
6. **Habilitar** endpoint e conexão. Escritas (ex.: `CriarOrdemTransferencia`) exigem ainda **Liberar
   escritas** na conexão com a referência da aprovação.
7. **Acompanhar** — aba *Execuções*: cada chamada com resultado, HTTP, duração, pedido/resposta
   mascarados, explicação do erro e correlation ID. Escrita com **resultado incerto** (tempo esgotado
   após o envio) fica parada até **Reconciliar** ("foi feita", informando as saídas, ou "não foi feita").

## Uso nos formulários (Form.io) e escrita pelo fluxo

Análise e recomendação (padrões de GET no formulário, como montar e enviar o POST, aplicação ao fluxo
`homologacao_fornecedor` e plano em fases): [`formio-consumo-endpoints.md`](formio-consumo-endpoints.md).

## Alternativas para importar/usar o Swagger

1. **Importação assistida (implementada)** — URL ou arquivo, prévia, seleção, reimportação com detecção
   de mudança de contrato (hash) que preserva nomes/constantes/saídas/vínculos; `bpm:openapi-check`
   diário marca endpoints alterados/removidos (não aplica nada).
2. **Saída por exemplo ou teste (implementada)** — necessária hoje porque o Swagger do AX não traz as
   respostas.
3. **Pedir ao time da API do AX (recomendado)** — anotar os retornos no Swashbuckle
   (`[ProducesResponseType(typeof(Fornecedor), 200)]` + `<returns>`/comentários XML), marcar campos
   obrigatórios (`[Required]`) e retirar a senha da query de `/Access`. Com isso, reimportar já traz os
   campos de saída e a prévia mostra os obrigatórios.
4. **Gateway de APIs (futuro)** — Azure API Management/Kong podem importar o mesmo OpenAPI para
   governança (limites, chaves por consumidor, telemetria). Não substitui o mapeamento do BPM.
5. **Gerar cliente por código (não recomendado)** — OpenAPI Generator/NSwag exigem build/deploy a cada
   mudança e tiram a autonomia do administrador.

## Métodos de autenticação da conexão

| Método | O que o servidor envia | Configuração |
|---|---|---|
| Sem autenticação | nada | — |
| **Bearer token** (caso da `apidax`) | `Authorization: Bearer <token>` | token no campo de credencial (com ou sem o prefixo "Bearer "); prefixo configurável |
| Basic | `Authorization: Basic base64(usuário:senha)` | usuário + senha |
| Chave de API em cabeçalho | `<cabeçalho>: [prefixo ]<chave>` | nome do cabeçalho, prefixo opcional, chave |
| Login do usuário de serviço | `POST <caminho do login>` com usuário/senha no corpo → `Authorization: Bearer <token>` (cache criptografado) | caminho, usuário, campos, caminho do token, validade |
| OAuth2 client credentials | `POST <URL de token>` (form: client_id, client_secret, scope) → `Authorization: Bearer <access_token>` até expirar | URL https do token, client ID, scope, client secret |

- A credencial nunca é parâmetro do endpoint nem vai na URL; fica no cofre (somente escrita) ou em segredo montado (`file:<nome>`).
- **Caminho de verificação**: um GET leve (ex.: `/v1/Financeiro/Moeda`) que o **Testar conexão** chama de verdade. Sem ele, credenciais fixas aparecem como "não verificadas" (nunca um sucesso presumido); com ele, 401/403 viram "credencial recusada".
- Não suportado de propósito: chave/token na query string (fica em URLs e logs).

## Segurança (resumo)

- Chamadas só pelo servidor; o navegador nunca fala com a API do AX.
- Credenciais só na conexão (cofre, somente escrita), enviadas em cabeçalho.
- Destinos: https, sem redirecionamento, sem metadata/loopback; hosts internos e de produção só se
  declarados em `BPM_INTEGRATION_ALLOWED_HOSTS`.
- Exemplos de resposta guardados sem valores; logs com CPF/CNPJ/e-mail/telefone/sensíveis mascarados.
- Escritas bloqueadas por padrão, idempotentes, sem repetição automática em resultado incerto, nunca
  testadas em produção; SQL nunca é usado para escrever no AX.

## Referência rápida

| Item | Onde |
|---|---|
| API | `GET/POST /api/v1/admin/integrations/connections`, `…/connections/{id}/test`, `…/openapi/preview`, `…/openapi/apply`, `…/endpoints`, `…/endpoints/{id}/test`, `…/endpoints/infer`, `…/bindings/{operação}`, `…/executions`, `…/executions/{id}/reconcile` |
| Comando | `php artisan bpm:openapi-check` (agendado 06:10) |
| Código | `apps/api/app/Integrations/Rest/*` (`OpenApiImporter`, `RestCaller`, `RestIntegration`, `TargetPolicy`, `JsonPaths`), `app/Integrations/LookupRunner.php`, `IntegrationCatalogController`; SPA `apps/web/src/components/integrations/*` |
| Testes | `tests/Feature/IntegrationCatalogTest.php` (fixture sintética `tests/Fixtures/openapi-ax-sample.json`), `OpenApiImportDialog.spec.ts` |
