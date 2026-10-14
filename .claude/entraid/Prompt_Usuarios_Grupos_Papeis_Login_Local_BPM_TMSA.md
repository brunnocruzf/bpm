# Prompt para Claude Code — usuários, grupos, papéis e login local no BPM TMSA

> Prompt pronto para uso no Claude Code junto ao repositório do BPM TMSA. Este escopo complementa a arquitetura existente; não substitui o Microsoft Entra ID nem autoriza remover funcionalidades atuais.

## Prompt de implementação

Você é responsável por implementar no produto BPM TMSA as melhorias descritas abaixo. Antes de alterar código, inspecione a aplicação existente, identifique as telas, entidades, permissões, autenticação e rotas reais e leia `CLAUDE.md`, migrations, testes e documentação do repositório. Se estiver disponível, consulte também `BPMN_TMSA_Arquitetura_e_Plano_Claude_Code.md` e o prompt anterior de customizações.

**Não comece um sistema novo, não crie uma implementação paralela e não presuma que os nomes de tabelas, guards ou componentes abaixo já existem.** Adapte as propostas ao código encontrado, mantendo compatibilidade e documentando as decisões.

### 1. Contexto e decisões de arquitetura

- Produto BPM TMSA, frontend Vue 3 + TypeScript + PrimeVue, backend/API Laravel, banco SQL/PostgreSQL, engine BPMN Flowable REST interna e usuários/grupos importados do Microsoft Entra ID via backend/Microsoft Graph.
- O login corporativo Entra ID existente deve continuar funcionando. O login local será **uma segunda opção de autenticação**, destinada apenas a contas locais criadas por administradores.
- Não misture configuração de login OIDC/Entra com a sincronização Graph de diretório. O login local não deve depender da sincronização Graph.
- A tela a atualizar está acessível no ambiente local em `http://localhost:8080/admin/groups`. Preservar essa URL. Como a aplicação é Laravel monolítica, registrar/servir a rota de interface `/admin/groups` por `routes/web.php` ou pelo fallback da SPA definido em `routes/web.php`, conforme o padrão já existente. Não colocar a rota de página apenas em `routes/api.php`; manter os endpoints de dados no local/API convention apropriado.
- Flowable deve continuar privado, sem chamadas do browser. Laravel permanece responsável por autorização, vínculos de usuários, regras de atribuição e auditoria.
- Manter os registros históricos do processo e preservar identificadores externos Entra (`tenant_id`, `oid/object id`) ao adicionar contas locais.

### 2. Entrega esperada

Implementar e testar:

1. Uma interface **Usuários e grupos** em `/admin/groups`, separada em abas **Usuários**, **Grupos** e **Papéis**.
2. Um perfil detalhado para cada usuário selecionado, com todas as informações que o sistema realmente possui e que o administrador tem autorização para consultar.
3. Inclusão e remoção manual de usuários em grupos e nas associações de raias/atribuições pertinentes ao TMSA, sem gravar alterações no Entra ID.
4. Uma tela de papéis que mostre permissões efetivas e usuários relacionados, distinguindo atribuição direta de atribuição por grupo.
5. Criação administrativa de contas locais.
6. Uma segunda opção na tela de login: **Entrar com usuário local**, mantendo **Entrar com Microsoft 365/Entra ID**.
7. Migrations, Policies, validação server-side, interface, logs de auditoria, documentação e testes automatizados.

Comece com um plano curto e uma análise dos componentes atuais. Em seguida implemente o fluxo completo, não apenas protótipos visuais. Ao concluir, descreva arquivos alterados, migrations/rotas, decisões de autenticação, testes executados e pendências de ambiente.

### 3. Página `/admin/groups`: abas e experiência

Transformar a página atual em uma seção de administração com tabs navegáveis:

