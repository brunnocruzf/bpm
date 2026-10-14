# Prompt para Claude Code — customizações do BPM TMSA

> Copie o conteúdo da seção **Prompt de implementação** e use-o no Claude Code junto com o repositório do BPM TMSA. O prompt preserva as decisões arquiteturais atuais e detalha os requisitos, segurança e critérios de aceite das novas customizações.

## Prompt de implementação

Você é o engenheiro responsável por implementar a próxima fase do produto BPM TMSA. Faça as customizações abaixo no repositório existente. Não comece um sistema novo nem reescreva a arquitetura sem demonstrar uma necessidade técnica e registrar a decisão.

### 1. Comece pelo repositório e pelas decisões já aprovadas

1. Inspecione o diretório, leia `CLAUDE.md`, instruções locais, migrations, testes e documentação existente. Se estiver disponível, leia integralmente `BPMN_TMSA_Arquitetura_e_Plano_Claude_Code.md` e considere também o protótipo HTML já aprovado. Identifique o estado real do código antes de planejar alterações.
2. Preserve como baseline, salvo evidência em contrário: frontend Vue 3 + TypeScript + PrimeVue; API de produto Laravel/PHP; PostgreSQL; autenticação da aplicação com Microsoft Entra ID usando OIDC/MSAL Authorization Code + PKCE no cliente e validação de access token no Laravel; Form.io Builder/Renderer incorporado ao Vue; Flowable BPMN REST em serviço Java interno e inacessível pelo navegador; Laravel queue worker/outbox para comandos assíncronos e integrações; Docker Compose para desenvolvimento.
3. O produto usa Microsoft Dynamics AX 2012 R3. Não altere os contratos nem faça chamadas reais ao ERP sem ambiente e autorização já configurados. Mantenha limites claros entre integrações AX, Microsoft Graph e engine BPMN.
4. Primeiro apresente um plano curto por fases, os arquivos/componentes que encontrou e as lacunas de decisão. Depois implemente por entregas pequenas. Não pare depois de apenas escrever um plano ou criar telas falsas: conclua backend, migrations, UI, testes, configuração Docker e documentação dentro do escopo viável.
5. Não coloque segredos em código, frontend, logs, fixtures, documentação ou commits. Não solicite permissões Graph excessivas. Não altere dados reais de Entra, envie mensagens externas ou gere relatórios de produção durante desenvolvimento/testes; use mocks e Mailpit.

### 2. Menu Configurações e controle de acesso

Adicionar ao menu lateral uma seção **Configurações**, visível apenas para administradores autorizados. Criar uma página de administração clara, com navegação por subseções:

- Organização e identidade visual.
- Microsoft Entra ID — login da aplicação.
- Microsoft Entra ID — importação/sincronização de usuários e grupos.
- E-mail e notificações.
- Auditoria e retenção.
- Relatórios e modelos.

A UI deve indicar claramente o estado de cada configuração: configurada, incompleta, teste pendente, erro ou desabilitada. Mostrar quando foi alterada, por quem e o resultado da última validação/sincronização quando aplicável. Não mostrar segredos depois de gravados. Implementar autorização no Laravel com Policies/Gates e testes; esconder links no Vue não é controle de acesso. Separe, no mínimo, permissões para administrar configuração global, administrar processos, consultar auditoria e gerar/consultar relatórios.

A configuração de organização neste escopo é para a organização TMSA já implantada. Não introduza multi-tenancy SaaS sem requisito e aprovação explícitos.

### 3. Entra ID: autenticação versus importação do diretório

Trate como **duas integrações relacionadas, mas independentes**:

1. **Login OIDC da aplicação:** o usuário autentica-se com Entra via SPA/MSAL; o Laravel valida o access token destinado à API.
2. **Importação de usuários/grupos:** um serviço backend agendado/acionado pelo administrador usa Microsoft Graph com credencial de aplicação. Essa sincronização não autentica pessoas, não deve depender da sessão de um administrador e não pode ser executada no browser.

