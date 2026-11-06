# Backup e recuperação

Este guia mostra como proteger os dados do BPM, restaurar um backup e o que fazer se o servidor for perdido. Tudo fica em **Configurações → Backup e recuperação** e exige permissão de administrador.

## Antes de começar

1. **Gere a chave de recuperação.** Em **Agendamentos e destino**, clique em **Gerar chave**. A chave aparece uma única vez: guarde no cofre de senhas da empresa, fora do servidor, e cole de volta para confirmar.

   > Sem a chave, nenhum backup pode ser restaurado, e ninguém consegue recuperá-la. Baixe o **kit de recuperação** e guarde junto.

2. **Ligue o envio ao Azure.** Primeiro ative o Azure Blob Storage em **Armazenamento de anexos**. Depois marque **Enviar cópias ao Azure**, informe a pasta, clique em **Salvar** e em **Testar destino**.

   > A pasta fica no mesmo contêiner dos anexos: quem tiver a chave da conta consegue apagar os dois. Mantenha a exclusão reversível (soft delete) ligada no Azure.

3. **Crie os agendamentos.** Uma combinação que funciona bem:

| O que copia | Quando | Cópias guardadas |
|---|---|---|
| **Banco** | Todo dia | 7 |
| **Dados** | Toda semana | 4 |
| **Aplicação inteira** | Todo mês | 12 |

## O que cada nível copia

| Nível | Conteúdo | Para que serve |
|---|---|---|
| **Banco** | Os dois bancos de dados | Voltar os dados no tempo |
| **Dados** | Bancos e arquivos da plataforma: anexos, relatórios, auditoria arquivada, logos e fotos | Voltar dados e arquivos juntos |
| **Aplicação inteira** | Dados, configuração e as imagens de todos os containers | Recuperar em um servidor novo |

Anexos que já estão no Azure não entram no backup: eles ficam na conta de armazenamento.

Todo backup sai criptografado. Com o agendamento diário, o pior caso é perder as últimas 24 horas.

## Testar um backup

Um backup que nunca foi testado não é garantia. Clique em **Testar** ao lado de um backup e informe a chave de recuperação. O sistema carrega o backup em bancos temporários e os apaga no fim. Os dados em uso não mudam e o sistema continua no ar.

O teste prova duas coisas: que o backup restaura e que a chave guardada é a certa. A tela avisa quando passam 90 dias sem teste.

## Restaurar um backup

Use quando os dados estiverem errados e o servidor estiver de pé.

1. Em **Backups**, clique em **Restaurar** no backup desejado.
2. Confira a data: tudo o que foi registrado depois dela será substituído.
3. Digite o nome da organização, a justificativa e a chave de recuperação.

O sistema faz uma cópia do estado atual e prepara os dados com tudo no ar. Só a troca dos dados deixa o sistema indisponível, por alguns minutos. Quem estiver usando vê uma página de manutenção, que atualiza sozinha.

### Revisar e liberar

Depois da troca, o sistema fica em **modo seguro**: só administradores entram, e e-mails e integrações ficam pausados.

1. Entre de novo e confira os dados.
2. Em **Revisar restauração**, veja as integrações que estão aguardando. Elas podem já ter rodado depois do backup: confira no sistema de destino.
3. Escolha o que fazer com os e-mails pendentes. Alguns podem já ter sido enviados.
4. Clique em **Liberar o sistema**.

Se algo estiver errado, clique em **Desfazer restauração** para voltar a como estava antes. Depois de liberar, não é mais possível desfazer.

## Se o servidor for perdido

A tela não ajuda quando o servidor não existe mais. A equipe de TI usa o roteiro `dr-restore.sh` em um servidor novo, com a chave de recuperação e o acesso ao portal do Azure. O roteiro e as instruções ficam na própria pasta de backups do Azure, em `LEIA-ME-RECUPERACAO.md`.

Para isso funcionar, é preciso ter um backup de **Aplicação inteira** no Azure. A tela avisa quando não há um recente.

## Quando algo dá errado

| O que aparece | O que significa | O que fazer |
|---|---|---|
| **Agente de backup: Fora do ar** | O serviço que faz os backups não está respondendo. | Peça à TI para conferir o serviço `backup-agent`. |
| **Sem cópia no Azure** | O backup ficou só no servidor porque o envio falhou. | Clique em **Enviar ao Azure**. Se falhar de novo, use **Testar destino**. |
| **Esta chave não abre este backup** | O backup foi criado com outra chave. | Use a chave indicada na mensagem. Backups antigos continuam pedindo a chave com que foram criados. |
| **Backup feito por uma versão mais nova** | A aplicação em uso é mais antiga que o backup. | Atualize a aplicação antes de restaurar. |
| **Backup atrasado** (e-mail) | Um agendamento não terminou no prazo. | Veja a aba **Log** para saber o motivo. |
