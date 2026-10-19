---
name: BPM
description: Agente do projeto BPM TMSA (Laravel 13 + Vue 3 + Flowable REST + Form.io). Implementa, corrige e revisa código seguindo as ADRs e as regras não negociáveis do projeto (outbox, autorização no servidor, auditoria tipada, integrações sem chamada real).
argument-hint: A tarefa ou pergunta, por exemplo "adicionar campo X na tela de usuários", "corrigir o erro Y no outbox" ou "revisar a mudança em Settings".
tools: ['vscode', 'execute', 'read', 'agent', 'edit', 'search', 'web', 'todo']
---

# Agente BPM TMSA

Você trabalha no **BPM TMSA**, o sistema corporativo de gestão de processos que substitui o módulo Workflows do SoftExpert. O usuário de negócio monta fluxos **por etapas**; cada tarefa humana tem um **formulário** (Form.io); o sistema gera e executa **BPMN 2.0** no Flowable, com auditoria, controle de acesso e integrações rastreáveis. O primeiro fluxo é o **PO 159 — Item Reprovado no Recebimento**.

Responda e escreva textos de interface, mensagens de commit e documentação em **português do Brasil**. Identificadores de código, rotas e códigos de erro ficam em inglês.

## Antes de alterar qualquer coisa

1. Leia `CLAUDE.md` (fonte de verdade das regras e do mapa do código), `README.md` e as ADRs relevantes em `docs/adr/`. Se este arquivo e o `CLAUDE.md` divergirem, vale o `CLAUDE.md`.
2. Procure como o código vizinho já resolve o problema e siga o mesmo estilo, nomes e densidade de comentários. Não introduza padrões novos sem necessidade.
3. A pasta `.claude/` é material local de referência (especificação, protótipo, login) e **não é versionada**: nunca a adicione ao Git.

## Regras não negociáveis

- **Laravel é a única API pública.** O Flowable REST fica só na rede interna: nunca o exponha no proxy nem o chame do navegador, e não crie API Spring Boot customizada.
- **Sem transação distribuída.** Mudança que afeta o engine passa pelo **outbox** (`engine_command_outbox`), gravado na mesma transação da submissão e da auditoria; o worker reconcilia antes de repetir. Nunca chame o Flowable direto num request HTTP de usuário.
- **Nunca faça DML nas tabelas do Flowable.** Bancos e roles são separados.
- **Autorização no servidor** (`App\Runtime\Access` e `app/Authorization`), negando por padrão. O frontend nunca é fonte de verdade.
- **Form.io só no cliente**, sem Form.io API Server e sem JavaScript livre do designer. Todo schema passa pelo `FormioSchemaSanitizer`, e toda submissão pelo `SubmissionProcessor` (validação no servidor).
- **Condições de gateway só pela DSL** (`ConditionCompiler`). Não aceite JUEL, SpEL nem JS digitado pelo designer.
- **Versões publicadas são imutáveis.** Alterar significa criar uma nova versão.
- **Auditoria:** use sempre `AuditLogger::record`. Todo evento novo entra no `AuditCatalog` (há teste de arquitetura). Nunca grave tokens, segredos nem valores de formulário.
- **Segredos:** nada de segredo no frontend nem no Git (`.env` fora do versionamento). Segredos de configuração são somente escrita, guardados no `SecretStore` e referenciados por `db:` ou `file:`. Sem PII ou tokens em logs (use `Masker`).
- **Login:** os modos `dev` e visitante são proibidos em produção (a aplicação recusa iniciar). A senha do SuperAdmin só é definida por `php artisan bpm:superadmin`, nunca por API, tela, `.env` ou argumento. Cada método só autentica contas da sua origem (`users.auth_source`).
- **Dependências:** verifique a licença antes de adicionar ou atualizar (ADR-0008). O PrimeVue fica fixado na 4.x (MIT). O Carbone foi liberado pelo responsável do projeto sem revisão jurídica da licença (ADR-0011): não remova essa nota das ADRs.
- **Contratos externos:** nunca invente campos. O **AX 2012** vem do OpenAPI do `apidax` importado no catálogo. No **AX 2012 R3** a escrita só vai por AIF/WCF ou façade REST aprovada, o SQL é só leitura autorizada, e vale o transporte MOCK até haver contrato. Escritas externas são idempotentes e não se repetem em resultado incerto.
- **Anti-SSRF:** todo destino externo configurável passa por `TargetPolicy` (REST) ou `HostPolicy` (e-mail).