#### 3.1 Configuração do login

Criar em Configurações uma tela guiada com os parâmetros não secretos necessários e checklist para configurar o App Registration no Entra:

- Tenant ID e autoridade/host de identidade aplicável ao tenant.
- Client ID da aplicação SPA e redirect URI(s), incluindo ambiente local, homologação e produção conforme o ambiente configurado.
- Identificador/audience da API TMSA, scope delegado exposto pela API e consentimento concedido à SPA.
- Logout URI e origens/domínios autorizados.
- Issuer, audience e política de tenant que o Laravel deve validar.
- Estado da configuração, última verificação e erros sem revelar tokens.

Não tente criar ou editar App Registrations no tenant por meio do produto; essa ação exigiria permissões de administração do diretório e não faz parte do requisito. Forneça instruções/checklist para o administrador Entra configurar redirect URIs, expor o scope da API e conceder consentimento. Client ID e tenant ID não são segredos; client secrets/chaves privadas são. Não use ID token como bearer de API e não coloque credencial confidencial no Vue.

A conexão só pode ser marcada como ativa após validação compatível com o fluxo real do projeto: descoberta OIDC/issuer do tenant, validação das configurações esperadas e teste de login/token em ambiente de desenvolvimento/homologação. Não crie um falso botão “testar” que apenas retorna sucesso fixo.

#### 3.2 Importação e sincronização de usuários e grupos

Criar um assistente de configuração e sincronização do Microsoft Graph. Ele deve:

- Distinguir configuração do tenant, Client ID do app de sincronização, tipo/referência de credencial, permissões esperadas, grupos permitidos para sincronizar, filtros, mapeamento de papéis TMSA e agenda.
- Preferir, em produção, certificado, workload identity federation ou identidade gerenciada quando compatível com o ambiente. Segredo de cliente é alternativa somente quando a organização aprovar e houver cofre/abstração de segredos. Não guardar segredo em texto puro no banco nem retorná-lo pela API. Não aceitar credencial no bundle SPA.
- Usar permissões de aplicação somente de leitura, com consentimento de administrador do tenant. Começar por `User.Read.All` para usuários e `Group.Read.All` para grupos/membros, validando as permissões mínimas específicas dos endpoints usados. Não pedir `Directory.Read.All` por padrão; só propor uma permissão mais ampla com justificativa, aprovação e revisão de segurança. Nunca pedir permissões de escrita no diretório para essa função. [1] [2]
- Incluir um checklist de App Registration separado do registro de login: Microsoft Graph → Application permissions estritamente necessárias → grant de admin consent. O produto não deve elevar as próprias permissões nem tentar obter consentimento administrativo silenciosamente.
- Sincronizar usuários, grupos e memberships necessários a partir de Graph v1.0. Fazer carga inicial paginada e, quando suportado pelos recursos/seleções adotados, sincronização incremental com delta query. Persistir `@odata.nextLink` apenas durante a paginação e o `@odata.deltaLink` somente depois de processar com sucesso toda a rodada. Tratar token delta inválido com recarga controlada, throttling/429 respeitando `Retry-After`, backoff, timeouts, interrupção e retomada. Não presumir que todos os campos ou alterações de membership aparecem em uma única resposta delta: validar endpoints, `$select` e limitações na POC, reconciliando memberships quando necessário. [3] [4]
- Usar como chave externa estável o `tenantId + Entra oid/object id`, não e-mail ou UPN. Atualizar atributos alterados sem criar duplicatas. Mapear nome, UPN, e-mail, departamento e estado habilitado somente conforme política e disponibilidade. Registrar grupos por object ID e membership com a origem.
- Deixar configurável quais usuários/grupos importar; excluir guests ou contas desabilitadas por padrão até decisão explícita da área, mas preservar histórico e referências BPM. Desativação/exclusão no diretório deve desabilitar login/atribuição futura no TMSA; jamais apagar submissões, autoria histórica ou eventos de auditoria.
- Permitir mapear grupos Entra a papéis/grupos de autorização TMSA por regra explícita e revisável. Importar um grupo não concede automaticamente papel privilegiado. Negar por padrão se não houver mapeamento; impedir que dados enviados pelo browser escolham grupos/candidate users privilegiados.
- Ter ação **Sincronizar agora**, modo **prévia/dry run**, configuração de periodicidade, status em andamento/concluído/parcial/falha, início/fim/duração, totais de criados/atualizados/desabilitados/inalterados, erros por registro sem token/dados sensíveis, cursor delta e próxima execução. Suportar repetição idempotente.
- Em falha parcial, não desativar em massa todo o diretório local nem avançar o checkpoint delta. Mostrar erro legível e permitir retomar/reconciliar. Alertar em caso de credencial inválida, consentimento ausente, cursor expirado ou ausência de sincronização por período configurável.