- **Usuários** — diretório de usuários, criação de conta local, busca e perfil detalhado.
- **Grupos** — grupos internos/importados e administração de membros; incluir as associações de usuários a grupos e, onde houver um conceito válido no modelo, a raias/assignment scopes.
- **Papéis** — catálogo dos papéis e das permissões; usuários/grupos relacionados a cada papel.

Requisitos da interface:

- Indicar a aba selecionada visualmente e por acessibilidade (`tablist`, `tab`, `tabpanel`, `aria-selected`, foco e teclado).
- Manter filtros, busca, paginação, ordenação e estado de carregamento/erro/vazio sem descarregar uma lista inteira para o browser.
- Funcionar em desktop e mobile, com linguagem em português e componentes coerentes com PrimeVue/design system atual.
- Manter filtros ao abrir/fechar perfil quando possível e usar drawer/modal ou rota de detalhe que funcione com teclado e leitores de tela.
- Atualizar os dados após adicionar/remover membro; mostrar confirmação antes de operações destrutivas ou que alterem autorização e registrar sucesso/falha.
- Aplicar autorização no backend para cada leitura e mutação. Ocultar botões no frontend é complementar, nunca substitui Policy/Gate.

### 4. Aba Usuários: listagem e perfil completo

#### 4.1 Listagem

Criar ou adaptar uma tabela com os dados disponíveis no domínio, preferencialmente:

- Nome exibido e avatar/iniciais.
- E-mail e/ou UPN/login.
- Origem da identidade: Entra ID ou conta local.
- Estado: ativo, desativado, convite pendente, bloqueado ou outro estado real do sistema.
- Departamento/cargo/identificador funcional quando existentes.
- Quantidade de grupos, papéis e associações de raia.
- Última atividade/login, se já é coletado de forma confiável e autorizado.
- Ações disponíveis de acordo com o papel do operador: abrir perfil, editar campos locais, ativar/desativar, incluir em grupo e criar usuário.

Busca por nome, e-mail/UPN e identificador; filtros por origem, status, departamento, grupo e papel; paginação server-side. Não inventar valores padrão ou estados que não existam no modelo. Diferenciar na UI “dado indisponível” de “dado vazio”.

#### 4.2 Perfil do usuário

Ao selecionar uma pessoa, abrir uma página/drawer de perfil com informações agrupadas para consulta rápida, não um dump de JSON. Mostrar todos os atributos realmente disponíveis e autorizados, podendo incluir:

**Identificação**
- Nome, nome de exibição, e-mail/UPN, username, identificador interno TMSA.
- Provedor de identidade, tenant/Entra OID quando aplicável, indicação de usuário local ou importado.
- Foto/avatar somente se existente e autorizado.

**Dados organizacionais**
- Departamento, unidade/localidade, cargo, employee ID ou outros atributos presentes no diretório/modelo.
- Origem e data da última sincronização de cada atributo relevante quando o sistema mantém essa proveniência.

**Conta e segurança**
- Estado da conta, método/provedor de autenticação, data de criação/atualização e último login confirmado.
- Estado de convite/ativação e informação de bloqueio somente quando disponível e autorizada.
- Nunca exibir senha, password hash, tokens, segredo, chave privada, código de recuperação ou resposta de segurança.

**Acesso**
- Papéis efetivos e sua origem (atribuído diretamente, por grupo ou regra de mapeamento Entra).
- Grupos e associações de raia/assignment scope, identificando a fonte `Entra`, `manual` ou outra origem existente.
- Tarefas atuais atribuídas ao usuário, se o modelo e o nível de permissão permitirem, com link autorizado para os detalhes; não expor dados de processos fora do escopo de acesso.

**Atividade/auditoria**
- Eventos relevantes: criação/ativação/desativação, alterações de perfil, mudanças de papel/grupo/raia, sync Entra e eventos de autenticação disponíveis. Paginar e aplicar autorização.

