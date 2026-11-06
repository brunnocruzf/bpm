---
name: bpm-release-validation
description: Seleciona e executa a validação proporcional para mudanças no BPM TMSA antes de revisão ou release. Use após alterar Laravel, Vue, OpenAPI, migrations, documentação, Docker, backup ou contratos entre frontend, API e Flowable.
---

# Validação de release do BPM

Preserve o trabalho existente. Inspecione `git status --short` e o diff antes dos comandos; não reverta, formate ou corrija arquivos fora do escopo da mudança.

## Seleção por escopo

### API Laravel

Para mudanças em `apps/api`, selecione os testes afetados e depois a suíte compatível com o risco:

```bash
composer test --working-dir=apps/api
composer analyse --working-dir=apps/api
composer lint --working-dir=apps/api
composer validate --working-dir=apps/api --no-check-publish --no-interaction
```

Em alterações de concorrência, outbox ou autorização, inclua regressões de comportamento, não apenas testes de unidades internas.

### Frontend Vue

Para mudanças em `apps/web`:

```bash
npm --prefix apps/web test
npm --prefix apps/web run typecheck
npm --prefix apps/web run build
```

Use Playwright ou Chrome DevTools para fluxos de navegador, acessibilidade, rede e performance quando a mudança exigir evidência visual ou runtime. Não trate build como substituto de E2E.

### Documentação e contratos

- Após alterar uma fonte coberta por `apps/web/scripts/sync-docs.mjs`, execute `npm --prefix apps/web run docs:sync` e revise somente as cópias esperadas.
- Ao alterar rotas ou payloads, confira `apps/api/openapi.yaml`, tipos em `apps/web/src/api`, validação servidor e testes de contrato.
- Ao alterar regras arquiteturais, atualize a ADR correspondente e documentos derivados necessários.

### Docker e infraestrutura

A partir de `deploy/docker`, valide a configuração resolvida sem publicar segredos:

```bash
docker compose config --quiet
docker compose config --services
docker compose ps
```

Não execute build ou suba toda a stack para uma alteração puramente documental. Para mudanças operacionais, valide health/ready e os serviços afetados.

## Escalada de risco

- Migration, fila, outbox, autorização ou auditoria: suíte API completa e regressões específicas.
- Form.io ou submissões: valide schema e dados no servidor; nunca dependa apenas da validação no navegador.
- Backup/restauração: use também a skill `bpm-backup-restore` e suas barreiras de ambiente descartável.
- Falha multi-serviço: use `bpm-runtime-debugging` antes de modificar estados ou repetir comandos.

## Critério de conclusão

Relate comandos executados, resultados, cobertura não executada e falhas preexistentes separadamente. Não declare release pronta se houver teste relevante ausente, falha sem explicação ou operação destrutiva pendente de aprovação.