Criar um adapter `EntraGraphDirectorySync`/equivalente e um serviço de domínio testável; não espalhar chamadas Graph em controllers. Usar HTTP client resiliente e mockável, fila Laravel para trabalhos longos, logs redigidos e tabela de execuções/cursors. Fazer scheduler com lock para evitar sincronizações simultâneas.

### 4. Provedor de e-mail e SMTP

Criar em **Configurações → E-mail e notificações** uma configuração selecionável de provedor que suporte, no mínimo:

- **SMTP personalizado**, com host, porta, criptografia (STARTTLS/TLS/SSL conforme suporte), autenticação, usuário, segredo/senha, remetente, nome do remetente, reply-to, timeout e opções compatíveis com a versão Laravel/transport adotada.
- **Microsoft 365 / Exchange Online**, somente pelo método realmente suportado e aprovado no tenant: SMTP AUTH com OAuth quando permitido, ou adapter Microsoft Graph se aprovado e com permissões mínimas justificadas. Não presumir que SMTP AUTH por usuário/senha esteja habilitado; não exigir nem armazenar senha pessoal de mailbox. Documentar os requisitos que o administrador do tenant precisa habilitar.
- Adaptadores de provedores transacionais/API que a equipe aprovar (p. ex. SES, SendGrid, Mailgun, Postmark) sem codificar segredos no frontend; priorizar o transport já suportado oficialmente pelo Laravel ou por pacote mantido após licença/security review.
- **Mailpit somente em desenvolvimento/teste**; impedir que seja escolhido como provedor em produção.

A UI deve apresentar os campos corretos para o tipo selecionado, remetente e reply-to, estado, máscara de segredos salvos, botão **Testar conexão** e envio de um e-mail de teste para o endereço digitado pelo administrador. Confirmar resultado, tempo, código de erro sanitizado e data do teste. Não executar o teste implicitamente ao salvar. Não retornar senha, token, private key ou Authorization header na API. Usar segredo armazenado em secret manager ou storage criptografado com uma abstração de chave; nunca salvar credenciais em JSON sem criptografia. Em execução local com Compose, documentar uso de Docker secrets/arquivos montados ou mecanismo equivalente; `.env` é apenas desenvolvimento e não deve ser commitado.

Validar TLS/certificado por padrão, tratar conexões/timeout e prevenir SSRF por configurações de host: restringir protocolos e portas; aplicar política de egress/allowlist em produção; não permitir destinos de metadata endpoints. Se a organização usar um relay privado interno, permitir exceção deliberada, documentada e restrita ao administrador de plataforma. Não desabilitar validação TLS para “fazer funcionar”.

Implementar envio somente por Laravel queue/worker e padrão outbox. Registrar estados queued/sending/sent/failed/retrying, contagem/data de tentativas, provider message ID quando disponível e erro sanitizado. Aplicar retry com backoff e idempotency key; falhas permanentes vão para estado de erro/dead-letter consultável. Não bloquear a submissão BPMN esperando resposta do SMTP.

### 5. Identidade visual da organização

Criar **Configurações → Organização e identidade visual** com:

