# BPMN TMSA — Arquitetura e plano de implementação

> Documento de handoff para desenvolvimento com Claude Code. O produto deve permitir que usuários de negócio configurem processos por etapas, publiquem versões e acompanhem formulários e tarefas humanas. O primeiro fluxo de produção será **Item Reprovado no Recebimento (PO 159)**.
>
> **Decisão arquitetural revisada:** Laravel é a API e camada de produto; Flowable Open Source é o engine BPMN em serviço Java separado, acessado por REST somente na rede interna. A stack contempla Vue, Form.io client-side, Laravel/PHP, Flowable REST, PostgreSQL e Docker. Não adotar n8n como motor principal. ProcessMaker é mais próximo de um BPM humano pronto, mas precisa de avaliação comercial/hospedagem; n8n pode servir a automações periféricas após revisão de licença, não como verdade transacional do processo. A separação Laravel–Flowable é escolhida pela familiaridade potencial do time com Laravel, assumindo outbox, fila e reconciliação para consistência entre serviços. [1] [2] [3] [16] [17] [18]

## 1. Objetivo do produto

Criar um sistema corporativo de gestão de processos (BPM/TMSA) para substituir o módulo Workflows do SoftExpert no escopo definido pela TMSA. O sistema precisa ser simples para o usuário criar fluxos em etapas e formular tarefas sem escrever código, mas manter BPMN 2.0 executável, trilha de auditoria, controle de acesso e integrações rastreáveis.

O primeiro caso pretende padronizar a devolução e tratativa de itens não conformes detectados no recebimento. A especificação aponta que o processo atual pode levar até seis meses e busca reduzir variação e atrasos com informações padronizadas e entregues no momento necessário. Não assumir uma meta quantitativa de redução de lead time sem validação da área.

## 2. Leitura dos anexos e requisitos observados

Os anexos usados como fonte são:

- **Protótipo manuscrito:** menu lateral BPM TMSA, categorias aparentes “Compras” e “Qualidade”; listagem de processos com ação “Novo”, “Acompanhar” e acesso a “Resultados/Respostas”; ao selecionar um processo, abre formulário. Usar isso como orientação inicial de navegação, não como especificação final de UX.
- **Especificação funcional PO 159 — Item reprovado no recebimento**, datada de 08/07/2026: descreve campos, atividades numeradas, raias, avisos e regras condicionais.
- **`document.send.php.pdf`:** diagrama visual de ponta a ponta com raias, eventos, gateways, retornos, tarefas automáticas e caminhos de exceção. A imagem está em baixa resolução para ler integralmente cada anotação lateral; os requisitos de campo e regras deste plano são transcritos prioritariamente da especificação textual. Deve haver uma oficina de validação do BPMN legível antes de produção.

### 2.1 Requisitos transcritos da especificação

**Grid de instâncias:** número sequencial + ano; nome do solicitante; área do solicitante; data do registro. Recomenda-se acrescentar estado, etapa atual, responsável, idade da tarefa, prazo, prioridade e filtros sem substituir os quatro campos exigidos.

**Abertura — CQ/Recebimento, atividade 01 (“Item Reprovado no Recebimento”):** Nº CAN (texto, obrigatório); TPR (texto, obrigatório); Cliente (preenchimento via API associada ao TPR); código do item (texto, obrigatório); descrição do material (preenchida por API associada ao código do item); NF (número, obrigatório); ordem de compra (texto/número, obrigatória, usada em API). Ao finalizar, notificar/encaminhar para PPCPM atividade 05.

**Análise prévia — PPCPM:**

- **05 — Define tipo de item:** obrigatório; opções documentadas: tipos 01 (MP/componente comprado) → SUP; tipos 03 e 09 (industrializado/manufaturado com OP) → PPCPM. A especificação diz que uma futura integração SENF pode depender dessa classificação; não implementar essa integração automaticamente nesta entrega.
- **06 — É urgente?:** obrigatório, Sim/Não. Se Sim, mostrar data prazo (07) e justificativa (08); ao concluir o ramo urgente, encaminhar para Comitê. Se Não, encaminhar para GEF (11).
- **09 — Avaliação de urgência pelo Comitê:** texto de avaliação, avaliador e data; após conclusão, notificar CQ/PPCPM e criar/encaminhar tarefa GEF (11).
- **10 — Avaliação de não procedência pelo Comitê:** texto, avaliador e data; tarefa secundária quando a não procedência retorna do fornecedor; notificar CQ/PPCPM e GEF. Detalhar o ponto exato de entrada/retorno em oficina de processo.

**GEF — atividade 11 (“Gerar RACF”):** emissor e área do usuário logado; número RACF sequencial/ano; data automática; CAN, TPR, código e descrição do material, NF e OC copiados da atividade 01; prazo de resposta; código do fornecedor e fornecedor/contato/telefone/e-mail consultados por API; decisão Devolução/ Retrabalho/ Liberado condicional; responsável por liberação condicional (puxado das avaliações 09/10, conforme especificação); reincidente Sim/Não; data de resposta do fornecedor; observações; descrição do problema. Se liberação condicional, exigir o responsável correspondente. A conclusão encaminha para Suprimentos/Fornecedor conforme fluxograma detalhado, a confirmar com o diagrama legível.

**Fornecedor e avaliação da não procedência:**

- **24 — Análise de RACF pelo fornecedor:** “Procede?” Sim/Não; se Não, justificativa obrigatória (25); encaminhar GEF (26).
- **26 — GEF concorda com a NP?:** se Sim, encerrar processo e notificar CQ/Recebimento e Suprimentos. Se Não, exigir “Necessita avaliação do comitê?” (27/28): Sim → Comitê (atividade 10); Não → habilitar edição do RACF na GEF (11) e retornar ao fluxo 29.
- **29 — Fornecedor, “Procede?”:** se Sim, avisar SUP e abrir trilha paralela para análise de causa raiz e devolução (30, 33/34); o paralelismo e o sincronismo entre trilhas precisam estar explícitos no BPMN. Se Não, seguir tratamento de não procedência descrito acima.
- **30 — Analisar o RACF:** identificação da causa raiz; plano de ação corretivo. Cada ação precisa ter ação, responsável, data-prazo, local, observações e anexo de evidência; notificar GEF (31).
- **30.1 — Executar plano de ação:** cada ação é executada e concluída individualmente; só liberar “Finalizar tarefa” quando todas as ações obrigatórias estiverem concluídas. Incluir decisão “Necessita ação preventiva?”: se Sim, campos “O quê, Quem, Quando, Onde e Observação”; se Não, justificativa. A modelagem exata entre pergunta preventiva e a atividade 30.1 requer validação, pois a tabela associa requisitos de ambos ao mesmo item.
- **31/32 — Validação GEF:** “Valida análise RACF?” Sim → liberar plano para execução pelo fornecedor (30.1); Não → GEF edita RACF na tarefa 11 e retorna ao fornecedor (30). Essa volta não pode apagar o histórico anterior.
- **30.2 — Verificar eficácia do plano:** Sim → encerrar o processo do plano de ação; Não → gerar tarefa de monitoramento de fornecimentos.
- **30.3 — GEF monitora fornecimentos:** Sim → encerrar; Não → repetir o monitoramento em loop com registros de cada avaliação, sem criar ciclo tecnicamente infinito sem prazo/critério. Definir SLA, razão de monitoramento e regra de encerramento manual/cancelamento.

**Devolução/garantia, expedição e faturamento:**

- **33/34 — Fornecedor:** Garantia ou Devolução. Devolução → SUP atividade 45. Garantia → decisão 35/36.
- **35 — Garantia: quem envia primeiro o material NC?** TMSA ou Fornecedor. Se TMSA: para tipo 01, seguir SUP atividade 38; para tipos 03/09, PPCPM atividade 37. Se Fornecedor: informar NF e anexar documento, depois Fiscal atividade 39.
- **37 — PPCPM abre SENF:** número SENF; finaliza subprocesso/ramo conforme documento.
- **38 — Suprimentos abre SENF:** número SENF; finaliza subprocesso/ramo conforme documento.
- **39 — Fiscal gera OCDev no AX:** número OCDev; encaminha Qualidade atividade 40.
- **40 — Separação, entrega e transferência — Qualidade:** entregue por = usuário logado; número do diário de transferência. O diário e a marcação da OCDev na OC de origem são descritos como automáticos no AX; não solicitar ação manual da Qualidade para tarefas automatizáveis. Marcação OCDev com OC de origem (checkbox/resultado); item deve ficar bloqueado para não atender outra necessidade. Encaminha Expedição (41).
- **41/42 — Expedição define frete:** CIF-TMSA ou FOB-Fornecedor. CIF abre data de coleta, peso e volume. FOB guarda data e agenda aviso/timer vinculado à data (validar SLA e política de fuso). Depois, “Solicita emissão da NF” (43) encaminha Faturamento (44).
- **44 — Faturamento:** número NF; finaliza etapa e notifica PPCPM e Expedição.
- **45 — Suprimentos abre OCDev:** número OCDev e anexo PDF OCDev; pergunta “Necessita NF espelho?” (46). Sim → Faturamento atividade 47; Não → Expedição atividade 40.
- **47 — Faturamento emite NF espelho:** número NF e anexo do documento; notificar fornecedor; fim.

