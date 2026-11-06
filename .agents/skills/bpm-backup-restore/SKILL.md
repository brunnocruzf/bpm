---
name: bpm-backup-restore
description: Orienta implementação, revisão e teste de backup, restauração, modo seguro e recuperação de desastre do BPM TMSA. Use ao alterar backup-agent, docker-gate, backup_job, storage de backups, dr-restore.sh ou a interface administrativa de recuperação.
---

# Backup e recuperação do BPM

O padrão é somente leitura e ambiente descartável. Restauração, exclusão de volumes e liberação do modo seguro são operações destrutivas ou capazes de repetir efeitos externos.

## Referências obrigatórias

- `docs/adr/0021-backup-e-recuperacao.md` integralmente.
- `docs/runbook.md`, seção de backup e recuperação aplicável.
- `deploy/docker/LEIA-ME-RECUPERACAO.md` e `docs/integracoes/guia-backup-e-recuperacao.md`.
- ADRs relacionadas 0005, 0010, 0014, 0018 e 0019 quando a mudança cruzar essas fronteiras.

## Antes de agir

1. Identifique organização, stack, nível do backup, destino, fase da restauração e se os bancos originais ainda existem.
2. Confirme que o `manifest.json` é a fonte da verdade; `backup_catalog` é apenas cache. Backup sem manifesto não existe.
3. Preserve a ordem dos dumps `bpm_app` antes de `flowable`, a troca por renomeação e o estado externo em `runtime.json`.
4. Verifique espaço para bancos antigos e novos coexistirem e a disponibilidade da chave privada de recuperação sem imprimi-la ou copiá-la para logs.
5. Em código, preserve o `docker-gate` como lista de permissão por stack. Não amplie acesso ao socket, `images/load`, criação de containers ou controle de outra organização.

## Testes por mudança

- Domínio/API: execute os testes relevantes de `apps/api/tests/Feature/BackupTest.php`.
- Filtro Docker: após alterar `deploy/docker/nginx/docker-gate.conf.template`, execute `deploy/docker/tests/docker-gate-test.sh` em shell compatível; ele usa containers `gatetest` descartáveis.
- Fluxo completo: use `node deploy/docker/tests/backup-e2e.mjs` somente quando a stack descartável `bpm-drtest`, portas 18081/18100/18026 e recursos temporários estiverem disponíveis.
- Investigação longa: `--keep` ou `--reuse` só com diretório temporário conhecido; remova somente os artefatos descartáveis criados pelo teste depois da análise.

## Barreiras de segurança

- Nunca aponte o E2E para homologação ou produção: ele restaura bancos e apaga volumes.
- Não execute restauração real, `down -v`, exclusão de backup ou `dr-restore.sh` sem autorização explícita, plano de rollback e confirmação do ambiente.
- Não execute ações em uma stack pelo nome sem verificar `COMPOSE_PROJECT_NAME`, volumes e portas.
- Não libere integrações ou e-mails automaticamente. O modo seguro exige revisão humana dos efeitos pendentes e das integrações idempotentes.
- Não registre chaves age, chave do cofre, credenciais Azure, conteúdo de dumps ou dados protegidos em logs, auditoria ou resposta.
- Não execute retenção sobre a cópia de segurança feita antes de uma restauração.

## Resultado

Documente o nível testado, stack e artefatos descartáveis usados, fases validadas, falhas, limpeza realizada e riscos remanescentes. Um teste de unidade não comprova recuperação de desastre.