- Nome exibido da organização/aplicação.
- Upload de logo principal, logo compacto e favicon, com pré-visualização em cabeçalho claro/escuro e telas de login.
- Cores primária, secundária e de destaque; superfícies/fundos apenas se necessários ao sistema de design.
- Pré-visualização antes de aplicar e botão para restaurar tema padrão.
- Uso centralizado de CSS custom properties/design tokens. Não deixar páginas componentes essenciais com cores hard-coded que ignorem a configuração.
- Validação de formato/tamanho, dimensões, malware/arquivo inválido e upload seguro. Preferir PNG/WebP. Se aceitar SVG, sanitizá-lo antes de servir e remover scripts/links externos/elementos ativos. Armazenar os assets no storage já adotado (privado/público controlado), versionar referências e servir pelo domínio da aplicação.
- Verificar contraste WCAG de texto e controles sobre as cores configuradas, mostrar aviso e evitar aplicar combinação que torne controles ilegíveis. Preservar foco visível, navegação por teclado e estados de erro/sucesso.

Gravar cada alteração de marca em auditoria, inclusive ator, data e diff seguro. A atualização visual não pode substituir cores de semântica de estado sem acessibilidade adequada.

### 6. Auditoria de alterações e eventos do sistema

Implementar uma trilha de auditoria robusta, pesquisável e separada de logs técnicos de aplicação. A trilha deve ser append-only no domínio da aplicação, com autorização restrita, paginação e filtros. Não permitir que usuário comum altere ou apague registros. Não confundir auditoria funcional com logs de debug/stack trace do Laravel.

Cada evento deve ter, conforme aplicável:

- ID e data/hora UTC; organização; tipo/categoria/nome estável do evento; resultado (sucesso/falha/bloqueado); actor Entra `oid` e nome/UPN capturado para exibição, ou actor de sistema/worker identificado sem fingir usuário humano.
- Objeto afetado: tipo, ID e nome legível. Em BPM: `flow_id`, versão, `processInstanceId` do Flowable, protocolo, `stage_id`, `task_id`, formulário/schema version e ligação aos eventos correlatos.
- `correlation_id`, `causation_id`, idempotency key/outbox ID quando disponível; rota HTTP/operação, origem e IP conforme política corporativa; user agent somente se necessário e aprovado.
- Para mudança em configuração/definição BPMN/schema: diff antes/depois com valores sensíveis redigidos, autor, versão anterior/nova e resultado. Preferir diff estruturado a cópia completa de payload.
- Para execução: início/fim de processo; criação/atribuição/claim/reassignment/delegação de tarefa; entrada/saída de etapa; submissão, devolução, cancelamento e conclusão; decisão e branch escolhido; mudança de prazo/status; envio/anexo por referência e metadados; integração disparada/confirmada/erro/reconciliação; comunicação/notificação queued/enviada/falha/retry; importação Graph; login/alteração de papel; mudança de branding, Entra, e-mail e templates; geração, consulta, impressão/download/exportação de relatório.

Cobrir logs em Laravel, Flowable REST, Graph, AX adapters, fila/outbox, notificações e relatórios via eventos correlacionados. O evento de auditoria deve ser persistido junto da transação local de domínio quando possível. Eventos vindos do Flowable/outros sistemas devem passar por um handler idempotente. Não afirmar atomicidade distribuída entre Laravel, Flowable, Graph, AX, SMTP ou Carbone.

Proteger informações: **nunca** registrar access/ID/refresh token, client secret, SMTP password, private key, cabeçalho Authorization, segredo do Carbone, conteúdo de arquivo ou corpo completo de request/response externo. Em formulário submetido, registrar protocolo, etapa/schema, ator, momento, resultado, correlação e metadados. Logar conteúdo de campos apenas quando a política/necessidade permitir e o campo não estiver marcado como PII/sensível; caso contrário usar redaction/máscara. Não expor submission values em logs de debug.