Regras de edição:
- Atributos geridos pelo Entra devem aparecer como **somente leitura**, com a origem indicada. Não permitir que edição local seja sobrescrita silenciosamente na sincronização.
- Atributos de administração local podem ser editados se o perfil do operador permitir. Cada mudança deve gerar diff de auditoria.
- Se “todas as informações disponíveis” incluir campos sensíveis, apresentar somente aos perfis com autorização específica e redigir campos que a política não permita mostrar. Não exibir dados desnecessários apenas porque estão no banco.

### 5. Aba Grupos: membros manuais e associações de raia

Permitir que um administrador autorizado inclua e remova manualmente usuários em grupos internos do BPM TMSA. A interface deve apresentar:

- Lista/pesquisa de grupos, tipo/origem, descrição, quantidade de membros e, se aplicável, referência ao grupo Entra.
- Detalhe do grupo com membros atuais, origem do vínculo, data e ator que criou o vínculo manual.
- Ação **Adicionar usuários**, com busca/picker server-side, seleção múltipla se o sistema suportar e validação de contas ativas.
- Ação de remover vínculo manual, com confirmação; nunca apagar o usuário ou o histórico.
- Feedback de duplicidade, conflito ou falha, e proteção contra adicionar usuário desativado a novas atribuições sem justificativa/permissão especial.

#### 5.1 Origem e sincronização

A aplicação não deve escrever no Microsoft Entra por meio desta tela. Todas as alterações manuais são **somente do BPM TMSA**.

- Diferenciar vínculos originados por sincronização Entra (`source=entra`) de vínculos manuais internos (`source=manual`) e outros tipos já existentes.
- Uma edição manual não deve falsificar, apagar ou sobrescrever o estado de membership que o Graph sincroniza. Se o produto permitir override interno de grupo Entra, representá-lo como associação local separada e explicitar na interface que isso não altera o Entra.
- Não alterar credenciais, consentimento ou membership no diretório externo.
- Se a sincronização Entra reimportar um grupo ou membership, preservar vínculos locais autorizados e não duplicar o mesmo membership efetivo.

#### 5.2 Grupos versus raias BPMN

Não confundir um grupo corporativo/global com uma **raia BPMN**. Raias são estruturas do modelo de processo e podem variar por processo/versão.

- Se o TMSA já tiver cadastro de raias globais ou `assignment roles`, permitir o vínculo manual conforme a modelagem existente.
- Se as raias forem definidas dentro de cada BPMN, representar o vínculo como uma regra de atribuição/candidate user/candidate group **escopada ao processo e à versão publicada**, não como uma associação global sem contexto.
- Mostrar processo, versão, raia/tarefa e regra associada no detalhe do usuário/grupo.
- Alterar membership/regra só afeta novas atribuições, salvo ação separada e autorizada de reatribuição das tarefas existentes. Não reatribuir silenciosamente tarefas em andamento.
- Antes de implementar, mapear como Flowable representa candidate users/groups e como Laravel mantém a regra. Comandos ao Flowable devem seguir o padrão assíncrono/outbox já utilizado e reconciliar falhas.

### 6. Aba Papéis: permissões e usuários relacionados

Criar uma tela legível para compreender o RBAC existente, sem reduzir permissões a nomes vagos.

Para cada papel mostrar:

- Nome técnico e nome amigável.
- Descrição funcional e estado (ativo/desativado), se aplicável.
- Permissões efetivamente concedidas, agrupadas por módulo/recurso (ex.: processos, formulários, configurações, usuários/grupos, auditoria, relatórios).
- Ação autorizada por permissão (`read`, `create`, `update`, `delete`, `assign`, `approve`, `admin` ou nomenclatura já adotada), escopo e restrições aplicáveis.
- Fonte das permissões: definição nativa do papel, policy/permission table, herança ou regra do produto. Não apresentar uma permissão como existente se o backend não a aplicar.
- Relação de usuários com o papel, distinguindo vínculo direto, grupo que concede o papel e mapeamento de grupo Entra; mostrar contagem e lista paginada.
- Acesso efetivo do usuário, contemplando combinações permitidas e regras deny/override existentes.