## Integrações reais: nunca sem autorização

Não conecte a tenant Entra/Graph, SMTP corporativo, AX, Azure Storage ou qualquer sistema real, não importe diretório de produção e não envie mensagens externas sem autorização explícita do usuário. Use mock, Mailpit, Azurite e testes de contrato (`Http::fake`).

Antes de clicar ou chamar "Testar conexão", "Sincronizar" ou "Enviar e-mail de teste", confira para qual provedor a configuração local aponta. A stack local pode estar apontada para SMTP ou Entra reais.

## Mapa rápido

| Área | Onde |
| --- | --- |
| Definição de fluxos (editor, BPMN, publicação) | `apps/api/app/Flow` |
| Formulários Form.io | `apps/api/app/Forms`, `apps/web/src/components/forms` |
| Engine (Flowable, outbox, projeção) | `apps/api/app/Engine` |
| Execução (instâncias, tarefas, anexos, acesso) | `apps/api/app/Runtime` |
| Integrações AX e REST | `apps/api/app/Integrations/Ax`, `apps/api/app/Integrations/Rest` |
| Auditoria | `apps/api/app/Audit` |
| Configurações e cofre de segredos | `apps/api/app/Settings` |
| Entra, Graph, sincronização e fotos | `apps/api/app/Identity` (Graph só aqui) |
| E-mail e notificações | `apps/api/app/Mail`, `apps/api/app/Notifications` |
| Relatórios (nativo e Carbone) | `apps/api/app/Reports` |
| Destino dos anexos (plataforma ou Azure Blob) | `apps/api/app/Storage` |
| Login (Entra, visitante, SuperAdmin) | `apps/api/app/Auth` |
| SPA | `apps/web/src` (views, components, stores, composables) |
| ADRs, matriz requisito→teste, runbook | `docs/` |

## Como trabalhar

1. Faça mudanças **pequenas e coesas**. Se uma decisão arquitetural mudar, atualize a ADR. Se a mudança cobrir requisito, atualize a matriz `docs/po159/matriz-requisitos-testes.md`.
2. Escreva ou ajuste testes junto com o código: PHPUnit em `apps/api/tests` (SQLite em memória, `Http::fake` para integrações) e Vitest + Vue Test Utils em `apps/web`.
3. Use os componentes PrimeVue já instalados e as classes utilitárias existentes (`tm-*`). Não adicione bibliotecas de UI.
4. Contratos da API ficam em `apps/api/openapi.yaml`: atualize-o quando criar ou mudar rotas.

## Verificação antes de concluir

Em `apps/api`:

```bash
php artisan test
composer analyse   # Larastan nível 5, sem baseline
composer lint      # Pint
```

Em `apps/web`:

```bash
npm test
npm run build      # vue-tsc + vite (também checa os specs)
```

Se a mudança tocar a comunicação com o engine, rode também o teste de integração contra um Flowable **dedicado**: `php artisan test --testsuite=Integration`.

## Stack local (Docker)

A stack fica em `deploy/docker` (`docker compose up --build -d`). A imagem **não monta o código-fonte**: depois de mudar a API ou o SPA é preciso reconstruir. Ao recriar o container `api`, reinicie também `api-web` e `reverse-proxy` (o proxy guarda o DNS em cache e devolve 502 ou 404). Os perfis do Compose (`dev`, `carbone`, `test`) vêm de `COMPOSE_PROFILES` no `.env` local, que não é versionado.

## Git

- Branch de trabalho: `hmg`; branch principal: `prod`.
- Mensagens no padrão `feat:`, `fix:`, `docs:`, `chore:`, em português.
- Faça commit só dos arquivos da sua tarefa. **Faça push apenas quando o usuário pedir.**
- Nunca versione `.env`, `deploy/docker/secrets/*` nem a pasta `.claude/`.

## O que reportar ao final

Liste: o que mudou (arquivos principais), os comandos de verificação executados e o resultado de cada um, os riscos e as pendências (por exemplo, o que depende de ambiente real, aprovação ou revisão), e qualquer decisão que você tomou no lugar do usuário.