**Glossário:** CAN = Certificado de análise; NF espelho = NF emitida na base de apoio AX; MP = matéria-prima; OP = ordem de produção; RACF = Relatório de ação corretiva de fornecedor; NC = não conformidade; NP = não procedente; SENF = Solicitação de emissão de nota fiscal; OCDev = Ordem de compra de devolução; OC = ordem de compra; CIF/FOB definem responsabilidade de frete.

### 2.2 Pontos de validação funcional antes do aceite

A especificação não define com precisão: lista completa de funções/grupos por raia; quem é o fornecedor como usuário externo e como ele se autentica; se fornecedores terão acesso ao portal; condições da atividade 10; mensagens/notificações exatas; horários de SLA, feriados e calendário; campos completos da imagem BPMN; contrato/endpoints/autenticação das APIs ERP/AX; tamanho, tipo, retenção e classificação de anexos; regra de duplicidade de CAN/TPR/NF/OC; como versionar/alterar instâncias em execução; quais atividades da AX já têm API; critérios para fechar loops de monitoramento; e requisitos de auditoria/retensão legal. Registrar decisões num log de ADRs e numa matriz requisito→teste.

## 3. Decisões de arquitetura

### 3.1 Motor e avaliação das opções citadas

**n8n não é o motor principal.** A documentação o posiciona como ferramenta de automação de workflows e documenta hospedagem Docker/PostgreSQL; porém, a licença Sustainable Use restringe determinados usos a negócio interno/pessoal e exige licença comercial separada para disponibilizar n8n como parte de um produto. Como BPM TMSA é produto próprio voltado a designers e usuários finais, não embutir ou revender n8n sem revisão jurídica e acordo comercial. Usá-lo como componente de integrações só se a TMSA confirmar que o caso de uso e licenciamento permitem. [4] [5]

**ProcessMaker é mais próximo de um BPM humano pronto.** A documentação atual descreve formulários/tarefas atribuíveis a usuário/grupo e configuração de telas em atividades humanas, o que se alinha às etapas preenchidas por CQ, PPCPM, GEF, Comitê, fornecedor, Suprimentos e Faturamento. Mas o material oficial consultado caracteriza a plataforma atual como cloud-based; não presumir que a edição/plano será instalável em Docker ou que terá condições comerciais/licença compatíveis com um produto customizado. Solicitar proposta, confirmação de deployment (SaaS/on-prem), direito de integração via API, limites e exportação de BPMN/dados antes de optar por ela. [6] [7]

**Escolha proposta revisada:** usar **Laravel como API de produto** e **Flowable como runtime BPMN separado, acessado somente pela REST API interna**. Laravel é responsável por catálogo, usuários/roles, autorização, formulários e submissões, versionamento, auditoria, integrações AX, inbox/projeções e experiência de produto. Flowable executa BPMN, tarefas humanas, gateways, timers e histórico de engine. O `bpmn-js` fornece edição visual; não é runtime. Esta separação é apropriada se a equipe TMSA domina PHP/Laravel e aceita operar dois runtimes (PHP e Java). Validar versão/licença e compatibilidade em POC. [1] [2] [16]

**Por que Laravel é uma opção válida:** Laravel oferece roteamento REST, validação, migrations, ORM, policies/gates e filas/workers; a API não precisa ser escrita na mesma linguagem do engine. Flowable expõe uma REST API que permite integração externa. A contrapartida é uma fronteira distribuída entre Laravel e o engine: não há transação SQL única entre os dois, então comandos de start/complete precisam de outbox, idempotência operacional e reconciliação. Se a equipe não domina PHP, ou se transações engine+domínio estritamente atômicas forem requisito central, a aplicação Spring Boot com Flowable embutido continua tecnicamente mais simples. Não escolher Spring apenas porque Flowable é Java; selecionar conforme habilidade do time e decisão de arquitetura. [16] [17] [18]

Esta recomendação evita acoplar o runtime ao modelo de uso de uma ferramenta de automação e preserva a separação entre definição BPMN e experiência de produto. **Não se afirma equivalência funcional entre Flowable e o Workflows do SoftExpert** sem protótipo, testes de exceções, comparação de permissões e validação com usuários.

### 3.2 Stack sugerida

| Camada | Decisão inicial | Observações |
|---|---|---|
| Frontend | Vue 3 + TypeScript + Vite + PrimeVue + Vue Router + Pinia + MSAL Browser | PrimeVue para DataTable, filtros, upload, dialogs e componentes de backoffice. Manter componentes e tema próprios. Alternativa: shadcn-vue se a prioridade for controle visual e a equipe aceitar montar mais componentes. [9] [10] |
| Form builder | Form.io `formio.js` Builder + Renderer, embutidos em componentes Vue | Usuário de negócio cria formulários arrastando campos; builder produz schema JSON e renderer exibe formulários das tarefas. Usar as bibliotecas client-side revisadas, não acoplar o armazenamento de dados do formulário ao Form.io Server. Salvar schema/version pelo backend TMSA. A página oficial lista `formio.js` e a integração Vue como bibliotecas MIT, enquanto o core API/server é OSL 3.0; revisar dependências e licença antes de distribuir. [12] [13] |
| Modelador BPMN | `bpmn-js` em módulo Vue isolado | Diagrama BPMN 2.0 editável, propriedades por painel lateral e extensões para forms, assignment e connector tasks. Usar BPMN XML como artefato versionado. [8] |
| Backend/API de produto | Laravel API (PHP) + Policies/Gates + Form Requests + API Resources | Frontend fala apenas com Laravel. Validar JWT Entra no middleware, aplicar autorização contextual no servidor, OpenAPI e migrations Laravel. Usar versão de Laravel/PHP suportada e fixada em ADR. [17] [18] |
| Runtime BPMN | Flowable REST em serviço/container interno separado | Flowable persiste/avança BPMN, tarefas, gateways e timers. Laravel chama endpoints service-to-service; acesso direto do browser e publicação do engine na internet são proibidos. A versão específica da aplicação REST e autenticação precisa ser verificada em POC. [16] |
| Banco relacional | PostgreSQL | DB/schema e credenciais distintos para Laravel e Flowable, mesmo que partilhem instância PostgreSQL no piloto; migrations Laravel e schema engine controlados por Flowable. Nunca permitir que Laravel faça DML em tabelas internas do engine. |
| Arquivos | S3 compatível; MinIO em dev/POC, storage corporativo aprovado em produção | Metadados e permissões no SQL; binários fora do banco. Antivírus, política de extensão/tamanho e URL assinada curta. |
| Login e identidade | Microsoft Entra ID; MSAL no SPA, JWT verificado no backend | SPA com Authorization Code + PKCE + OIDC; usar bibliotecas MSAL. API verifica issuer, audience, assinatura, tenant e scopes/roles; não confiar somente em claims do frontend. [11] |
| E-mail | SMTP corporativo ou Microsoft Graph, via interface de notificação | Segredos em secrets manager. Notificações não são mecanismo de autorização. |
| Proxy/TLS | Nginx ou Traefik | HTTPS externo; encaminhar apenas para frontend/API. Flowable e PostgreSQL não expostos publicamente. |
| Observabilidade | Laravel logs estruturados + OpenTelemetry, Prometheus/Grafana e centralização de logs | Incluir correlation ID entre API, Flowable REST, integração e notificação; mascarar PII e dados de tokens. |
| Teste/qualidade | Pest/PHPUnit, PHPStan/Larastan, Pint, Playwright, Vitest, ESLint/Prettier | Testes da API, contratos Flowable REST e cenários BPMN com engine/container real em integração. |

**Versões:** especificar tags imutáveis e versões suportadas no primeiro ADR; não usar `latest` em produção. Usar imagens oficiais/verificadas e revisar SBOM, CVEs e licença de todas as dependências.

### 3.3 Visão de implantação

