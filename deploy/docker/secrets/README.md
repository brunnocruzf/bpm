# Segredos locais (Docker secrets em arquivo)

Este diretório é ignorado pelo Git — exceto este README e `generate.sh`.

| Arquivo | Uso | Como criar |
|---|---|---|
| `bpm_secrets_key` | Chave AES-256 (base64, 32 bytes) do cofre de segredos das integrações (`BPM_SECRETS_KEY_FILE`) | `sh generate.sh` |
| `carbone_key.pub` | Chave pública da autenticação do Carbone (somente perfil `carbone`, após aprovação da licença) | `docker run --rm carbone/carbone-ee:slim-5.15.4 generate-keys` |
| `<nome>` livre | Credenciais referenciadas como `file:<nome>` nas Configurações (ex.: `smtp_password`, `entra_sync_certificate.pem`) | copie o arquivo e monte em `/run/secrets` |

Em produção use o secret manager corporativo; o `.env` serve apenas para desenvolvimento e nunca é versionado.
