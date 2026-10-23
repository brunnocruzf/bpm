#!/bin/sh
# Gera segredos LOCAIS para o Compose de desenvolvimento (Docker secrets em arquivo).
# Nunca versione os arquivos gerados (este diretório é ignorado pelo Git, exceto este script e o README).
# Produção: use o secret manager corporativo (Vault/Key Vault/Swarm secrets) montado em /run/secrets.
#   sh generate.sh        → ./bpm_secrets_key (instância padrão, TMSA)
#   sh generate.sh ims    → ./ims/bpm_secrets_key (outra organização; BPM_SECRETS_KEY_PATH no .env.ims)
set -e
cd "$(dirname "$0")"
if [ -n "$1" ]; then
  mkdir -p "$1"
  cd "$1"
fi
if [ ! -s bpm_secrets_key ]; then
  # 32 bytes aleatórios em base64 = chave AES-256 do cofre de segredos das integrações
  head -c 32 /dev/urandom | base64 > bpm_secrets_key
  chmod 600 bpm_secrets_key
  echo "bpm_secrets_key criado${1:+ ($1)}."
else
  echo "bpm_secrets_key já existe${1:+ ($1)} (rotação: ver docs/runbook.md)."
fi