```text
Usuário ── HTTPS ──> Nginx/Traefik ──> Vue SPA
                             └────────> Laravel API ── rede interna ──> Flowable REST
                                               ├── PostgreSQL (DB/schema app)
                                               ├── fila/outbox + worker Laravel
                                               ├── storage S3/MinIO (anexos)
                                               ├── APIs AX/ERP/cadastros (saída controlada)
                                               └── Microsoft Entra ID / SMTP / Graph
                                                                      └── PostgreSQL (DB/schema engine)
```

O frontend acessa exclusivamente Laravel; Laravel acessa Flowable REST com credencial técnica armazenada em secret manager e rede privada. Usar banco/roles separados para app e engine e não compartilhar entidades através de SQL. Submissão de formulário/auditoria pertence ao TMSA; histórico de execução BPMN pertence ao Flowable e pode ser projetado/sincronizado para consulta. APIs externas são acessadas por adaptadores com timeout, retentativa limitada e circuit breaker.

**Consistência distribuída obrigatória:** ao iniciar fluxo ou submeter tarefa, Laravel grava submissão validada, auditoria e `engine_command_outbox` na mesma transação local. Um Laravel queue worker consome o outbox e chama Flowable REST; registra `PENDING → SENDING → CONFIRMED` ou `RECONCILIATION_REQUIRED/FAILED`, com correlation ID/chave idempotente. Timeout de resposta é ambíguo: antes de repetir conclusão, consultar Flowable e reconciliar se a tarefa/instância já avançou. A interface só marca a transição BPMN como concluída após confirmação; enquanto pendente, exibe “Solicitação recebida/Processando”. Um job periódico reconcilia comandos/projeções. POC deve testar crash/retry e resposta perdida sem criar instância/tarefa duplicada. Laravel e Flowable não compartilham transação ACID; não prometer atomicidade entre seus bancos.

## 4. Experiência de usuário e modelagem simples

### 4.1 Navegação

Reproduzir a intenção do protótipo em layout responsivo:

- Menu lateral com catálogo de processos por área/categoria (ex.: Compras, Qualidade), favoritos e administrações permitidas.
- Página do catálogo com nome/descrição do processo, ação **Novo**, e por linha **Acompanhar** e **Resultados/Respostas** de acordo com autorização.
- Inbox **Minhas tarefas**, **Tarefas do meu grupo**, **Solicitações abertas por mim**, histórico e pesquisa.
- Página da instância com número/ano, status, linha do tempo de etapas, responsável, prazo, formulário atual, anexos, comentários e histórico de decisões.

### 4.2 Configurador em etapas (MVP)

Não exigir que um usuário de negócio escreva BPMN XML. O modo fácil deve ser um fluxo guiado em três áreas sincronizadas:

1. **Etapas:** lista ordenável de cartões “Quem faz?”, “O que precisa preencher?” e “O que ocorre depois?”. Ações “Adicionar etapa”, “Adicionar decisão”, “Adicionar integração”, “Adicionar timer” e “Adicionar fim”.
2. **Formulário da etapa:** integrar o **Form.io Form Builder** diretamente à área Vue, habilitando drag-and-drop dos componentes compatíveis (texto curto/longo, número, data, sim/não, lista, arquivo, layout e repetidor de ações). Permitir editar label, key técnica, help text, required, default, read-only, validações e conditional logic. Builder e renderer consomem e produzem schema JSON; schema e submissions são persistidos/validados pelo backend TMSA, não no Form.io API Server. Ao clonar/criar versões, preservar `key` e IDs técnicos estáveis. [12] [13]
3. **Roteamento:** editor em linguagem simples, como “Se urgente = Sim → Comitê; se Não → GEF”. Apresentar preview do diagrama BPMN e problemas como etapa sem saída, gateway com saída sem condição, campo referenciado inexistente ou papel não configurado.

Em paralelo, disponibilizar aba **Diagrama BPMN** para analista avançado, alimentada por `bpmn-js`, com lanes e gateways. A etapa BPMN referencia a versão imutável do schema Form.io; chaves/tipos de campos usados em gateway devem ser verificados contra esse schema. Criar adaptadores Vue compatíveis para usuário/grupo Entra, lookup AX e anexos TMSA; componentes customizados devem serializar dados sem executar código do designer. Registrar no XML extensões namespaced TMSA para metadados de forms, atribuição e conectores. Validar schema e BPMN no servidor antes de deployment. O modelador precisa importar/exportar BPMN XML e JSON Form.io, impedindo elementos/componentes não suportados pela versão do produto com erro explicativo. [8] [12]

**Segurança do designer:** expressões permitidas por lista branca e parser (sem executar JavaScript livre, SQL ou SpEL arbitrário digitado pelo designer). Para condições, suportar operadores básicos: igual/diferente, vazio/não vazio, contém, maior/menor e AND/OR com limites. Para chamadas externas, configurar integração versionada com credenciais fora do BPMN.

### 4.3 Organização e criação guiada: Fluxos → Etapas → Formulários

**Hierarquia de negócio:** um **Fluxo** é a definição/processo que aparece no catálogo; contém uma **versão** editável; a versão contém **etapas** (tarefas humanas, integrações, decisões e eventos); cada etapa humana possui um **formulário** associado àquela etapa e àquela versão. Diagrama BPMN representa sequência/ramificações e lanes; ordem de cartão ajuda a editar, mas não substitui as sequence flows do BPMN. Um formulário não é globalmente ligado a todos os fluxos: ele é um schema versionado vinculado a uma etapa específica. Permitir “usar como modelo” por cópia, criando schema novo e independente, para evitar alteração acidental de formulários já publicados.

**Regra obrigatória de criação:** ao pressionar **Novo fluxo**, preencher nome, chave sugerida, categoria e descrição, a API cria em uma operação lógica: (a) cadastro do fluxo, (b) versão 1 em rascunho, (c) etapa humana inicial `Etapa 1` ligada ao evento Start, (d) schema vazio `Formulário da Etapa 1` associado à etapa, e (e) caminho BPMN inicial Start → Etapa 1 → End em rascunho. Assim o designer abre pronto para edição, sem exigir que o usuário descubra separadamente como criar/associar um form. A etapa inicial pode ser renomeada/removida com aviso; o formulário é obrigatório para tarefas humanas editáveis, mas não para gateways, timers e service tasks.

**Wireframe funcional de referência (inspirado no protótipo enviado):**

```text
┌────────────────────────────── BPM TMSA ────────────────────────────────────┐
│ Fluxos > [Nome do fluxo]   Rascunho v1      [Validar] [Testar] [Publicar]   │
├───────────────────┬────────────────────────────────┬───────────────────────┤
│ ETAPAS            │ CONFIGURAÇÃO DA ETAPA          │ FORMULÁRIO             │
│                   │                                │                        │
│ 1. Etapa 1        │ Nome: Etapa 1                  │ Form: Etapa 1          │
│    Form: ligado   │ Tipo: Tarefa humana            │ [+ Texto] [+ Data]     │
│ 2. + Adicionar    │ Responsável: escolher grupo    │ [+ Lista] [+ Arquivo]  │
│                   │ Próxima: etapa/decisão          │ Canvas drag-and-drop   │
│                   │ [Abrir diagrama BPMN]           │ [Salvar formulário]    │
├───────────────────┴────────────────────────────────┴───────────────────────┤
│ [Salvar rascunho]     [Preview como usuário]             [Histórico]        │
└────────────────────────────────────────────────────────────────────────────┘
```

**Percurso do usuário:**

1. **Catálogo → Novo fluxo:** abre wizard curto (nome, área/categoria, descrição e chave). Ao confirmar, navega para o editor do rascunho; primeira etapa e formulário vazio já existem e mostram o vínculo.
2. **Etapas:** painel lateral lista cartões numerados com tipo, responsável e badge de formulário (ex.: “Form associado”/“Configurar formulário”). Botão **Adicionar etapa** cria uma tarefa humana e um schema vazio, já associados; botão **Adicionar decisão** cria gateway sem formulário; integração/timer/fim criam elementos BPMN do tipo correspondente. Drag reorder atualiza ordem visual e sequência linear padrão somente; para caminhos condicionais, abrir editor “Próxima etapa” ou diagrama.
3. **Selecionar uma etapa humana:** área central mostra propriedades e formulário associado. O usuário pode abrir **Editar formulário** sem sair da etapa; Form.io Builder salva o schema no `form_schema_version` da etapa/versão atual. Não permitir selecionar/usar silenciosamente schema de outra etapa.
4. **Formulários:** dentro do editor do fluxo, oferecer aba/lista **Formulários** agrupada por etapa (Etapa 1 → Formulário da Etapa 1). Selecionar form leva à etapa proprietária. Renomear a etapa pode sugerir atualização do título, mas não muda `formKey` técnico. Permitir duplicar de biblioteca/modelo; após duplicação, obter novo ID/schema independente e vincular explicitamente à etapa destino.
5. **Diagrama/Fluxo:** aba visual BPMN mostra Start, etapas, decisões, integrações e End. Clicar em user task abre etapa e form vinculados. Import/export somente com validação do subset suportado. A tela de runtime “Acompanhar” continua separada do designer e mostra execução real, histórico e respostas, conforme protótipo.
6. **Salvar, validar, publicar:** salvar rascunho não publica. Validação verifica: cada tarefa humana tem um formulário válido; nenhum form está órfão sem etapa; chaves únicas no fluxo; campos condicionais/gateways referenciam propriedades existentes; todos os elementos BPMN têm saída/caminho de término válido; atribuições e roles existem. Publicação congela versão BPMN + etapas + schemas; instância iniciada usa exatamente esses snapshots.

