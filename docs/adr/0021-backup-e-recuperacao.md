# ADR-0021 — Backup e recuperação

- **Status:** aceito
- **Relaciona:** ADR-0005 (outbox e reconciliação), ADR-0010 (auditoria), ADR-0014 (configurações e segredos), ADR-0018 (Azure Blob), ADR-0019 (uma instância por organização)

## Contexto

Havia só `orgs.sh backup` e dois comandos no runbook. A operação precisa agendar backups, restaurar pela tela e se preparar para a perda total do servidor, com cópia no Azure Blob. O alvo é o Compose em servidor Linux, uma stack por organização.

Restaurar um sistema com dois bancos, um engine e integrações externas não é rodar `pg_restore`. O código mostrou riscos concretos: `START` só reconcilia com `attempts > 1` e duplicaria processos; se o engine ficar mais antigo que a aplicação, o reconciliador cancela tarefas como "removida do engine"; jobs de integração reaparecem depois de já terem escrito no AX; e o estado da própria restauração não pode morar no banco que está sendo trocado.

## Decisão

1. **Serviço `backup-agent`, com a mesma aplicação em outro estágio da imagem.** Executa um pedido por vez, fora das filas, e tem cliente PostgreSQL 17: a imagem da API traz o 18 em `PATH`, e o PostgreSQL não garante carregar dump de um cliente mais novo em servidor mais antigo. Não abre porta. Só ele recebe a senha de superusuário e lê o `.env`.

2. **Pedidos por tabela de tipos fixos (`backup_job`), nunca pela fila do Laravel.** A fila desserializa PHP: quem escrevesse no banco executaria código no serviço com mais acesso.

3. **`docker-gate`: nginx com lista de permissão na frente do socket.** É o único serviço com o socket. Libera parar e iniciar `queue-worker`, `scheduler` e `flowable-rest`, consultar containers da stack e exportar imagens por ID. Todo o resto é 403. A lista usa `${COMPOSE_PROJECT_NAME}`, o que preserva o isolamento entre organizações. O `tecnativa/docker-socket-proxy` foi descartado: parar e iniciar exige `POST=1`, que somado a `IMAGES=1` libera `POST /images/load` e permitiria trocar a imagem da API. `deploy/docker/tests/docker-gate-test.sh` confere a lista.

4. **Três níveis:** Banco (`bpm_app`, `flowable`, papéis), Dados (mais os arquivos da plataforma) e Aplicação inteira (mais `.env`, chave do cofre, `deploy/docker` e imagens). Container não guarda estado: "todos os containers" vira imagens, dados e configuração. Cada imagem é guardada uma vez por digest.

5. **Criptografia com `age`, chave privada fora do servidor.** O servidor só tem a chave pública: gerar backup não exige a privada, e restaurar exige. Vazar o Azure não expõe dados, e a chave funciona como segundo fator de uma ação destrutiva. A chave do cofre entra no pacote criptografada, o que mantém a separação pedida pelo runbook. Na restauração a chave chega ao agente cifrada com `sodium_crypto_box_seal` para uma chave efêmera dele, vira arquivo em `tmpfs` e some.

6. **Dumps lógicos, `bpm_app` antes de `flowable`.** O outbox e o reconciliador pausam só até os dois snapshots abrirem, normalmente segundos. Assim nenhuma submissão fica de um lado só. Se ainda assim houver diferença, o engine é o mais novo, e a reconciliação conserta nesse sentido.

7. **Restaurar ao lado e trocar por renomeação.** A carga vai para `*_restore` com o sistema no ar. Só a troca exige manutenção: parar fila, agendador e Flowable e renomear os quatro bancos em uma transação. Falha na carga não toca nos dados em uso, e desfazer é a mesma troca ao contrário. Os bancos antigos ficam até a liberação.

8. **Depois da troca:** migrations, comandos não confirmados voltam a `RECONCILIATION_REQUIRED` com `attempts = 1`, a tabela `jobs` e o cache são esvaziados, todas as sessões são revogadas e os eventos de auditoria posteriores ao backup são arquivados com SHA-256 em `audit-archive/restores/`.

9. **Modo seguro até a liberação.** Só `settings.manage` entra, e integrações e e-mails ficam pausados. O administrador escolhe enviar ou descartar os e-mails pendentes. Isso evita repetir efeitos externos antes de alguém revisar, e torna o desfazer sem perda.

10. **Estado fora do banco.** `runtime.json`, em volume próprio, é escrito só pelo agente e lido pelos demais: `normal`, `pause`, `maintenance` e `safe`. Durante a troca a API responde 503 sem tocar no banco. O mesmo arquivo guarda o diário que permite retomar se o agente cair: antes da troca ele limpa, depois da troca ele desfaz.

11. **O manifesto é a fonte da verdade.** `manifest.json` é gravado por último, e `backup_catalog` é cache refeito do disco e do Azure. Backup sem manifesto não existe.

12. **Destino no Azure:** pasta no contêiner do destino ativo de Armazenamento de anexos, relativa ao prefixo dele, com a mesma credencial. Escolha do projeto, ciente do risco abaixo.

13. **A tela restaura bancos e arquivos.** Imagens e configuração voltam só por `deploy/docker/dr-restore.sh`, que precisa apenas de Docker, da chave da conta e da chave de recuperação. Restaurar imagens pela tela obrigaria o filtro a liberar `images/load` e criação de containers. O roteiro chama `bpm:backup-import`, o mesmo caminho da tela, e termina no modo seguro sem a opção de desfazer: os bancos anteriores são os vazios da instalação nova.

14. **A retomada lê o banco, não só o diário.** Se o agente cair entre a troca e o registro da fase, os nomes dos bancos mostram se a troca aconteceu. Voltar aos bancos antigos pode rodar mais de uma vez. A cópia de segurança feita antes de restaurar não dispara a retenção, que poderia apagar o backup em uso, e ela própria fica guardada por 30 dias, fora da retenção dos agendamentos.

## Consequências

- **Perder a chave de recuperação perde todos os backups feitos com ela.** A confirmação ao gerar e o "Testar restauração" existem por isso.
- **Quem tem a chave da conta Azure apaga anexos e backups juntos.** A proteção é o soft delete da conta e as cópias no servidor. Imutabilidade exigiria contêiner próprio, porque ela vale por contêiner e os anexos precisam poder ser apagados; o formato não muda se isso for adotado.
- **O pior caso é o intervalo entre backups:** 24 horas no agendamento diário. Reduzir isso pede arquivamento contínuo do PostgreSQL.
- **Integração pode repetir na liberação** se o destino não for idempotente. O modo seguro lista as pendentes; a conferência é humana.
- **`docker-gate` tem o socket:** uma falha no próprio nginx daria controle do host. Ele fica em rede privada, com um único cliente.
- **Backup forjado:** quem tem a chave do Azure e a chave pública pode criar um. A tela compara o SHA-256 do manifesto com o catálogo; o `dr-restore.sh` não tem essa comparação.
- **Restaurar pede espaço:** os bancos antigos e os novos convivem até a liberação.
- Cada organização ganha dois serviços: `backup-agent` (até 512 MB) e `docker-gate` (até 32 MB).

## Validação

`deploy/docker/tests/backup-e2e.mjs` sobe uma stack descartável com PostgreSQL, Flowable e Azurite reais: backup, recusas, teste de restauração, restauração, modo seguro, desfazer, liberação e o ensaio de desastre, que apaga volumes e disco de backups e sobe tudo de novo. Nenhum teste usa Azure real, homologação ou produção.