Criar tela **Auditoria** para perfis autorizados, com filtros por período, ator, evento, objeto, processo, instância/protocolo, etapa, resultado, correlation ID; detalhe com diff redigido e linha do tempo correlacionada; export controlado de CSV/JSON; paginação/ordenação segura. A exportação precisa gerar evento de auditoria próprio. Definir retenção configurável com aprovação de privacidade/jurídico/segurança; não inventar prazo legal. Criar política de backup/arquivamento e controles para impedir alteração/apagamento ordinário. Se propuser encadeamento criptográfico/hash-chain, explique custo, rotação e verificação; não o use como substituto para controle de acesso, backup ou evidência imutável.

Implementar `AuditEvent`/`AuditService` central com eventos tipados e redação central, evitando logging ad hoc em cada controller. Incluir migração/índices sem travar tabela de domínio existente e estratégia de retenção compatível com volume previsto.

### 7. Notificar responsável quando uma etapa receber formulário

Em **Editor BPMN → Etapas do processo → tarefa humana**, adicionar a opção:

> **Enviar e-mail ao responsável quando esta etapa receber uma nova tarefa**

A opção configura a notificação na etapa de destino (responsável que preencherá aquele formulário). Quando o fluxo começar ou quando a etapa anterior for submetida e o BPMN criar/atribuir a próxima tarefa, resolver o caminho e o responsável efetivo primeiro; então, se a opção da próxima etapa estiver habilitada, enfileirar a mensagem. Em gateway, notificar somente a etapa realmente ativada, nunca os caminhos não escolhidos. Em tarefas reatribuídas, aplicar a política configurada sem duplicar envios ao mesmo destinatário/transição.

Configuração por etapa deve oferecer:

- Toggle habilitado/desabilitado, desligado por padrão em processos novos até o responsável e o conteúdo serem configurados.
- Destinatário: usuário/grupo Entra autorizado que seja responsável pela tarefa; permitir grupo como assignment. Definir política de expansão de grupo aprovada (membros elegíveis atuais ou mailbox de grupo configurada), deduplicar destinatários e exibir prévia da lista. Não aceitar e-mails arbitrários vindos de variável de formulário sem validação/allowlist.
- Assunto e corpo editáveis com placeholders permitidos e documentados (ex.: nome do fluxo, protocolo, nome da etapa, nome do responsável, prazo se configurado, URL protegida da tarefa). Não executar PHP/JS/liquid arbitrário no template. Escapar conteúdo e bloquear inclusão de PII não autorizada.
- Pré-visualização e envio de teste somente para administrador; usar ambiente de teste.

E-mail deve conter resumo mínimo e link para abrir a tarefa autenticada; não incluir valores completos do formulário nem anexos confidenciais por padrão. Se houver falha no envio, a tarefa permanece válida e visível na inbox, com status de entrega/retry para administradores. Usar outbox transacional, fila, idempotência por `processInstance + task + recipient + notification type`, retry/backoff e auditoria. Ao submeter um formulário, confirmar que a notificação subsequente é disparada somente depois da transição BPMN confirmada/reconciliada; não enviar e-mail antes de saber que a nova tarefa foi criada.

### 8. Relatórios, impressão e visualização de qualquer etapa

Criar um módulo **Relatórios** seguro e completo. A pessoa autorizada deve poder abrir uma instância e visualizar/imprimir:

1. O resumo e a linha do tempo completos do fluxo.
2. Uma etapa específica e o conteúdo do formulário daquela etapa.
3. Uma seleção de várias etapas ou todas as etapas.
4. O estado histórico correspondente ao momento da submissão, mesmo se houver edição/atualização de dados em outra etapa depois.

Mostrar versão do processo/formulário, protocolo, solicitante, datas, responsável, status, decisões/branch escolhido, dados submetidos, histórico de atribuição e anexos permitidos. Marcar claramente campos vazios, omitidos ou indisponíveis por autorização. Não misturar em relatório dados atuais do diretório/flow com os snapshots que formam o histórico do processo. Respeitar policies, acesso por processo/grupo e marcação de campos sensíveis; esconder/mascarar dados conforme permissão do usuário. Imprimir não deve contornar autorização. Logar visualização, geração, download, impressão e exportação quando permitido pela política.