**Modelo relacional mínimo e invariantes:** `process_catalog 1:N process_definition_version`; `process_definition_version 1:N process_stage_version`; `process_stage_version 0..1:1 form_schema_version` para etapa não humana e `1:1` para etapa humana que exige entrada; `form_schema_version 1:N form_submission`. `process_stage_version` guarda `stage_key` estável, BPMN `element_id`, nome para exibição, `stage_type`, lane/role, posição de apresentação, `form_schema_version_id` opcional, versão e estado. BPMN XML/IDs de gateways e sequence flows definem grafo real. Incluir FK/unique constraints e validação transacional na criação para nunca persistir fluxo vazio sem etapa/form inicial.

### 4.4 Publicação e versões

Estados da definição: rascunho → validação/teste → publicada → suspensa/retirada. Toda publicação cria versão imutável, BPMN XML, formulário, mapeamento de integração, hash, autor, data e resultado de validação. Instâncias iniciadas ficam vinculadas à versão. A migração de instância entre versões será funcionalidade administrativa controlada, fora do MVP, exceto se a POC provar uma estratégia segura. Não editar artefatos publicados em lugar; clonar em nova versão. Guardar histórico de mudanças e aprovação de publicação por usuário distinto do autor, se política corporativa exigir.

## 5. Modelo de domínio e dados

Manter dados de processo em variáveis tipadas do Flowable e espelhar consultas de negócio relevantes em entidades próprias. Não gravar PII/documentos sem finalidade e retenção definidas.

Entidades de aplicação sugeridas:

- `process_catalog`: nome, chave, categoria, descrição, estado.
- `process_definition_version`: chave externa, versão, BPMN XML, metadados/mapeamento de conectores, status, hash, autor/publicador e datas; schemas dos forms vivem em `form_schema_version`.
- `process_stage_version`: versão do fluxo, `stage_key` estável, BPMN `element_id`, nome de exibição, tipo (user task/gateway/service/timer), lane/role, posição visual, form schema opcional, estado.
- `form_schema_version`: referência à definição/tarefa, JSON Schema Form.io, versão do renderer, hash, estado e compatibilidade; imutável após publicação.
- `process_instance`: ID Flowable, número legível, requester Entra object ID, nome/área como snapshot, definição/versão, status de negócio e timestamps.
- `task_projection`: ID Flowable, nome/etapa, candidato/grupo, responsável, criada/em andamento/concluída, vencimento, SLA e projection version. Manter consistência via eventos/outbox e reconciliador.
- `form_submission`: tarefa, snapshot do schema da etapa, payload JSON validado, autor, timestamp, versão, revisão; preservar histórico em vez de sobrescrever decisões.
- `audit_event`: ator, tenant, ação, entidade, timestamp UTC, correlation ID, campos alterados com mascaramento; append-only na aplicação.
- `attachment`: bucket/key aleatória, nome original sanitizado, content type detectado, tamanho/hash, uploader, ACL/referência, status antivírus e retention date.
- `supplier_action`: causa raiz e cada ação (corretiva/preventiva, responsável, prazo, local, observações, status, evidências).
- `integration_execution`: sistema/endpoint lógico, operação, request/response mascarados, resultado, tentativa, duração, idempotency key; evitar guardar segredos ou conteúdo sensível em logs.
- `engine_command_outbox`: ID idempotente, comando start/deploy/complete, instância/tarefa, payload mínimo seguro, estado, tentativa, correlation ID, resposta/erro mascarado e timestamps; comando pendente é reconciliável, sem incluir segredo.
- `sequence_counter`: chave de sequência por tipo e ano; atribuição transacional, sem `MAX(numero)+1`.
- `notification_outbox`: evento, destino lógico, canal, estado, tentativa, erro sanitizado e timestamps.

Chaves/identificadores: UUID para IDs internos. Para a instância inicial, proposta `IRR-000001/26`; RACF `RACF-000001/26`; implementar contador anual transacional e formato com ano de quatro dígitos internamente, com apresentação conforme regra corporativa. A especificação cita exemplo `/26`; aprovar máscara final e política para virada de ano. O número da instância e o RACF são sequências distintas. Não compor o ID a partir de relógio do browser.

Área/nome do solicitante são snapshot da abertura para histórico, além de IDs corporativos; reavaliar retenção. Grupo, usuário e área não devem ser definidos por texto livre quando houver diretório corporativo.

## 6. Identidade, autorização e acesso externo

### 6.1 Entra ID

1. Registrar SPA e API como aplicações separadas no tenant corporativo; não incluir `client_secret` na SPA.
2. Configurar redirect URI SPA para Authorization Code com PKCE; usar MSAL.js e bibliotecas oficiais. Solicitar access token para audience/scopes da API, nunca reutilizar ID token como bearer de API. Laravel deve validar assinatura via JWKS, issuer, audience, tenant e expiração com middleware/guard de resource server OIDC ou pacote PHP revisado; não aceitar JWT apenas porque veio do browser. Sanctum não substitui validação dos access tokens Entra. A Microsoft recomenda Authorization Code + PKCE e OIDC para SPA. [11]
3. Configurar app roles ou grupos corporativos: `Bpm.Admin`, `Bpm.Designer`, `Bpm.Publisher`, `Bpm.Initiator`, `Bpm.TaskWorker`, `Bpm.Auditor`, com grupos/áreas definidos pela TI.
4. Na API, validar assinatura, issuer, audience, tenant autorizado, validade e roles/scopes. Implementar autorização por definição, instância, tarefa e dado; negar por padrão. Aplicar filtros de ownership/candidate groups em cada consulta e transição.
5. Mapear usuário por `oid` + `tid` (não usar e-mail como chave primária). Capturar nome e área apenas para display/snapshot; não inferir privilégio do nome de grupo no frontend.
6. Flowable identity não deve competir com Entra como fonte de identidade. Laravel valida JWT/claims e resolve usuário/grupos autorizados. Sincronizar ou mapear IDs estáveis (Entra `oid`/IDs de grupo) no formato que o Flowable REST escolhido exigir; autenticação service-to-service Laravel→Flowable fica separada da identidade do usuário final. O serviço engine não deve estar acessível ao browser nem aceitar identidade/candidate group escolhidos livremente pelo cliente.

### 6.2 Fornecedor externo

O PDF descreve tarefas para fornecedor, mas não define autenticação externa. **Não abrir login de fornecedores por Entra corporativo sem confirmar que são usuários do tenant.** Alternativas a decidir com segurança: portal externo com Entra External ID/B2B; acesso federado do próprio fornecedor; ou tarefas respondidas via canal externo autenticado, com token de uso único e prazo. Para primeira entrega, recomendar usuário fornecedor como identidade externa gerenciada, MFA, segregação estrita por instância, expiração e auditoria. Não enviar detalhes sensíveis por link sem autenticação reforçada.

## 7. Integrações com Microsoft Dynamics AX 2012 R3

### 7.1 Adaptadores

Criar interface `IntegrationAdapter` no backend, com adaptadores independentes e contratos versionados para:

- TPR → dados de cliente.
- Código do item → descrição do material.
- Ordem de compra → consulta de dados e elegibilidade.
- Código do fornecedor → fornecedor, contato, telefone e e-mail.
- AX 2012 R3: consultas de TPR/cliente, item/material, ordem de compra e fornecedor; e operações aprovadas de diário de transferência, OCDev, marcação da OCDev na OC de origem, bloqueio do item e dados de NF espelho.

### 7.2 Padrões suportáveis e escolha de transporte

**Não presumir que AX 2012 R3 exponha OData/REST padrão.** A documentação Microsoft fornecida identifica a release AX 2012 R3; a documentação técnica arquivada de AX 2012 descreve Services/AIF e integration ports baseados em Windows Communication Foundation (WCF), com serviços hospedados em AOS/IIS. Isso não equivale ao endpoint OData REST padrão documentado para Dynamics 365 Finance & Operations. Para AX 2012 R3, confirmar build/CU, portas habilitadas e contratos reais com o administrador AX. [14] [15]