**Não implementar edição/criação de papéis ou permissões como requisito implícito** se isso não existir hoje. Se já houver editor de RBAC, preservá-lo e garantir proteção contra autoelevação; qualquer mudança deve exigir permissão de administração apropriada, confirmação, auditoria e testes. Por padrão, esta aba pode ser somente leitura para tornar transparência de acesso segura.

### 7. Criar usuários manualmente

Adicionar botão **Novo usuário** na aba Usuários. O formulário deve criar uma **conta local** quando o administrador selecionar o provedor local, com campos mínimos úteis conforme o domínio existente:

- Nome completo/nome de exibição.
- E-mail e/ou username, normalizados e únicos segundo as regras de conta.
- Departamento, cargo, employee ID ou demais campos locais quando relevantes e presentes no modelo.
- Estado inicial e grupos/papéis iniciais opcionais, sujeitos à permissão do operador e ao princípio do menor privilégio.
- Provedor de autenticação claramente identificado como **Conta local**.

Validações e regras:

- Não criar usuário local ativo sem meio seguro de ativação. Preferir convite por link de uso único e validade limitada enviado ao e-mail cadastrado; o usuário define a senha no primeiro acesso.
- Não enviar senha em texto puro por e-mail e não exibir senha temporária persistente a administradores. Se o e-mail corporativo não estiver configurado, permitir salvar a conta como `invitation_pending` sem acesso e exibir instrução operacional segura, ou usar fluxo de ativação administrado já existente.
- Validar duplicidade por username/e-mail e conflitos com usuário Entra sem vincular contas automaticamente apenas porque e-mails coincidem. Se houver necessidade de vinculação, exigir ação administrativa explícita com identificação OID e trilha de auditoria.
- Criar conta local não deve criar/alterar um objeto no Entra nem aparentar que houve importação Graph.
- Não oferecer cadastro público/self-registration: contas locais são provisionadas por administradores, salvo requisito futuro explícito.
- Aplicar estado ativo/inativo, suspensão e bloqueio de acordo com o modelo real. Desativar uma conta impede novas sessões/login e futuras atribuições, mas preserva autoria e histórico BPM.
- Fazer validação server-side, normalização e transação para criar conta + papéis/grupos iniciais + eventos de auditoria. Se algum passo falhar, não deixar conta parcialmente privilegiada.

### 8. Login local como segunda opção

Na tela de login atual, conservar o botão **Entrar com Microsoft 365/Entra ID** e acrescentar a opção **Entrar com usuário local**. Não substituir nem despriorizar autenticação corporativa; apresentar duas opções claramente distintas.

O fluxo local deve incluir:

1. Botão/link “Entrar com usuário local” que apresenta formulário de e-mail/username e senha, com botão para exibir/ocultar senha acessível.
2. Botão “Entrar” e link “Esqueci minha senha”, além de ação para voltar ao login corporativo.
3. Estado de carregamento, erro de credenciais, conta desativada/bloqueada, convite pendente e recuperação de senha, com mensagens seguras e consistentes.
4. Texto breve indicando que conta local é fornecida pelo administrador. Não criar link “Criar conta”.
5. Em sucesso, redirecionar conforme fluxo de retorno autorizado, mantendo autorização e escopo do usuário.

#### 8.1 Segurança de autenticação

