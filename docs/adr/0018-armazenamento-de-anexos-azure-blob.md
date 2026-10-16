# ADR-0018 — Armazenamento de anexos configurável (Azure Blob Storage)

- **Status:** aceito
- **Relaciona:** ADR-0007 (storage de anexos), ADR-0008 (licenças), ADR-0014 (configurações e segredos)

## Contexto

Os arquivos enviados nos formulários (Form.io) e nas ações dos fornecedores iam sempre para o disco do ambiente
(`BPM_ATTACHMENTS_DISK`: RustFS/S3 ou disco local). A TMSA já usa **Azure Blob Storage** com **chave da conta** (Shared Key)
em outro sistema e quer escolher o destino **por tela**, sem editar `.env`. O SDK PHP oficial usado lá
(`microsoft/azure-storage-blob`) foi **aposentado pela Microsoft em 17/03/2024** e não instala neste projeto (exige Guzzle
`~6|^7`; o projeto usa Guzzle 8). O `league/flysystem-azure-blob-storage` está abandonado.

## Decisão

1. **Configurações → Armazenamento de anexos** (`attachments.storage`, `requiresActivation`): destino *plataforma* (padrão do
   ambiente) ou *Azure Blob Storage* — conta, nuvem, contêiner, prefixo, **chave da conta somente escrita** (cofre `db:` ou
   segredo montado `file:`), referência da aprovação do uso de chave de conta e checklist.
2. **Salvar não muda o destino.** Fluxo: Salvar → **Testar** (política de rede, credencial, contêiner existente e **privado**,
   gravação → leitura com SHA-256 → exclusão de um arquivo de prova, tudo real) → **Ativar** (409 sem teste aprovado na mesma
   versão). "Voltar ao padrão" desativa; o teste e a ativação são auditados (`settings.validated/activated/disabled`).
3. **Transporte próprio, sem dependência nova:** `SharedKeySigner` + `AzureBlobClient` (cliente HTTP do Laravel, sem
   redirecionamentos, timeouts, retry em 500/503) + `AzureBlobAdapter` (Flysystem 3), registrado como driver `bpm-azure-blob`.
   A assinatura é validada pelo exemplo documentado pela Microsoft, por um fake que a recalcula de forma independente e por
   teste de integração contra o **Azurite**.
4. **Registro de destinos (`storage_target`).** Cada anexo guarda o nome do disco (`attachment.disk`); discos `azblob_<hash>`
   resolvem pelo registro, inclusive destinos **aposentados**, e cada destino tem **cópia própria** do segredo. Assim editar a
   configuração, falhar um reteste ou trocar de contêiner **nunca** deixa anexo antigo ilegível. Mesma identidade
   (endpoint/conta/contêiner/prefixo) com chave nova é **rotação**: o segredo do destino é atualizado. **Arquivos não são
   migrados** entre destinos.
5. **Sem fallback silencioso:** falha ao gravar no destino ativo é `503 storage_unavailable` (não grava em outro disco).
6. **Rede e SSRF:** o endpoint é montado no servidor (`https://{conta}.blob.{sufixo}`, sufixo por nuvem), validado pela
   `TargetPolicy` (em produção o host precisa estar em `BPM_INTEGRATION_ALLOWED_HOSTS`). O emulador só existe fora de
   produção, com endpoint vindo do ambiente (`BPM_AZURITE_BLOB_ENDPOINT`), nunca da tela.
7. **O navegador nunca fala com o Azure.** Upload e download continuam pela API (URL assinada de 5 minutos do Laravel); o
   contêiner é privado e nenhuma SAS chega ao SPA. Logos, relatórios, auditoria e fotos ficam no disco do ambiente.
8. **Segredo e log:** a chave nunca é devolvida, auditada nem registrada (Masker cobre `AccountKey=`/`SharedAccessSignature=`);
   erros viram códigos estáveis explicados na tela (`shared_key_disabled`, `container_public`, `clock_skew`…).

## Consequências

- Shared Key dá acesso à **conta de armazenamento inteira** e contas corporativas podem tê-la desligada por Azure Policy
  (`shared_key_disabled`): use conta dedicada ao BPM, segredo montado em produção e rotação periódica. SAS de contêiner e
  Entra ID + RBAC (`Storage Blob Data Contributor`) ficam como evolução.
- Adaptador próprio exige manter a `x-ms-version` (config `bpm.storage.azure.api_version`) e a canonicalização da assinatura
  cobertas por teste; Put Blob é um único PUT (anexos até 20 MB).
- Backup passa a incluir o Azure (soft delete/versionamento/imutabilidade) além do volume `storagedata`.
- Latência de upload (síncrono na requisição) depende do link até o Azure; timeouts configuráveis (`BPM_STORAGE_TIMEOUT`).
- Pendências: provisionar conta/contêiner, política de Shared Key, egress/firewall, retenção e residência de dados,
  migração dos arquivos já gravados (se desejada).