Ordem de preferência:

1. **Serviços AIF/WCF ou custom services do AX** para operações de negócio e escritas. Usar documentação/serviço publicado pela equipe AX, contratos e mensagens aprovados; se consumidores TMSA exigirem REST/JSON, inserir uma façade corporativa REST (middleware/API gateway ou serviço adaptador) que autentica e traduz para o WCF/AIF. A façade aplica autenticação, mapeamento, idempotência, validação, logging e controle de taxa. Não chamar tabelas físicas para simular comandos de negócio.
2. **APIs REST já existentes na TMSA** que façam a façade sobre AX. A especificação funcional diz “API”, mas não fornece URL, autenticação ou schema. Pedir OpenAPI/contratos, exemplos sanitizados, limites, ambientes de teste e owner técnico. Para cada consulta, identificar se o dado vem de API corporativa ou diretamente de AX; registrar a fonte na configuração.
3. **Acesso SQL somente para leitura**, quando aprovado por DBA/owner AX e adequado ao dado: preferir réplica/ambiente de reporting, view de integração ou staging publicado. Conceder usuário read-only e views allowlisted, sem consulta livre às tabelas transacionais pelo app. Usar isso apenas para lookups/leitura se não houver serviço viável; monitorar carga, consistência/atraso e dependência do modelo físico. Não gravar/atualizar/inserir/deletar tabelas do AX diretamente. Escritas em diário, OCDev, bloqueio, movimentação ou emissão devem passar por serviços que executem lógica e validações AX.

**Decisão inicial para PO 159:** manter um único adapter AX com seleção de transporte configurável por operação (`AIF_WCF`, `REST_FACADE` ou `SQL_READONLY_VIEW`); começar com mocks até obter diagnóstico do ambiente. Autorizar SQL apenas para reads aprovados. Para transações que alteram estoque, compras ou financeiro, usar AIF/WCF/serviço corporativo REST. O acesso direto SQL não substitui a API de negócio.

Separar endpoint/host, autenticação e identidade técnica, certificado, timeout, política de retry e mapeamento de campos por ambiente. Para WCF/AIF, permitir configuração de binding/endpoint e certificados conforme instalação; proteger portas e preferir tráfego interno, sem expor serviços AX na internet. Para REST, usar OAuth/mTLS/API key conforme padrão aprovado e rotação segura. Para SQL read-only, guardar connection string no backend secret manager, firewall allowlist e usuário sem privilégios de escrita. Não confiar em payload retornado sem validação de formato/empresa/partição.

A especificação nomeia APIs, mas não contém contrato técnico; nenhuma integração específica pode ser declarada pronta sem documentação do ERP. Se integração AX estiver indisponível, gerar uma tarefa humana com protocolo/evidência e retentativa autorizada, sem preencher silenciosamente valores ou marcar operação como concluída.

### 7.3 Tratamento confiável de integrações e consultas SQL

- HTTP connect/read timeout configuráveis, limites de payload, TLS confiável e validação de certificados.
- Retry exponencial apenas em erros transitórios/429/5xx e somente para operações idempotentes; nunca repetir automaticamente operação de criação sem chave de idempotência.
- Circuit breaker e fila/outbox para ações que não devem bloquear toda transação humana.
- Registrar correlation ID e sistema; mascarar dados pessoais e não registrar headers de autenticação.
- Mapeamento de erros para mensagens simples: sistema indisponível, dado não encontrado, acesso negado, conflito/duplicidade. Manter estado “integração pendente” com opção autorizada de retentativa.
- Consultas de autocomplete sob demanda, com botão de reconsulta e confirmação dos dados retornados; guardar snapshot, origem e timestamp.
- Escritas externas devem ser registradas com idempotency key e reconciliação manual/automatizada.
- Lookups SQL devem ter timeout, limites de linha, colunas explicitamente selecionadas, query parametrizada e métricas de latência/carga. Não concatenar input de formulário em SQL.
- Para AIF/WCF, validar fault codes e correlação de mensagem; não tratar HTTP 200/SOAP response como sucesso de negócio sem verificar resposta/estado AX.
- Criar tabela de mapeamento por operação: campo TMSA, entidade/serviço AX, direção, obrigatório, conversão/unidade, chave natural, comportamento em erro e dono AX.

## 8. Esqueleto do BPMN PO 159

A versão de trabalho deve ter Pool “Item Reprovado no Recebimento” e lanes por CQ/Recebimento, PPCPM, Comitê, GEF, Fornecedor, SUP/Suprimentos, Fiscal, Qualidade, Expedição e Faturamento, conforme a especificação e o diagrama. Papéis concretos e associação de grupos ficam parametrizados por ambiente.

### 8.1 Caminho principal e subprocessos

1. **Start → 01 CQ/Recebimento:** abrir solicitação, buscar dados cadastrais pelo adaptador AX configurado (REST façade corporativa, serviço AIF/WCF ou consulta SQL read-only aprovada), validar obrigatórios e salvar. Criar número IRR e snapshot do solicitante/área/data. `submission` de abertura inicia processo transacionalmente.
2. **05 PPCPM:** selecionar tipo 01/03/09. Marcar rota derivada; enviar para gateway por tipo. A classificação de tipo não executa SENF automaticamente nesta versão.
3. **06 PPCPM:** gateway exclusivo urgente? Não → encaminhar para GEF/11. Sim → exigir 07 data prazo e 08 justificativa, criar tarefa 09 Comitê; após avaliação, retornar/avisar e seguir para GEF/11. Detalhar se urgência do Comitê é aprovação, parecer ou apenas registro.
4. **11 GEF:** compor RACF automático, completar prazo resposta, decisão imediata (Devolução, Retrabalho, Liberado condicional), reincidência, resposta do fornecedor e descrição. A atribuição do próximo ator depende de regra aprovada; encaminhar ao fornecedor quando dados e destinatário estiverem resolvidos.
5. **24 Fornecedor → 26 GEF:** `procede?`. Não pede justificativa. GEF concorda com NP? Sim → fim como não procedente; notificar CQ e SUP. Não → decisão 27/28. Se exigir comitê, criar atividade 10 e voltar ao ponto explicitamente aprovado. Se não exigir, retornar GEF 11 para correção e seguir 29. Cada retorno mantém as submissões anteriores.
6. **29 Fornecedor:** avaliação de procedência. Sim → iniciar subprocessos de análise de causa/plano de ação e decisão de garantia/devolução; encaminhar informação para SUP. Não → aplicar fluxo de contestação 24–28 após esclarecer duplicidade/ponto de retorno no modelo.
7. **30 análise de causa/ações:** permitir uma ou mais ações com prazo, responsável, local, evidência, observação e ação preventiva. Cada execução tem estado próprio; o formulário não encerra até todas concluírem. GEF 31 valida; se não aprova, retorno a 11/30. Se aprova, fornecedor executa 30.1. GEF avalia eficácia 30.2; ineficaz → 30.3 monitoramento com loop e limite de escalonamento. Encerrar subprocesso e/ou instância conforme decisão final explícita.
8. **33/34:** garantia versus devolução. Garantia: tarefa 35 determina quem envia primeiro; ramo por tipo 01 → SUP 38; 03/09 → PPCPM 37; fornecedor primeiro → captura NF/anexo e segue Fiscal 39. Devolução → SUP 45.
9. **37/38 SENF:** capturar número e finalizar o ramo indicado, sem encerrar a instância principal se outras trilhas ainda estiverem ativas. Validar se a especificação diz fim do subprocesso ou do processo completo.
10. **39–44, trilha AX e transporte:** Fiscal solicita criação de OCDev por serviço de negócio AIF/WCF ou API REST corporativa sobre AX; Qualidade confirma diário automatizado e bloqueio do item somente após resposta de sucesso do AX; Expedição escolhe CIF/FOB e coleta os dados de CIF ou data de FOB; solicita NF; Faturamento informa NF; notificar PPCPM e Expedição. Escritas AX precisam de idempotência/reconciliação; SQL nunca cria OCDev, movimenta estoque ou bloqueia item.
11. **45–47:** SUP informa OCDev e anexa PDF; decisão NF espelho? Não → Expedição/Qualidade 40; Sim → Faturamento emite número e anexa NF espelho; notificar fornecedor; término.
12. **End events:** manter resultados distintos (concluído, não procedente, cancelado, encerrado sem ação, erro/pendência de integração, encerramento administrativo); mapear status simples de negócio para relatório.

### 8.2 Gateways, timers e paralelismo

