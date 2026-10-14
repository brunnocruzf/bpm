#!/bin/sh
# Gera segredos LOCAIS para o Compose de desenvolvimento (Docker secrets em arquivo).
# Nunca versione os arquivos gerados (este diretório é ignorado pelo Git, exceto este script e o README).
# Produção: use o secret manager corporativo (Vault/Key Vault/Swarm secrets) montado em /run/secrets.
set -e
cd "$(dirname "$0")"
if [ ! -s bpm_secrets_key ]; then
  # 32 bytes aleatórios em base64 = chave AES-256 do cofre de segredos das integrações
  head -c 32 /dev/urandom | base64 > bpm_secrets_key
  chmod 600 bpm_secrets_key
  echo "bpm_secrets_key criado."
else
  echo "bpm_secrets_key já existe (rotação: ver docs/runbook.md)."
fi
