# Próximos passos das integrações nos formulários

> Este documento descreve **melhorias planejadas**. O que já funciona está em
> [Usar consultas nos formulários](guia-consumir-formulario.md),
> [Etapa do tipo Integração](guia-etapa-integracao.md) e
> [Criar um novo mapeamento](guia-mapeamentos-e-mock.md#criar-um-novo-mapeamento).

## Como funciona hoje

| O que você quer fazer | Situação |
|---|---|
| Digitar um código e preencher o formulário com dados do sistema externo | ✅ Já funciona (componente **Consulta AX**). |
| Aceitar "não encontrado" como resposta válida (ex.: fornecedor que ainda não existe no AX) | ❌ Hoje "não encontrado" sempre impede o envio. |
| Escolher o campo de destino numa lista, em vez de digitar a chave | ⚠ Hoje a chave é digitada. |
| Lista de opções vinda do sistema externo (grupo de fornecedor, condição de pagamento, moeda…) | ❌ Não existe. |
| Preencher uma tabela de linhas com dados do sistema (ex.: linhas da ordem de compra) | ❌ Não existe. |
| Consultar automaticamente numa etapa, sem o usuário clicar | ✅ Já funciona: **Novo mapeamento** do tipo *Etapa automática do fluxo* com um serviço de leitura. |
| Gravar no sistema externo usando qualquer serviço cadastrado | ✅ Já funciona: **Novo mapeamento** do tipo *Etapa automática do fluxo* com um serviço de gravação (a conexão precisa estar liberada para escritas). |
| Ajustar o dado antes de enviar (tirar pontuação do CNPJ, converter datas, "sim/não" em 1/0) | ❌ Não existe. |

## O que está planejado

### 1. Consultas melhores nos formulários

- Opção de **aceitar "não encontrado"** (ou só avisar), preenchendo um campo "existe no sistema"
  (sim/não) que pode ser usado nas decisões do fluxo.
- **Escolher o campo de destino numa lista** e ver só as informações da consulta escolhida.
- **Ajustar o valor antes de consultar**, por exemplo tirar a pontuação do CNPJ ou do CEP.

### 2. Lista de opções vinda do sistema externo

Um novo componente para escolher um valor válido do sistema externo (grupo de fornecedores, grupo de
impostos, condição e forma de pagamento, moeda, estabelecimento). O valor escolhido seria conferido de
novo pelo BPM ao enviar, e o nome da opção ficaria guardado para aparecer nas etapas seguintes e nos
relatórios.

### 3. Etapa automática mais completa

Hoje a etapa automática já chama qualquer serviço cadastrado (pelo **Novo mapeamento**). Ainda faltam:
**ajustar os dados** antes de enviar (datas, números, "sim/não"), uma **pré-visualização** do que seria
enviado e um **caminho alternativo** no fluxo quando a integração falhar de vez.

### 4. Tabelas preenchidas pelo sistema externo

Preencher uma tabela de linhas com dados do sistema (ex.: as linhas de uma ordem de compra).

## Aplicação ao processo de homologação de fornecedor

Hoje o processo tem duas etapas (**Solicitar homologação** e **Avaliar documentação**) e nada é consultado
nem gravado no AX: depois de homologado, o fornecedor ainda precisa ser cadastrado à mão.

Com as melhorias, o processo poderia ficar assim:

1. **Solicitar:** ao digitar o CNPJ, o BPM verifica se o fornecedor já existe no AX. O CEP preenche o
   endereço. Grupo, impostos, condição de pagamento, moeda e estabelecimento seriam escolhidos em listas
   vindas do AX.
2. Se o fornecedor **já existir**, o processo termina como "Já cadastrado", mostrando a conta encontrada.
3. Se não existir, segue para **Avaliar documentação**.
4. **Aprovado:** uma etapa automática cadastra o fornecedor no AX e mostra a conta criada para Suprimentos.
   Isso já pode ser montado com um **Novo mapeamento** do tipo *Etapa automática*; falta a equipe do AX
   confirmar o que o serviço de cadastro devolve (veja as dúvidas abaixo).
5. Se o cadastro falhar de vez, uma tarefa é aberta para Suprimentos tratar.

## O que não fazer, mesmo depois das melhorias

- Enviar dados para o sistema externo **direto do formulário**: a gravação sempre acontece numa etapa
  automática, com controle contra duplicidade.
- Confiar em valores que vieram do navegador: o BPM sempre confere de novo ao salvar.
- Colocar senhas ou códigos de acesso no formulário.

## Dúvidas em aberto com a equipe do AX

1. O que significa o parâmetro **filter** em várias listas do AX.
2. O que o AX devolve ao cadastrar um fornecedor (a conta criada?) e o formato das listas.
3. Quais campos são obrigatórios no cadastro de fornecedor e quais códigos usar para tipos de contato e
   país.
4. Como o AX evita cadastrar o mesmo fornecedor duas vezes.
5. Ambiente de homologação e usuário de serviço exclusivo para o BPM.
