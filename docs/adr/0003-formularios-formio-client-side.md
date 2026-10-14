# ADR-0003 — Formulários com Form.io client-side, persistidos pela API TMSA

- **Status:** aceito

## Decisão

- Builder e Renderer do `@formio/js` (MIT) embutidos em componentes Vue (`FormBuilderPanel.vue`, `FormRenderer.vue`). **Não** há Form.io API Server (core server OSL 3.0) no MVP.
- Schema JSON é salvo por `PUT /definitions/{key}/versions/{v}/forms/{formKey}` somente em rascunho e passa pelo `FormioSchemaSanitizer`:
  - remove `customConditional`, `calculateValue`, `customDefaultValue`, `logic`, `validate.custom/json` e afins (sem JavaScript livre);
  - selects apenas com valores fixos (sem URL/recursos externos); upload sempre via storage `tmsa`;
  - HTML de conteúdo sanitizado por lista branca; rótulos como texto puro;
  - keys válidas, únicas e não reservadas; limites de tamanho/profundidade/quantidade.
- No builder, as abas/painéis de código (Lógica, JS de validação/condicional/cálculo) ficam ocultos; o renderer de execução roda com `Evaluator.noeval`.
- Submissões são validadas **no servidor** (`SubmissionProcessor`): visibilidade condicional, obrigatoriedade, tipos, opções, tamanhos, anexos autorizados; campos somente leitura vêm do contexto da instância; campos `tmsaAuto` são calculados no servidor; consultas AX são reexecutadas e registradas (origem/horário).
- Formulário pertence a **uma** etapa de **uma** versão (`form_schema_version.stage_version_id` único). "Usar como modelo" copia o schema (cópia independente).
- Mesma `key` em formulários diferentes = mesmo dado de negócio (cópia automática; tipos devem ser compatíveis — validado na publicação).

## Extensões TMSA

- `properties.tmsaAuto`: `user.name|user.area|user.email|now.date|now.datetime|instance.*|sequence:<CHAVE>|counter:<CHAVE>`.
- Componente `axlookup`: `lookupSource` (tpr, item, purchaseOrder, supplier) + `fillMap` (campo AX → key destino).

## Consequências

Componentes Form.io fora do subset (assinatura, endereço, editgrid, etc.) são recusados com mensagem explicativa. Schemas históricos continuam renderizáveis (submissão guarda o hash e aponta o schema da versão).

## CSP e `unsafe-eval`

O Form.io compila templates (lodash `template`) em runtime; com `script-src 'self'` o builder falha (`EvalError`). A CSP do SPA permite `'unsafe-eval'`, mas **não** `'unsafe-inline'` nem origens externas para scripts. Mitigações: propriedades de código do designer são removidas no servidor antes de persistir; o renderer de execução usa `Evaluator.noeval`; schemas só vêm da API TMSA. Reavaliar se o Form.io oferecer templates pré-compilados para o builder.

## Campos sensíveis (relatórios)

`properties.tmsaSensitive` (checkbox "Campo sensível" na aba API do builder) marca o campo como sensível: em relatórios e exportações ele aparece como "•••• restrito" para quem não tem a permissão `data.sensitive` (o autor da submissão vê o próprio dado). Chaves/tipos típicos de PII (CPF, CNPJ, RG, telefone, senha) são tratados como sensíveis automaticamente. Ver [ADR-0011](0011-relatorios-e-carbone.md).
