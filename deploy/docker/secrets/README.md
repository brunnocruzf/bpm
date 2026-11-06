# Segredos locais (Docker secrets em arquivo)

Este diretório é ignorado pelo Git — exceto este README e `generate.sh`.

| Arquivo | Uso | Como criar |
|---|---|---|
| `bpm_secrets_key` | Chave AES-256 (base64, 32 bytes) do cofre de segredos das integrações (`BPM_SECRETS_KEY_FILE`) | `sh generate.sh` |
| `carbone_key.pub` | Chave pública da autenticação do Carbone (somente perfil `carbone`) | `docker run --rm carbone/carbone-ee:slim-5.15.4 generate-keys` |
| `<nome>` livre | Credenciais referenciadas como `file:<nome>` nas Configurações (ex.: `smtp_password`, `entra_sync_certificate.pem`) | copie o arquivo e monte em `/run/secrets` |

Em produção use o secret manager corporativo; o `.env` serve apenas para desenvolvimento e nunca é versionado.

> **Permissão do arquivo**: o Compose (fora do Swarm) faz *bind-mount* destes arquivos preservando o
> dono/modo do host. Garanta que `bpm_secrets_key` (e qualquer outro montado em `/run/secrets`) fique
> legível pelo usuário do container (`www-data`, uid 82) — `chmod 644` é suficiente (o `generate.sh`
> já faz isso). Um arquivo `600` criado por um usuário diferente de `www-data` fica ilegível dentro do
> container: o cofre volta silenciosamente para a chave derivada da `APP_KEY`, e qualquer segredo já
> salvo com a chave dedicada passa a falhar com "rotação incompleta" até ser reconfigurado manualmente.