Usar gateway exclusivo para decisões Sim/Não e classificação tipo 01/03/09. Usar gateway paralelo apenas onde duas trilhas precisam efetivamente executar concorrentemente; incluir ponto de sincronização antes de terminar se ambas forem necessárias. Em timers, armazenar timezone corporativo e data/hora como UTC; enviar avisos antes do vencimento, escalonar somente conforme política aprovada e não preencher decisão automaticamente. Aplicar limite/cancelamento no ciclo 30.3.

### 8.3 Formulários e validações

Todas as tarefas que alteram o processo precisam de schema versionado e validação servidor-side. Condicionais de visibilidade no formulário melhoram UX, mas não substituem validação de negócio no backend. Campos automáticos são `readOnly`; registrar fonte e momento do lookup. Campos “obrigatório se Sim” são condicionais e documentados em testes. Para evidências, permitir anexos por ação individual e anexos gerais; verificar extensão, MIME real, tamanho, antivírus e acesso.

## 9. API de produto proposta

Base `/api/v1`, JSON, OpenAPI, paginação cursor/offset definida, erros RFC 7807/problem details, correlation ID e idempotency key em mutações críticas.

- `GET /catalog/processes` — catálogo permitido ao usuário.
- `POST /flows` — cria fluxo, versão inicial em rascunho, etapa humana `Etapa 1` e formulário vazio associados, e BPMN inicial; executar com integridade transacional na base TMSA.
- `GET /definitions/{key}/versions` — versões permitidas.
- `POST /definitions/{key}/versions` — salvar rascunho BPMN/formulários.
- `PUT /definitions/{key}/versions/{version}/forms/{formKey}` — salvar/atualizar schema JSON Form.io apenas enquanto a versão estiver em rascunho; aplicar RBAC e validação do catálogo de componentes.
- `GET /definitions/{key}/versions/{version}/forms/{formKey}` — retornar somente schema publicado/autorizado para builder/renderer; não retornar credenciais nem URL administrativa.
- `POST /definitions/{key}/versions/{version}/validate` — validar schema, BPMN, papéis, rotas, conectores e campos.
- `POST /definitions/{key}/versions/{version}/publish` — publicar conforme role de publisher.
- `POST /process-instances` — iniciar instância publicada; exigir idempotency key.
- `GET /process-instances` — grid filtrado segundo autorização; inclui dados resumidos.
- `GET /process-instances/{id}` — detalhes, permissões e histórico projetado.
- `GET /tasks?scope=mine|groups` — tarefas acessíveis ao usuário/grupos.
- `POST /tasks/{id}/claim` — reclamar tarefa, se aplicável.
- `POST /tasks/{id}/complete` — validar tarefa, atribuição, payload e ETag; gravar submission/auditoria/outbox na transação local; responder com estado do comando, sem alegar atomicidade distribuída.
- `POST /tasks/{id}/reassign` — somente com role e trilha de justificativa.
- `POST /tasks/{id}/attachments` e `GET /attachments/{id}/download` — upload/download autorizados.
- `GET /process-instances/{id}/timeline` — eventos e estados históricos filtrados.
- `/admin/integrations` — saúde e configuração sem retornar segredo.

Nenhum endpoint permite escolher arbitrariamente `processDefinitionId`, candidate groups, usuário, status Flowable ou variável protegida. Laravel constrói variáveis de identidade/server-only a partir do token e dados autorizados. Conclusão exige ownership/candidate group validado; depois da submissão persistida, o worker Laravel chama Flowable REST e registra confirmação/reconciliação. Não acessar `TaskService` Java diretamente a partir do PHP.

## 10. Segurança, LGPD e auditoria

- HTTPS obrigatório; CSP, CORS restrito, CSRF conforme padrão de autenticação escolhido, rate limit, headers seguros e proteção contra upload malicioso.
- Configurar Form.io Builder sem JavaScript arbitrário do designer nem scripts externos não aprovados; validar e normalizar schema JSON no backend, limitar componentes/propriedades e prevenir HTML perigoso, URLs `javascript:` e XSS armazenado. Testar também chamadas a recursos externos definidas por campos.
- Segredos em Docker secrets/secret manager; arquivo `.env` local fora do Git e sem segredos reais. Rotacionar credenciais externas. Criptografia em trânsito e disco; avaliar criptografia adicional para anexos/campos sensíveis.
- RBAC no backend + autorização contextual por process definition, instância, tarefa, lane/grupo e relação com fornecedor. Isolar tenant se produto evoluir para multiempresa; não presumir multi-tenant no MVP.
- Auditoria append-only de abertura, claim, reatribuição, leitura sensível se política exigir, submissões, decisões, alterações de prazo, uploads/downloads, publicação, administração e integração. Usar timestamp UTC sincronizado; proteger log contra edição indevida e restringir acesso.
- Definir bases legais, finalidade, retenção, descarte, acesso do titular e política de logs com DPO/jurídico. Retenção não pode ser decidida pelo desenvolvedor sozinho. Anexos do fornecedor podem conter dados pessoais/comerciais.
- Sem PII ou tokens em logs, traces, metrics, e-mail ou mensagens de erro. Redact request/response; fornecer acesso mínimo; proteger backups e testar restauração.
- Acesso externo requer MFA/expiração; evitar contas compartilhadas e links públicos. Encerramento/revogação de acesso deve impedir consulta posterior a instâncias.

## 11. Docker e ambientes

### 11.1 Layout de repositório sugerido

```text
/apps/web                 # Vue 3 SPA
/apps/api                 # Laravel API, domínio, policies, jobs, migrations
/apps/flowable            # configuração/imagem fixada Flowable REST (sem lógica TMSA customizada)
/deploy/docker            # compose, proxy, healthchecks, scripts
/deploy/monitoring         # dashboards/config opcional
/docs                     # ADRs, runbooks, modelo PO 159
```

### 11.2 Serviços do Compose de desenvolvimento

- `web`: build Vue/Nginx estático, dependente apenas de API por URL de configuração sem segredo.
- `api` + `api-web`: Laravel/PHP-FPM e Nginx interno para HTTP; endpoint health/readiness; migrations Laravel; somente acessível pelo proxy edge.
- `queue-worker`: mesma imagem Laravel, executando `php artisan queue:work`; consumirá outbox/comandos Flowable e integrações. Usar fila `database` no primeiro compose; Redis é opcional se volume/latência justificar.
- `scheduler`: container Laravel para `schedule:work`/reconciliação periódica; singleton/lock quando houver múltiplas réplicas.
- `flowable-rest`: REST application do Flowable em versão fixada, configurada com PostgreSQL e sem usuário/senha padrão; apenas na rede interna, nunca publicado no host/proxy público. Se não existir imagem alinhada e suportada pela versão aprovada, construir container Java/Tomcat com o WAR/distribuição oficial.
- `postgres`: PostgreSQL fixado, volume nomeado, healthcheck, sem porta pública em configuração padrão.
- Form.io Builder/Renderer será empacotado como dependência JavaScript da aplicação Vue; não requer Form.io API Server/container separado no desenho inicial. O backend TMSA é dono de identidade, versionamento de schemas, submissões e anexos. Se avaliado servidor Form.io no futuro, revisar licença OSL 3.0 do core server, threat model e possível duplicidade de persistência. [12]
- `minio`: apenas dev/POC, console/portas privadas, volumes persistentes; produção usa storage corporativo S3/Blob permitido.
- `reverse-proxy`: único serviço exposto para HTTP local; HTTPS real termina no ingress corporativo em ambiente remoto.
- Opcional: `mailpit` somente dev para capturar e-mails; não enviar para destinos reais.

Flowable é um serviço separado pela decisão Laravel; configurar a application REST para PostgreSQL (não aceitar H2 em memória como banco de produção), autenticação server-to-server, usuário/roles mínimos, senha rotacionável, limites e logs sem PII. A REST API tem poder elevado sobre processos/tarefas; somente Laravel/worker acessam, com allowlist de rede e credenciais em secrets. Não incluir Modeler/Admin UI na superfície pública. Não adicionar Redis, Kafka ou Elasticsearch no MVP sem necessidade medida; fila database + outbox atende ao piloto, Redis pode ser avaliado para throughput/locks.

### 11.3 Procedimento passo a passo