Adicionar:

- Tela de catálogo/execução de relatórios com filtros por fluxo, versão, protocolo, área, status, intervalo de abertura/fechamento, etapa, responsável e outros filtros já suportados pela API.
- Visualização prévia no browser (preferir PDF renderizado) antes de imprimir; opção de imprimir etapa atual ou relatório completo; geração PDF e formatos tabulares aprovados (CSV/XLSX) para dados agregados quando aplicável.
- Relatórios operacionais: instâncias abertas/concluídas/canceladas, aging/tempo por etapa, pendências por grupo/responsável, prazos/SLA se definidos, erros/reprocessamentos de integração e notificações; os cálculos e fusos/intervalos devem ser consistentes e testados.
- Modelos de documento versionados, título/cabeçalho/logo organizacional, paginação, data/hora/fuso, indicação de conteúdo confidencial quando necessário e histórico de quem gerou cada arquivo.
- Geração assíncrona por fila para relatórios grandes, estados queued/rendering/ready/failed/expired, feedback de progresso, retry seguro, limite de tamanho/tempo e retenção de artefatos configurável. Links de download expiram ou exigem autorização do Laravel; arquivos privados não podem ser servidos por URL pública permanente.

#### 8.1 Avaliação e integração do Carbone

Avalie o Carbone como motor de template/geração, mas **não o descreva como licença open source permissiva sem revisão**. A documentação oficial informa que a Community Edition usa o CCL Agreement, inicia sem licença com recursos de Community, e recursos Enterprise exigem licença. Confirme compatibilidade do CCL com o uso interno pretendido e com a distribuição/hosting do BPM TMSA junto ao jurídico/segurança antes de selecionar recursos ou imagem. Não embuta chave/licença em imagem ou Git. Se o CCL ou algum recurso necessário não for aprovado, pare a decisão de produto, mantenha a interface `ReportRenderer` e apresente alternativa(s) avaliadas quanto a formatos, qualidade, manutenção, segurança e licença; não introduza um pacote aleatório sem análise. [5] [6]

Se aprovado:

- Executar Carbone self-hosted em container versionado e rede interna, não publicar a porta externa. Escolher imagem Docker que inclua dependências necessárias para PDF; confirmar na POC se LibreOffice/convertidor e fonts são necessários para formatos selecionados. Configurar limites CPU/memória, timeout, healthcheck, autenticação service-to-service e métricas. [5] [7]
- Laravel permanece dono dos dados e autorização. Só Laravel compõe um DTO/JSON seguro do relatório e chama o renderer. Carbone não recebe credenciais de banco nem acessa AX, Graph, Flowable ou storage irrestritamente.
- Guardar modelos aprovados e versionados em storage controlado. Validar formato/tamanho, bloquear macros/conteúdo ativo quando aplicável, limitar placeholders/funções, impedir path traversal/template injection e isolar conversão.
- Usar a API de renderização suportada pela versão escolhida, configurar output format explicitamente e tratar resposta/timeout/erro. Evitar payloads ilimitados e report rendering direto dentro da requisição interativa. [6]
- Criar `ReportRenderer` no Laravel para não acoplar controllers ao fornecedor e incluir testes de contrato. Armazenar PDFs gerados em storage privado com metadados de job e expiração.

Não construir interface para consultas SQL livres nem aceitar script de relatório fornecido por usuário de negócio no MVP. O usuário escolhe campos e filtros apenas de um catálogo de dados aprovado; para modelos sofisticados, administração de template exige permissão específica e revisão.

### 9. Modelo de dados e APIs

Inspecione o modelo existente antes de criar tabelas. Ajuste nomes ao padrão do repositório, mas cubra ao menos estas responsabilidades, sem duplicar dados já modelados:

