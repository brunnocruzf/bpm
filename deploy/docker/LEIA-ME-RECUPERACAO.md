# Recuperação do BPM em um servidor novo

Use este roteiro quando o servidor foi perdido. Se o servidor está de pé, restaure pela tela: **Configurações → Backup e recuperação**.

Este arquivo e o `dr-restore.sh` ficam na pasta de backups do Azure e não têm segredos.

## O que você precisa

- **Chave de recuperação** da organização (`AGE-SECRET-KEY-1...`), guardada fora do servidor. Sem ela, nenhum backup abre.
- **Acesso ao portal do Azure**, para pegar a chave da conta de armazenamento.
- **Um servidor Linux com Docker e Docker Compose**, com acesso à internet e espaço para o backup e as imagens.
- Um backup de nível **Aplicação inteira**. Os níveis Banco e Dados não trazem configuração nem imagens.

## Passo a passo

1. No portal do Azure, abra a conta de armazenamento e copie a **chave da conta** (Segurança + rede → Chaves de acesso).
2. Baixe `dr-restore.sh` desta pasta para o servidor e dê permissão de execução: `chmod +x dr-restore.sh`.
3. Grave a chave de recuperação em um arquivo, por exemplo `chave-recuperacao.txt`, só com a linha `AGE-SECRET-KEY-1...`.
4. Rode o roteiro, trocando os valores pelos da sua organização:

   ```bash
   export AZURE_ACCOUNT_KEY='<chave da conta>'
   ./dr-restore.sh --org tmsa --account <conta> --container <contêiner> --path <prefixo>/<pasta> \
     --key-file ./chave-recuperacao.txt --dir /opt/bpm
   ```

   O `--path` é o caminho da pasta de backups dentro do contêiner, com o prefixo dos anexos quando houver: `bpm/backups`.
   Sem `--backup`, o roteiro usa o backup de Aplicação inteira mais recente.

5. Quando terminar, a aplicação estará em **modo seguro**. Aponte o DNS ou o proxy para o novo servidor. Se o endereço público mudou, ajuste `PUBLIC_URL` no `.env` e rode `docker compose up -d`.
6. Entre como administrador, confira os dados e libere o sistema em **Configurações → Backup e recuperação**. Aqui não existe **Desfazer**: antes da recuperação, o servidor novo estava vazio.
7. Apague o arquivo com a chave de recuperação e a pasta `dr-work` do servidor.

## O que o roteiro faz

Baixa o backup, confere o SHA-256 de cada arquivo, abre a configuração, carrega as imagens, sobe banco e armazenamento, importa os dados e sobe o resto da stack. Nada é baixado de registros de imagem além do contêiner auxiliar usado no download.

## Depois de recuperar

- **Carbone:** se a organização usa, copie `secrets/carbone_key.pub` para `deploy/secrets/`: ele não entra no backup.
- **Anexos no Azure:** continuam na conta de armazenamento e voltam a funcionar com a chave restaurada.
- **Comandos seguintes:** a stack passa a usar `docker-compose.dr.yml`, que fixa as imagens carregadas. Para atualizar a versão, siga o procedimento normal do runbook.
- **Agendamentos:** voltam junto com os dados. Confira a pasta do Azure e faça um backup novo depois de liberar.
