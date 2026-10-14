# ADR-0007 — Storage de anexos: S3-compatível (RustFS em dev/POC)

- **Status:** aceito

## Contexto

A especificação previa MinIO em dev/POC. Em 10/2026 as imagens `minio/minio` (Docker Hub) e `quay.io/minio/minio` não estão disponíveis para pull anônimo (a MinIO deixou de distribuir imagens da edição comunitária).

## Decisão

- Dev/POC: **RustFS 1.0.1** (`rustfs/rustfs:1.0.1`), compatível com a API S3, somente na rede interna; bucket privado `bpm-attachments` criado por `php artisan bpm:storage-init`.
- Laravel usa o disco `attachments_s3` (Flysystem S3, path-style); sem Docker, `attachments_local` (disco privado).
- Produção: storage corporativo S3/Blob aprovado (trocar endpoint/credenciais por ambiente; nenhuma alteração de código).
- Metadados e permissões no PostgreSQL (`attachment`): chave de objeto aleatória, nome sanitizado, MIME detectado pelo conteúdo, tamanho, SHA-256, uploader, vínculo com instância/tarefa/ação, status de antivírus.
- Download apenas por **URL assinada de 5 minutos** emitida após autorização (`GET /attachments/{id}/url`), servida pela API com `Content-Security-Policy: sandbox`.
- Antivírus: ClamAV (`clamd` INSTREAM) quando `BPM_CLAMAV_HOST` está definido; caso contrário o anexo fica `not_scanned` (aceitável apenas em dev/POC).

## Pendências

Política de retenção/descarte e classificação de anexos (DPO/jurídico); antivírus obrigatório em homologação/produção.