- Configurações organizacionais tipadas (branding e referências de configuração), com segredo separado/criptografado e versionamento.
- Credenciais e estado da integração Entra, separado para login e Graph. Guardar apenas metadata da credencial/referência segura no domínio; nunca segredo legível.
- Execuções de sincronização, checkpoint opaque de delta query, usuários/grupos importados por object ID e memberships, com estado de desativação e timestamps.
- Configuração/versionamento do mail provider; notification templates por etapa; outbox de envio e tentativas/status.
- Eventos append-only de auditoria e correlation IDs.
- Catálogo/versões de modelos de relatório, jobs de geração, artifacts e permissões/expiração.

Ajuste OpenAPI e validação de payloads. Endpoints de referência (adapte à convenção existente, não duplique endpoints):

- `GET/PUT /api/admin/settings/entra/auth` e `GET/PUT /api/admin/settings/entra/directory-sync`.
- `POST /api/admin/entra/sync/preview`, `POST /api/admin/entra/sync/run`, `GET /api/admin/entra/sync/runs`.
- `GET/PUT /api/admin/settings/email`, `POST /api/admin/settings/email/test`.
- `GET/PUT /api/admin/settings/branding` e endpoint autorizado de upload/preview de logo.
- `GET /api/admin/audit-events` e endpoint de detalhe/exportação com permissão própria.
- `POST /api/instances/{id}/reports` ou endpoint equivalente para gerar relatório por instância/etapas; `GET /api/reports/{id}` para acompanhar o job; download protegido e auditado.

Usar Form Requests, Resources, Policies, validação server-side, API versioning compatível, rate limit e idempotency nos comandos/retries. Erros da UI devem ser úteis; logs internos não devem expor secrets nem corpos sensíveis.

### 10. Docker Compose e variáveis de ambiente

Atualize Docker/compose somente após confirmar os serviços atuais. Manter Flowable, Carbone, PostgreSQL, API, worker, scheduler, proxy e storage nas redes mínimas necessárias:

- Carbone, se licenciado/aprovado, fica em rede interna e é acessado só por Laravel worker/API com credencial service-to-service.
- Não publicar portas de Flowable, Carbone, Postgres, Redis ou SMTP no host além do estritamente necessário ao perfil local.
- Mailpit somente em profile `dev/test`, sem possibilidade de entrega externa; documentar as portas locais.
- Segredos via secrets/secret manager no ambiente produtivo. `.env.example` tem nomes e placeholders fictícios. Não imprimir secrets em entrypoint/healthcheck.
- Fixar versões/digests de imagens, healthchecks, volumes, backup/restore e recursos. Adicionar migrations e seeders sem dados reais.
- Registrar no `README` como habilitar cada integração em modo local/mock, homologação e produção, quais recursos dependem de admin consent do tenant e como realizar rollback.

### 11. Testes obrigatórios e definição de pronto

Implemente testes unitários, de integração, autorização e E2E coerentes com a stack real. No mínimo:

**Entra/Graph**
- Access token/login separado de app-only Graph sync; teste de validação de issuer/audience/tenant, token expirado e claim inválida.
- Importação inicial paginada, carga delta, `nextLink`, checkpoint, deletions/tombstones, membership, throttling 429/Retry-After, timeout, consentimento ausente, credential invalid, delta token expirado, falha no meio e recuperação idempotente.
- Falha parcial nunca desativa todos os usuários nem avança cursor; usuário histórico não é apagado; grupo importado sem role mapping não ganha privilégios.
- Provar que chamada Graph é do backend e nunca da SPA.

**E-mail/notificação**
- Validação da configuração por tipo de provider; secret mascarado e não presente em response/log; configuração inválida e TLS failure.
- Teste com Mailpit em dev, sem envio externo. Teste de fila, retry/backoff, idempotency, dead-letter e correlation ID.
- Fluxo Start envia apenas para responsável da primeira tarefa se toggle habilitado; submissão seguida de gateway notifica somente o grupo/usuário da etapa efetivamente aberta; falha de e-mail não desfaz tarefa; sem destinatário não vaza para endereços arbitrários.