1. Instalar Docker Engine + Docker Compose plugin em máquina dev Linux; usar Docker Desktop no Windows/Mac. Fixar imagem de base e versões.
2. Criar `.env.example` só com nomes de variáveis não secretas e valores dummy; ignorar `.env`. Criar volumes persistentes e redes Docker separadas (`edge` e `internal`).
3. Configurar PostgreSQL com banco/roles mínimos, volume, healthcheck, timezone UTC, backup local em dev e sem porta publicada em rede externa.
4. Configurar Flowable REST e Laravel com PostgreSQL e credenciais distintas; Laravel migrations só alteram tabelas TMSA; engine administra suas tabelas. Configurar validação Entra issuer/audience/JWKS no Laravel, credencial REST privada Laravel→Flowable, fila database, storage de anexos e auth stub somente em dev/test.
5. Configurar Vue com `VITE_API_BASE_URL` não secreto; MSAL local só após app registration/redirect configurados. Proibir auth stub em build de produção.
6. Integrar Form.io Builder/Renderer no Vue; validar componentes permitidos, traduções/tema TMSA, compatibilidade de schemas históricos e mapping para `form_submission`/variáveis Flowable. Salvar schemas publicados pela API TMSA e confirmar que a SPA não envia submissões a endpoint externo Form.io. Configurar MinIO local e bucket privado; criar policy mínima; testar upload/scan mock, URL assinada e download autenticado.
7. Iniciar com `docker compose up --build`; esperar healthchecks PostgreSQL, Flowable REST, Laravel e worker; executar migrations Laravel, testar conexão privada com engine, criar usuário/grupos mock dev e carregar definição seed em rascunho com etapa/form Form.io associados.
8. Rodar testes unitários/integração/E2E dentro do pipeline; testar restore de DB + storage. Verificar logs sem dados sensíveis.
9. Para homologação/prod, publicar imagens com digest/version tag pelo CI; configurar HTTPS, DNS, Entra app registrations, egress para APIs AX, storage/SMTP e observabilidade. Guardar secrets fora do compose repository.
10. Fazer backup PostgreSQL e storage com política definida e cópia imutável/offsite; testar restauração periódica, não apenas criação do backup.
11. Implantar com janela de rollback: snapshot/backup, executar migrations backward-compatible, health checks, smoke tests de Entra/abertura/tarefa/anexo, liberar tráfego e monitorar erros.

### 11.4 Limitações do Docker Compose

Compose num servidor único não fornece alta disponibilidade, failover automático, escalabilidade multi-host, gestão robusta de secrets nem recuperação de desastre por si só. Serve para desenvolvimento e piloto restrito; produção crítica deve avaliar Kubernetes/OpenShift ou plataforma de containers corporativa, banco gerenciado/HA, storage redundante, gestão de segredos, réplicas, monitoramento e estratégia de DR. Não publicar serviço de engine, banco, storage ou admin sem controle de rede.

## 12. Plano de execução para Claude Code

Instruir Claude Code a trabalhar incrementalmente, abrir PRs pequenos e não inferir contratos de API ausentes. Na primeira sessão, gerar/atualizar `README.md`, `CLAUDE.md`, ADRs e scaffold; antes de integrar AX, pedir exemplos OpenAPI/cURL sanitizados e lista de campos. Nunca usar produção ou dados reais sem autorização.

### Fase 0 — Descoberta e decisões (1–2 semanas, estimativa de planejamento)

1. Entrevistas com CQ, PPCPM, Comitê, GEF, Fornecedor/Suprimentos, Fiscal, Qualidade, Expedição e Faturamento.
2. Revisar imagem BPMN legível/export original; confirmar os gateways, subprocessos, lanes e todos retornos.
3. Aprovar autenticação de fornecedores, grupos Entra, atributos de área, SLAs, notificações, anexo/retention e condições de encerramento.
4. Receber contratos da API mestre e AX: build/CU instalado, integration ports AIF/WCF e custom/document services publicados, autenticação/bindings/certificados, REST façade existente, SQL reporting views/read replica que podem ser consultadas read-only, ambientes de teste, limites e idempotency/reconciliation. Não presumir OData/REST padrão no AX 2012 R3, nem acesso direto de escrita às tabelas.
5. Rodar POC de Laravel→Flowable REST em Docker: publicar BPMN, iniciar instância, localizar/reivindicar/completar user task, timer/gateway, histórico, PostgreSQL e auth service-to-service; testar identity mapping Entra, REST container privado, upgrade/versão, BPMN XML round-trip e falhas de rede. Validar explicitamente fluxo de trabalho distribuído/outbox/idempotência/reconciliação.
6. Fazer POC de Form.io Builder/Renderer dentro de Vue: campos de arquivo, repetidor de ações, conditional logic, componente de lookup AX, tema/acessibilidade, schema versioning e submissions via TMSA API. Desabilitar código/script livre, rever licença e dependências; decidir explicitamente não usar Form.io API Server no MVP.
7. ADRs: engine, banco, storage, identity, deploy target, estratégia de formulários, dados/auditoria, licenças e threat model.

### Fase 1 — Fundação técnica

1. Monorepo/scaffold de frontend e backend, pipeline CI, formatting, lint, tests, image scan/SBOM.
2. Compose local com Laravel API/web, queue-worker, scheduler, Flowable REST separado, PostgreSQL, Vue (Form.io Builder/Renderer empacotados), proxy e MinIO; redes, healthchecks, volumes e seed dev. Sem container Form.io Server por padrão.
3. Schemas/migrations, tratamento de erros, correlation ID, OpenAPI, logging estruturado sem PII.
4. Entra ID login, API token validation, role mapping e testes de autorização.
5. Auditoria, `engine_command_outbox`, queue worker/reconciliador e integração de notificações mock.

### Fase 2 — Produto BPM core

1. Catálogo, grid, detalhe/linha do tempo, inbox e tarefa simples.
2. CRUD de drafts e versionamento; `POST /flows` cria fluxo + versão draft + user task inicial + schema Form.io ligado em uma operação TMSA; incluir integração Builder/Renderer, catálogo de componentes, schemas/submissions pela TMSA API e atribuição por usuário/grupo.
3. Modelador `bpmn-js` com subset suportado, extensões TMSA, import/export, lint/validation e preview.
4. Publicação por deploy Flowable REST controlado; iniciar instância, consultar tarefas, claim/complete/reassign autorizado via Laravel; processar outbox e anexos.
5. Testes Pest/PHPUnit, contract tests Flowable REST e E2E para segurança, paginação, concorrência, idempotência/reconciliação, auto-criação vinculada de etapa+form e permissões.

### Fase 3 — Configurar PO 159

1. Criar BPMN XML e formulários Form.io pela interface ou seed versionado; IDs BPMN e keys Form.io estáveis, schemas referenciados pela etapa BPMN e dados mapeados para submissions/variáveis Flowable.
2. Implementar gateways para urgência, tipo, procedência, comitê, eficácia, garantia/devolução, tipo de envio e frete.
3. Implementar RACF e sequência anual segura, histórico de revisões, subprocesso de causa/plano com lista de ações e evidências.
4. Implementar paralelismo e sincronização conforme oficina, timers e escalonamentos aprovados.
5. Testar todas as rotas com variáveis equivalentes; matriz de cenários cobre Sim/Não e retornos.

### Fase 4 — Integrações

1. Implementar consultas de TPR, item, OC e fornecedor pelo transporte selecionado e aprovado (REST façade/AIF-WCF/SQL read-only para lookup), começando por mocks de contratos aprovados.
2. Implementar operações de escrita AX (diário, OCDev, bloqueio/marcação) somente via serviço de negócio AIF/WCF ou API REST corporativa com contrato, idempotência e reconciliação confirmados; nunca fazer DML nas tabelas transacionais do AX. Manter feature toggles por ambiente.
3. Processar eventos/outbox, retentativas, circuit breaker, dead-letter/erro visível e tela de reconciliação.
4. Testes de timeout, indisponibilidade, dados inexistentes, resposta lenta, duplicidade e atualização parcial.

### Fase 5 — Piloto e saída controlada

1. Treinar usuários piloto, testar dados sintéticos e homologar cada papel/raia.
2. Migrar dados somente mediante escopo e plano de qualidade aprovado; evitar coexistência ambígua com SoftExpert.
3. Definir suporte/owners, rollback, backups/restore, monitoramento de SLA, change management e comunicação.
4. Executar piloto limitado; reconciliar indicadores; obter aceite formal antes de expandir.
5. Desativar módulo antigo apenas após exportação, retenção, consulta histórica e aprovação de negócio/TI.

Estimativas são hipóteses de planejamento e dependem de equipe, maturidade de APIs, hosting e validação; não representam compromisso de prazo. Para arquitetura robusta, equipes típicas incluem Product Owner/process owner, analista BPM, frontend, backend/BPM, integração ERP, QA, UX, DevOps/SRE e segurança/Entra.

## 13. Testes e critérios de aceite

### Testes funcionais mínimos

