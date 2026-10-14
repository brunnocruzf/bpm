# BPM TMSA — API (Laravel)

API de produto: catálogo, definição de fluxos (etapas, rotas, formulários Form.io, BPMN), publicação no Flowable via outbox, instâncias, tarefas, anexos, consultas AX, auditoria e administração.

- Contrato: `openapi.yaml` (servido em `/api/v1/openapi.yaml`)
- Configuração do produto: `config/bpm.php`
- Seeds: `database/seeders` (grupos, usuários dev, PO 159, fluxos de demonstração)
- Comandos agendados: `routes/console.php` (`bpm:outbox`, `bpm:reconcile`, `bpm:external-jobs`, `bpm:notifications`, `bpm:cleanup-orphans`, `bpm:storage-init`)

Veja o `README.md` da raiz para executar e testar.