**Branding/auditoria**
- Upload rejeita tipos/tamanhos inseguros e sanitiza SVG se suportado. Tokens CSS refletem tema; contraste e foco permanecem acessíveis.
- Mudanças em processos, etapas, schemas, roles, Entra, e-mail, branding, sync, notificação, relatório, assignment/submission e ações de Flowable criam audit events correlacionados.
- Redaction cobre secrets, tokens, PII marcada e corpos externos; controller/perfil sem permissão não lê/exporta logs; usuário comum não atualiza/exclui eventos.

**Relatórios**
- Relatório de uma etapa, múltiplas etapas e processo completo; snapshot da submissão; campo sensível respeita a autorização; versão histórica renderiza com modelo de formulário/schema correto.
- PDF preview/impressão e formato tabular aprovado; template inválido, render timeout, fila/retry, artifact expirado, link não autorizado e download auditado.
- Contract test do Carbone/renderer mock e teste integrado no container/licença realmente escolhidos.

**Qualidade geral**
- Rodar formatters, linter, PHPUnit/Pest, PHPStan/Larastan, Vitest e Playwright (ou equivalentes encontrados no projeto), migrations fresh e testes Compose em execução. Não alegar sucesso se algum comando não rodou; informar claramente bloqueios.
- Atualizar documentação técnica, `.env.example`, OpenAPI, instruções de operação/segurança, limitações de licença, runbook de sincronização, runbook de e-mail e guia de geração/retention de relatórios.

A funcionalidade só está concluída quando um administrador autorizado consegue configurar (ou validar) as integrações, executar prévia/sync mock, alterar tema, consultar trilha de auditoria, configurar notificação por etapa e gerar/visualizar/imprimir relatório autorizado sem vazar dados ou secrets; os fluxos continuam operando quando Graph, mail provider ou Carbone estão temporariamente indisponíveis.

### 12. Saída esperada do Claude Code

Ao concluir, apresente:

1. Resumo das alterações por módulo e paths principais.
2. Migrations/tabelas e novas rotas/endpoints.
3. Decisões tomadas sobre app registrations, permissões Graph, provider e renderer; destaque pendências que exigem administrador Entra, jurídico/licenciamento, segurança ou infraestrutura.
4. Configuração Docker e comandos para rodar localmente com mocks/Mailpit.
5. Testes executados e resultados reais.
6. Riscos, limitações e sequência recomendada para homologação — sem afirmar que Entra, AX, SMTP corporativo ou Carbone de produção foram conectados se apenas mocks foram usados.

Não faça deploy, não configure credenciais reais, não importe o diretório de produção, não envie mensagens externas, não rode relatórios com dados reais e não mude permissões de tenant como parte desta tarefa. Prepare instruções para que os administradores autorizados façam essas etapas controladamente.

## Referências oficiais para validação durante a implementação

[1]: https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-client-creds-grant-flow "Microsoft Learn — OAuth 2.0 client credentials flow"
[2]: https://learn.microsoft.com/en-us/graph/permissions-reference "Microsoft Learn — Microsoft Graph permissions reference"
[3]: https://learn.microsoft.com/en-us/graph/delta-query-users "Microsoft Learn — Get incremental changes for users"
[4]: https://learn.microsoft.com/en-us/graph/delta-query-groups "Microsoft Learn — Get incremental changes for groups"
[5]: https://carbone.io/documentation/developer/on-premise-installation/licensing.html "Carbone — On-premise licensing"
[6]: https://carbone.io/documentation/developer/http-api/generate-reports.html "Carbone — Generate reports HTTP API"
[7]: https://carbone.io/documentation/developer/self-hosted-deployment/deploy-with-docker.html "Carbone — Deploy with Docker"
[8]: https://github.com/carboneio/carbone "Carbone — Official repository and Community Edition terms"