- Armazenar somente hash de senha com o mecanismo moderno suportado pela versão Laravel (preferir Argon2id se disponível/configurado; caso contrário, hasher recomendado pelo framework). Nunca persistir/registrar senha em log.
- Preferir sessão server-side ou o mecanismo de sessão seguro já usado pela SPA. Para SPA same-origin, avaliar Laravel Sanctum/stateful session com cookie `HttpOnly`, `Secure` em produção, `SameSite` apropriado e proteção CSRF. Não guardar access token/senha em `localStorage` ou `sessionStorage`.
- Se a aplicação atual exige bearer tokens, desenhar um fluxo compatível e documentar ADR; não inventar JWT artesanal com chave hard-coded. O middleware de autenticação deve identificar a origem (Entra ou local) e aplicar as mesmas Policies/autorização de domínio.
- Aplicar rate limiting por IP e conta, proteção contra brute force, backoff/lock temporário, CSRF, sessão renovada após login e invalidação de sessão/token em logout, reset de senha, desativação e mudança de credencial.
- Mensagens de falha não devem revelar se um e-mail existe. Respostas de “esqueci minha senha” devem ser neutras e não enumeráveis.
- Recuperação: token aleatório, armazenado em hash, expiração curta configurável e uso único; revogar sessões após reset. Usar o provider de e-mail já configurado pelo produto e testes Mailpit em desenvolvimento.
- Não habilitar senha local para usuário que só tem identidade Entra. O fluxo de erro/indisponibilidade Entra não deve fazer fallback automático para autenticação local.
- Permitir MFA local se já existir; caso contrário, documentar como controle recomendado/fase futura para perfis privilegiados, sem apresentar MFA como habilitada quando não está.
- Um login local válido não deve ganhar permissões extras; autorização deriva dos mesmos papéis/grupos/policies do sistema.

### 9. Dados, APIs, auditoria e migração

Inspecione o schema atual e reutilize entidades equivalentes. Proponha migrations incrementais e reversíveis. O domínio precisa distinguir, sem redundância:

- Identidade de aplicação e provedor (`entra`/`local`) e identificador externo imutável Entra quando aplicável.
- Credenciais locais com password hash/estado/tempo de alteração, separados dos campos importados do diretório.
- Origem de cada membership de grupo e sua identidade; vínculos manuais e sincronizados sem sobrescrita.
- Atribuições de papel e grupos que concedem papéis; relações efetivas com usuários.
- Associação de usuários/grupos a assignment scopes/raias BPMN com processo/versão quando necessário.
- Estado de convite/ativação/reset sem guardar tokens em texto puro.

Criar ou reutilizar endpoints obedecendo a convenção existente. Exemplos de intenção (adapte as URLs/nomes, não crie duplicatas):

- `GET /api/admin/users`, `POST /api/admin/users`, `GET /api/admin/users/{id}`, `PATCH /api/admin/users/{id}`.
- `GET /api/admin/groups`, `GET /api/admin/groups/{id}/members`, `POST /api/admin/groups/{id}/members`, `DELETE /api/admin/groups/{id}/members/{userId}`.
- Endpoints administrativos equivalentes para listagem de papéis, permissões e usuários por papel.
- Endpoints de assignment/lane sempre com escopo de processo/versão e policy específica.
- Rotas locais de login, logout, convite e reset segundo arquitetura de auth existente.

Proteger leitura de detalhes/listas com paginação, filtros allowlisted e Policies. Proteger mutações contra IDOR, Mass Assignment, role escalation e alteração de memberships sem escopo. `routes/web.php` deve servir a tela `/admin/groups`; controllers de página não devem expor dados sensíveis na view antes da autorização.

Registrar em audit log pelo menos: usuário criado/convidado/ativado/desativado; tentativa e resultado de convite/reset; alteração em perfil; entrada/saída de grupo/raia; alteração de papel/permissão; login local sucesso/falha/bloqueio/logout quando a política permitir; mudança de provedor/vínculo de identidade. Redigir senhas, tokens, cookies, tokens de reset e dados pessoais desnecessários. Identificar ator humano ou serviço e correlation ID.

### 10. Testes e critérios de aceite

Implementar testes de backend, frontend e integração compatíveis com as ferramentas atuais (PHPUnit/Pest, Vitest, Playwright ou equivalentes). No mínimo:

