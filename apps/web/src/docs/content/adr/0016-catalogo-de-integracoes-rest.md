# ADR-0016 — Catálogo de integrações REST (endpoints e payloads) e importação do Swagger

- **Status:** aceito
- **Relaciona:** ADR-0006 (AX 2012), ADR-0005 (outbox/reconciliação), ADR-0014 (segredos)
- **Guia:** `docs/integracoes/README.md`

## Contexto

A maior parte das integrações com o Dynamics AX 2012 será feita pela API REST interna
`https://apidax.tmsa.ind.br` (Swashbuckle, OpenAPI 3.0.1, documentos `v1` — 68 operações — e `v2` — 6).
A TMSA já mapeia endpoints no SoftExpert ("Fontes de dados REST": identificador, nome, URL com
`{parâmetros}`, parâmetros de entrada PATH/QUERY, exemplo de resposta para tipar a saída). O BPM tinha
apenas transportes fixos por operação (MOCK) e nenhum cadastro de endpoints.

Constatações no Swagger do AX (14/10/2026): nenhuma operação documenta a resposta (0 schemas/exemplos
de 200); campos dos corpos sem `required`; `POST /Access` e `/Access/Autentica` recebem usuário e
senha na query string; autenticação `Bearer` em cabeçalho (`POST /v1/User/login` com corpo JSON).
No print do SoftExpert, um token Bearer aparece como **parâmetro constante de QUERY** — o token fica
exposto na configuração, em URLs e em logs.

## Decisão

1. **Catálogo** em três níveis, só no servidor:
   - **Conexão** (`integration_connection`): URL base, ambiente, tempo limite, autenticação
     (`none | bearer_token | basic | api_key_header | login | oauth2_client_credentials`; credencial na query
     não é oferecida), credencial no cofre (somente escrita), caminho de verificação para teste real,
     URL do OpenAPI, **escritas bloqueadas por padrão** (liberar exige referência de aprovação).
   - **Endpoint** (`integration_endpoint`, a "fonte de dados"): método, caminho com `{parâmetros}`,
     parâmetros de entrada (PATH/QUERY/HEADER; valor de entrada ou constante **não secreta**), campos
     do corpo (do OpenAPI), campos de saída (do OpenAPI ou **inferidos de um exemplo**), caminho do
     resultado, publicação opcional como consulta nos formulários (`api:<identificador>`).
   - **Vínculo** (`integration_binding`): liga operações do BPM (consultas `tpr/item/purchaseOrder/
     supplier` e a tarefa `transferAndBlock`) a um endpoint, com de/para de entrada (parâmetros e
     `body:<caminho>`) e saída (campo ⇐ caminho na resposta). Sem vínculo ativo vale o transporte
     configurado (MOCK). **Vínculo ativo com endpoint/conexão desligado falha** — nunca volta ao MOCK
     em silêncio num processo real.
2. **Importação assistida do OpenAPI 3** (URL baixada pelo servidor ou upload): prévia por grupo com
   status *novo | contrato alterado | inalterado | removido* e alertas; o administrador escolhe o que
   importar; endpoints novos entram **desabilitados**. Reimportar compara um hash de contrato
   (método, caminho, parâmetros, corpo, resposta) e preserva nome, constantes, campos inferidos,
   publicação e vínculos. `bpm:openapi-check` (diário) só **marca** endpoints com contrato alterado.
   Swagger 2.0 é recusado (exportar em OpenAPI 3).
3. **Saída sem documentação**: (a) colar exemplo de resposta (como no SoftExpert) → inferência de
   caminhos/tipos; (b) "Testar endpoint" no servidor (homologação) → resposta mascarada + inferência.
   O exemplo é guardado **só como estrutura** (tipos e formatos, ex.: "texto (18 car.)"), nunca com
   valores; campos com cara de dado pessoal (CPF, CNPJ, e-mail, telefone, nascimento, endereço…) são
   marcados como sensíveis e mascarados nos logs; publicar campo sensível num formulário exige confirmação.
4. **Segurança**: credencial nunca é parâmetro do endpoint (nomes como `Authorization`, `token`,
   `senha` recusados como constante; cabeçalhos de credencial reservados à conexão); política de destino
   anti-SSRF (`TargetPolicy`): https, sem metadata/loopback, sem usuário/senha na URL, **sem seguir
   redirecionamentos**; host que resolve para rede interna (o `apidax` resolve para 10.x) e, em produção,
   todo host precisam estar em `BPM_INTEGRATION_ALLOWED_HOSTS` (decisão de plataforma); limites de
   tamanho (resposta 2 MB, OpenAPI 5 MB) e tempo. Token do login em cache **criptografado**, renovado
   uma vez em 401.
5. **Escritas** (POST/PUT/PATCH/DELETE): exigem conexão com escrita liberada; idempotência pela chave
   da tarefa (uma escrita concluída nunca é reenviada; as saídas mapeadas ficam em `result_data`);
   **tempo esgotado após o envio = resultado incerto** (`unknown`) → não há repetição automática até a
   reconciliação manual ("foi feita" com as saídas, ou "não foi feita"). Teste de escrita só com
   confirmação explícita e nunca em conexão de produção. SQL continua proibido para escrita (ADR-0006).
6. **Execuções**: toda chamada em `integration_execution` (sistema, operação, endpoint, HTTP, duração,
   correlation ID) com pedido/resposta mascarados; explicação de cada código (`IntegrationErrors`).
7. **Permissões**: configuração `settings.manage`; execuções também `processes.manage` e `audit.read`;
   reconciliação `processes.manage`. Tudo auditado (`integration.*`).

## Alternativas consideradas para usar o Swagger

| Alternativa | Avaliação |
|---|---|
| **Importação assistida no BPM** (adotada) | Sem código, prévia, seleção, auditoria e detecção de mudança de contrato. Limitação: o Swagger do AX não traz as respostas. |
| Sincronização automática (aplicar mudanças sozinha) | Rejeitada: mudança de contrato pode quebrar processo em produção; adotado só *marcar* (`bpm:openapi-check`) e reimportar com revisão. |
| Gerar cliente por código (OpenAPI Generator/NSwag) | Rejeitada: cada mudança exige build/deploy, não dá autonomia ao administrador e não convive com o mapeamento no-code. |
| Gateway de APIs (Azure API Management, Kong) importando o OpenAPI | Futuro opcional para governança/limites/telemetria centralizados; não substitui o mapeamento de payloads do BPM. |
| Melhorar o próprio Swagger da API do AX (recomendado ao time da API) | `[ProducesResponseType(typeof(T), 200)]`/comentários XML no Swashbuckle → a importação passa a trazer os campos de saída automaticamente; marcar campos obrigatórios; remover senha da query em `/Access`. |

## Consequências

- O PO 159 pode trocar MOCK por chamadas reais operação a operação, sem deploy.
- Endpoints do AX passam a ser reutilizáveis como consultas nos formulários.
- Conexões novas exigem decisão de plataforma (host permitido) e de negócio (aprovação de escritas).
- O mapeamento de saída depende de exemplo/teste até o Swagger do AX documentar as respostas.