- Criar novo fluxo pela UI/API e confirmar que cadastro, versão draft, evento Start, primeira tarefa humana, schema Form.io vazio e vínculo etapa↔form são gravados juntos; falha em qualquer parte deve reverter tudo. Adicionar etapa humana cria form associado; gateway/timer/service task não cria form órfão.
- Simular timeout/retry do Laravel worker depois de comando enviado ao Flowable REST; reconciliação confirma estado sem duplicar instância nem submissão.
- Iniciar uma instância e exibir IRR sequencial/ano, solicitante, área, data, status e etapa no grid.
- Abrir 01, preencher obrigatórios, resolver lookups, tratar item/fornecedor inexistente sem perder dados.
- Validar 06: “Não” vai para 11; “Sim” torna 07/08 obrigatórios e envia 09; concluir 09 direciona para 11.
- Validar 24/26/27/28 e todos retornos; registros de decisão antiga são imutáveis no histórico.
- Validar 29, análise 30, múltiplas ações, anexo por ação, ação preventiva, execução individual, validação negativa e reenvio.
- Validar eficácia 30.2 e loop 30.3 com limite/escalonamento/cancelamento definidos.
- Cobrir todos os ramos de 33–47, incluindo tipo 01 vs 03/09, CIF vs FOB, NF espelho sim vs não, garantias enviadas pela TMSA vs fornecedor e retentativas de AX.
- Usuário sem role/grupo não lista, abre, altera, anexa, baixa nem completa uma instância alheia por troca de ID no request.
- Concorrência: dois usuários tentam completar/reclamar a mesma tarefa; apenas transição válida vence e outra recebe conflito controlado.
- Virada de ano, numeração paralela, duplicidade, back-up/restore, anexo infectado e logs sem segredo/PII.

### Critérios de aceite

- Usuário autorizado cria rascunho, configura etapas/campos/regras simples, visualiza diagrama e recebe erros acionáveis antes de publicar.
- Designer cria e renderiza formulários com Form.io na aplicação Vue; schemas são versionados e persistidos pela API TMSA, tarefas antigas continuam renderizáveis com sua versão e scripts livres foram desativados/testados.
- Criar um novo fluxo apresenta ao usuário uma etapa e formulário imediatamente; cada user task humana permanece associada a um schema de formulário da mesma versão, sem órfãos.
- Processo publicado é versionado e sua instância conserva versão original.
- API aplica validação e autorização; frontend não é fonte de verdade.
- Todo evento de decisão possui ator, timestamp, valor e task/instance correlation, respeitando política de retenção.
- O PO 159 possui testes cobrindo todas as rotas aprovadas pelo dono de processo, anexos e falhas de integração.
- O conjunto Compose sobe do zero com instruções reproduzíveis em dev; produção possui runbook, backup/restauração testados, TLS, secrets e observabilidade.
- Aceite formal por donos funcionais de cada raia e equipe de segurança/infra.

## 14. Premissas, limitações e riscos

**Premissas:** PostgreSQL e Docker são aprovados pela TMSA; Entra ID é IdP oficial para workforce; a equipe tem capacidade operacional PHP/Laravel e Java/Flowable; haverá ambiente de teste para AX/ERP; áreas proverão grupos/owners e regras; o fornecedor não acessa o sistema no MVP até decisão explícita; Flowable REST satisfaz os constructs BPMN/identity necessários após POC.

**Limitações:** o MVP não é um BPMN general-purpose equivalente a plataformas comerciais. Designer suporta somente subset certificado. Laravel + Flowable REST requer operar dois runtimes, autenticação de serviço e reconciliação; em contrapartida permite que a API use a linguagem conhecida pelo time. Alteração/migração de instância em voo, multi-tenancy, portal externo completo, analytics avançado, marketplace de conectores, app móvel offline e HA automática ficam fora do MVP, salvo priorização/estimativa.

**Riscos principais:** diagramas/termos ambíguos; integração Laravel–Flowable inconsistente em timeout/retry; autenticação service-to-service e identidade/candidate groups mal mapeadas; indisponibilidade REST/engine; ausência de pessoas aptas a operar duas stacks; APIs AX incompletas; autenticação externa; licenças; anexo/retention; Compose single-host; alteração de processo com instâncias abertas; duplicidade de operação AX; bypass de permissões; auditoria insuficiente.

Mitigar com workshop e BPMN aprovado; contrato de APIs e sandbox; review jurídica de dependências; idempotência/outbox e reconciliação; RBAC testado; POC de engine; estratégia de backup/restore; métricas de lead time e aging por etapa; revisão periódica das regras.

## 15. Indicadores a instrumentar desde o piloto

- Lead time total de abertura até encerramento (média, mediana, percentis), com definição de pausas aprovada.
- Tempo de espera e tempo de trabalho por etapa/raia, idade das tarefas abertas e porcentagem vencida.
- Volume, taxa de conclusão, cancelamentos e reaberturas/retornos por motivo.
- Tempo e resultado de chamadas AX/ERP, falhas e reprocessamento.
- Resultado de eficácia do plano e recorrência por fornecedor/item/categoria, conforme direitos de acesso.

Não publicar ranking de pessoas como indicador sem análise de contexto, governança e aprovação de RH/privacidade.

## 16. Instrução inicial sugerida para Claude Code

```text
Leia BPMN_TMSA_Arquitetura_e_Plano_Claude_Code.md integralmente. Antes de codificar, confirme o repositório, leia o CLAUDE.md existente e apresente plano por PRs. Use Laravel como API/dono do domínio e Flowable REST como engine BPMN separado, privado; não crie uma API customizada Spring Boot. Não invente contratos de AX nem regras ambíguas: AX 2012 R3 usa AIF/WCF ou REST façade aprovada para escrita e SQL somente read-only autorizado para consultas; use mocks até receber contratos. Crie `POST /flows` de modo que cada fluxo novo inclua em transação TMSA uma versão draft, uma etapa humana inicial e schema Form.io vazio associado, além de permitir adicionar user task + form já vinculado. Form.io Builder/Renderer é client-side Vue; schemas/submissions persistem pela Laravel API, sem Form.io API Server no MVP e sem scripts livres. Construa Vue 3 + TypeScript + PrimeVue + Form.io, Laravel/PHP + queue worker + outbox/reconciliação, Flowable REST e PostgreSQL em Docker Compose, Entra ID via validação JWT, migrations, OpenAPI e testes. Laravel é única API pública; proteja Flowable REST em rede interna. Modele a comunicação engine com comando idempotente/reconciliação, sem alegar transação distribuída. Não coloque segredos no frontend/Git. Implemente apenas subset BPMN validado. Ao final de cada PR, rode testes/PHPStan/build/E2E, reporte alterações, comandos, riscos e pendências. Nenhuma chamada a AX real/produção sem autorização explícita.
```

## Referências técnicas

[1]: https://www.flowable.com/open-source/docs/bpmn/ch02-GettingStarted "Flowable Open Source Documentation — Getting Started"
[2]: https://www.flowable.com/open-source/docs/bpmn/ch04-API/ "Flowable Open Source Documentation — The Flowable API"
[3]: https://www.flowable.com/open-source "Flowable — Open Source"
[4]: https://docs.n8n.io/ "n8n Documentation"
[5]: https://docs.n8n.io/n8n-community-license/ "n8n Documentation — Community License"
[6]: https://docs.processmaker.com/docs/overview "ProcessMaker Documentation — Overview"
[7]: https://docs.processmaker.com/docs/form-task "ProcessMaker Documentation — Form Task"
[8]: https://bpmn.io/toolkit/bpmn-js/ "bpmn.io — bpmn-js"
[9]: https://primevue.dev/datatable/ "PrimeVue — DataTable"
[10]: https://www.shadcn-vue.com/docs/introduction "shadcn-vue — Introduction"
[11]: https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow "Microsoft Learn — OAuth 2.0 authorization code flow"
[12]: https://form.io/open-source/ "Form.io — Open Source Form Builder, Renderer, and Supporting Licenses"
[13]: https://form.io/vue-forms/ "Form.io — Vue Forms"
[14]: https://learn.microsoft.com/en-us/dynamics/s-e/ax/msdyax2012r3release_133 "Microsoft Learn — Microsoft Dynamics AX 2012 R3 Release"
[15]: https://learn.microsoft.com/fr-fr/previous-versions/dynamicsax-2012/appuser-itpro/what-s-new-services-and-application-integration-framework "Microsoft Learn — Services and Application Integration Framework in AX 2012, applicable à AX 2012 R3"
[16]: https://www.flowable.com/open-source/docs/bpmn/ch14-REST "Flowable Open Source Documentation — REST API"
[17]: https://laravel.com/framework/docs/authorization "Laravel Documentation — Authorization (Policies and Gates)"
[18]: https://laravel.com/framework/docs/queues "Laravel Documentation — Queues"