**Usuários e perfil**
- Aba/listagem, busca, filtros e paginação; permissões impedem usuário sem acesso de consultar dados administrativos.
- Perfil apresenta atributos existentes agrupados e suas fontes; atributos Entra não editáveis por formulário local; segredos/password hash não aparecem em endpoint ou UI.
- Ações administrativas são autorizadas, auditadas e atualizam UI; registros históricos continuam ligados após desativação.

**Grupos e raias**
- Adicionar/remover membros cria/remove somente vínculo manual e não chama Microsoft Graph para escrita.
- Membership Entra e membership manual não se sobrescrevem nem duplicam. Membership manual não promove usuário a papel privilegiado sem mapeamento permitido.
- Atribuição de raia é escopada ao processo/versão; nova tarefa respeita a regra; tarefas já abertas não são reassinaladas sem ação explícita.

**Papéis**
- Lista de permissões reflete policies efetivas; visualização de usuários distingue fonte direta/grupo/Entra; nenhuma permissão visual exibida é fictícia.
- Tentativa de autoelevação/alteração sem permissão falha e gera auditoria.

**Provisionamento local**
- Criar conta gera convite pendente e link de uso único; convite expirado/repetido é rejeitado; e-mail de senha em texto puro nunca é enviado.
- Username/e-mail duplicados e colisões Entra/local são tratados sem auto-link; senha armazenada somente como hash.
- Contas pendentes, desativadas e bloqueadas não autenticam; criação transacional não deixa papel parcial se falhar.

**Login local e Entra**
- Entra continua funcional sem regressão. A tela oferece ambas opções e fluxos distintos.
- Login local funciona no provider seguro escolhido; logout encerra sessão; reset revoga sessões; CSRF e rate limiting funcionam.
- Erros são não enumeráveis; senha/token não aparece em logs; indisponibilidade do Entra não habilita fallback local.
- Verificar redirect/return URL contra open redirect.

**Operação/qualidade**
- Rodar migrations em banco vazio e em cópia de schema existente sem apagar dados.
- Executar linters e suíte de testes relevante; informar comandos e resultados reais, sem afirmar sucesso não verificado.
- Testar `/admin/groups` no navegador nas resoluções suportadas e com navegação por teclado.

Critérios de aceite funcionais:

1. Um administrador autorizado abre `/admin/groups` e alterna entre **Usuários**, **Grupos** e **Papéis**.
2. Selecionar um usuário abre perfil com todos os dados disponíveis e autorizados, separados por categoria e com origem do dado.
3. O administrador consegue criar uma conta local pendente de ativação, sem definir/enviar senha em texto claro.
4. O administrador consegue adicionar/remover um usuário a um grupo TMSA e, onde modelado, a uma associação de raia com escopo correto; esses atos não alteram membership do Entra.
5. Cada papel apresenta permissões efetivas e lista de usuários, incluindo origem direta ou herdada.
6. A tela de login oferece Entra ID e conta local; cada opção autentica apenas sua classe de identidade.
7. Todas as mutações/ações sensíveis ficam auditadas e protegidas por autorização server-side.

### 11. Instruções para a resposta final

Ao terminar, apresente:

- Funcionalidades concluídas e principais paths de frontend/backend.
- Entidades/migrations atualizadas e endpoints/rotas novos.
- Decisão de sessão/guard para autenticação local e como ela convive com Entra.
- Como provisionar um usuário local em ambiente de desenvolvimento e testar reset de senha usando Mailpit/mocks.
- Testes executados, resultados e limitações conhecidas.
- Pendências para homologação/segurança, como política de senha/MFA local e estratégia de associação de raias.

Não acesse produção, não crie usuários reais em diretórios corporativos, não altere consentimentos ou permissões do Entra e não envie e-mails externos durante o desenvolvimento. Use fixtures, mock Graph e Mailpit.
