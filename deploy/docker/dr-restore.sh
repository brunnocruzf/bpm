#!/bin/sh
# Recuperação de desastre do BPM (ADR-0021): sobe a aplicação em um servidor novo a partir de um backup de nível
# Aplicação inteira guardado no Azure. Precisa só de Docker, da chave da conta do Azure e da chave de recuperação.
# Este arquivo não tem segredos: uma cópia dele fica na pasta de backups do Azure, junto com LEIA-ME-RECUPERACAO.md.
#
#   export AZURE_ACCOUNT_KEY='<chave da conta>'
#   ./dr-restore.sh --org tmsa --account tmsabpmanexos --container anexos --path bpm/backups \
#                   --key-file ./chave-recuperacao.txt --dir /opt/bpm
#
#   --backup <id>      um backup específico (padrão: o nível Aplicação inteira mais recente)
#   --project <nome>   nome do projeto Compose (padrão: bpm-<org>)
#   --endpoint <url>   endpoint do Blob (padrão: https://<conta>.blob.core.windows.net)
#   --no-start         só baixa, confere e prepara os arquivos
set -eu

HELPER_IMAGE=alpine:3.24
API_VERSION=2023-11-03

# ---------------------------------------------------------------- dentro do contêiner auxiliar

blob_url() { printf '%s/%s/%s' "$ENDPOINT" "$CONTAINER" "$1"; }

# Assinatura Shared Key de um GET: https://learn.microsoft.com/rest/api/storageservices/authorize-with-shared-key
auth_header() {
  url_path=$1
  query_lines=$2
  stamp=$(LC_ALL=C TZ=GMT date '+%a, %d %b %Y %H:%M:%S GMT')
  hex_key=$(printf '%s' "$AZURE_ACCOUNT_KEY" | base64 -d | od -An -tx1 | tr -d ' \n')
  to_sign=$(printf 'GET\n\n\n\n\n\n\n\n\n\n\n\nx-ms-date:%s\nx-ms-version:%s\n/%s%s%b' "$stamp" "$API_VERSION" "$ACCOUNT" "$url_path" "$query_lines")
  signature=$(printf '%s' "$to_sign" | openssl dgst -sha256 -mac HMAC -macopt "hexkey:$hex_key" -binary | base64 | tr -d '\n')
  printf 'x-ms-date: %s\nx-ms-version: %s\nAuthorization: SharedKey %s:%s\n' "$stamp" "$API_VERSION" "$ACCOUNT" "$signature"
}

az_get() {
  url=$1
  query=$2
  query_lines=$3
  shift 3
  url_path=$(printf '%s' "$url" | sed -E 's#^https?://[^/]+##')
  auth_header "$url_path" "$query_lines" > /tmp/headers
  curl -fsS --retry 3 -H @/tmp/headers "$url${query:+?$query}" "$@"
}

list_blobs() {
  prefix=$1
  marker=
  while :; do
    if [ -n "$marker" ]; then
      xml=$(az_get "$ENDPOINT/$CONTAINER" "comp=list&marker=$marker&prefix=$prefix&restype=container" "\ncomp:list\nmarker:$marker\nprefix:$prefix\nrestype:container")
    else
      xml=$(az_get "$ENDPOINT/$CONTAINER" "comp=list&prefix=$prefix&restype=container" "\ncomp:list\nprefix:$prefix\nrestype:container")
    fi
    printf '%s' "$xml" | grep -o '<Name>[^<]*</Name>' | sed -e 's/<Name>//' -e 's#</Name>##'
    marker=$(printf '%s' "$xml" | sed -n 's#.*<NextMarker>\([^<]*\)</NextMarker>.*#\1#p')
    [ -n "$marker" ] || break
  done
}

check_sha() {
  actual=$(sha256sum "$1" | cut -d' ' -f1)
  [ "$actual" = "$2" ] || { echo "Arquivo corrompido: $1" >&2; exit 1; }
}

fetch() {
  apk add --no-cache -q curl openssl jq age zstd tar coreutils >/dev/null
  root=$(printf '%s/%s' "$BLOB_PATH" "$ORG")
  work=/work/backup
  mkdir -p "$work" /work/config /work/images

  if [ -z "$BACKUP" ]; then
    echo 'Procurando o backup de Aplicação inteira mais recente...'
    for name in $(list_blobs "$root/" | grep '/manifest.json$' | sort -r); do
      az_get "$(blob_url "$name")" '' '' -o /tmp/manifest.json
      if [ "$(jq -r .scope /tmp/manifest.json)" = full ]; then
        BACKUP=$(jq -r .id /tmp/manifest.json)
        break
      fi
    done
    [ -n "$BACKUP" ] || { echo 'Nenhum backup de Aplicação inteira encontrado nesta pasta.' >&2; exit 1; }
  fi
  dir="$root/$(printf '%s' "$BACKUP" | cut -c1-4)/$(printf '%s' "$BACKUP" | cut -c5-6)/$BACKUP"
  echo "Backup: $BACKUP"
  az_get "$(blob_url "$dir/manifest.json")" '' '' -o "$work/manifest.json"
  [ "$(jq -r .scope "$work/manifest.json")" = full ] || { echo 'Este backup não é de Aplicação inteira: faltam configuração e imagens.' >&2; exit 1; }

  jq -r '.parts[] | [.name, .sha256] | @tsv' "$work/manifest.json" | while IFS="$(printf '\t')" read -r part sha; do
    echo "Baixando $part"
    az_get "$(blob_url "$dir/$part")" '' '' -o "$work/$part"
    check_sha "$work/$part" "$sha"
  done

  echo 'Abrindo a configuração'
  age -d -i /key "$work/config.tar.zst.age" | zstd -q -d | tar -xf - -C /work/config

  : > /work/images.tsv
  jq -r '.images | unique_by(.id)[] | [.id, .file, .sha256] | @tsv' "$work/manifest.json" | while IFS="$(printf '\t')" read -r id file sha; do
    echo "Baixando imagem $(printf '%s' "$id" | cut -c8-19)"
    target=/work/images/$(basename "$file")
    az_get "$(blob_url "$BLOB_PATH/$file")" '' '' -o "$target"
    check_sha "$target" "$sha"
    age -d -i /key "$target" | zstd -q -d > "${target%.zst.age}"
    rm -f "$target"
    printf '%s\t%s\n' "$id" "$(basename "${target%.zst.age}")" >> /work/images.tsv
  done
  jq -r '.images[] | [.service, .id, (.ref | split("@")[0])] | @tsv' "$work/manifest.json" > /work/services.tsv
}

if [ "${1:-}" = __fetch ]; then
  fetch
  exit 0
fi

# ---------------------------------------------------------------- no servidor

ORG=
ACCOUNT=
CONTAINER=
BLOB_PATH=
KEY_FILE=
DIR=
BACKUP=
PROJECT=
ENDPOINT=
START=1
while [ $# -gt 0 ]; do
  case "$1" in
    --org) ORG=$2; shift 2 ;;
    --account) ACCOUNT=$2; shift 2 ;;
    --container) CONTAINER=$2; shift 2 ;;
    --path) BLOB_PATH=${2%/}; shift 2 ;;
    --key-file) KEY_FILE=$2; shift 2 ;;
    --dir) DIR=$2; shift 2 ;;
    --backup) BACKUP=$2; shift 2 ;;
    --project) PROJECT=$2; shift 2 ;;
    --endpoint) ENDPOINT=${2%/}; shift 2 ;;
    --no-start) START=0; shift ;;
    *) sed -n '2,17p' "$0"; exit 1 ;;
  esac
done
if [ -z "$ORG" ] || [ -z "$ACCOUNT" ] || [ -z "$CONTAINER" ] || [ -z "$BLOB_PATH" ] || [ -z "$KEY_FILE" ] || [ -z "$DIR" ] || [ -z "${AZURE_ACCOUNT_KEY:-}" ]; then
  sed -n '2,17p' "$0"
  exit 1
fi
PROJECT=${PROJECT:-bpm-$ORG}
ENDPOINT=${ENDPOINT:-https://$ACCOUNT.blob.core.windows.net}

# O Docker não aceita caminho relativo em -v. pwd -W só existe no Git Bash, usado nos testes em Windows.
abspath() {
  parent=$(cd "$(dirname "$1")" && { pwd -W 2>/dev/null || pwd; })
  printf '%s/%s' "$parent" "$(basename "$1")"
}
[ -r "$KEY_FILE" ] || { echo "Não consigo ler a chave de recuperação em $KEY_FILE." >&2; exit 1; }
KEY_FILE=$(abspath "$KEY_FILE")
SELF=$(abspath "$0")
mkdir -p "$DIR"
DIR=$(cd "$DIR" && { pwd -W 2>/dev/null || pwd; })
WORK=$DIR/dr-work
DEPLOY=$DIR/deploy
mkdir -p "$WORK" "$DEPLOY"

echo '== Baixando e conferindo o backup'
docker run --rm --add-host host.docker.internal:host-gateway \
  -e ORG="$ORG" -e ACCOUNT="$ACCOUNT" -e CONTAINER="$CONTAINER" -e BLOB_PATH="$BLOB_PATH" -e BACKUP="$BACKUP" -e ENDPOINT="$ENDPOINT" -e AZURE_ACCOUNT_KEY \
  -e API_VERSION="$API_VERSION" -v "$WORK:/work" -v "$KEY_FILE:/key:ro" -v "$SELF:/dr-restore.sh:ro" "$HELPER_IMAGE" sh /dr-restore.sh __fetch

echo '== Recriando a configuração'
cp -R "$WORK/config/deploy/." "$DEPLOY/"
env_name=.env
[ "$ORG" = tmsa ] || env_name=.env.$ORG
mkdir -p "$DEPLOY/secrets"
cp "$WORK/config/bpm_secrets_key" "$DEPLOY/secrets/bpm_secrets_key"
chmod 600 "$DEPLOY/secrets/bpm_secrets_key"
# caminhos absolutos do servidor antigo não existem aqui
grep -vE '^(BPM_SECRETS_KEY_PATH|BPM_ENV_FILE|BPM_BACKUP_PATH|COMPOSE_FILE|COMPOSE_PATH_SEPARATOR)=' "$WORK/config/env" > "$DEPLOY/$env_name"
{
  echo 'BPM_SECRETS_KEY_PATH=./secrets/bpm_secrets_key'
  echo "BPM_ENV_FILE=./$env_name"
  echo 'COMPOSE_PATH_SEPARATOR=:'
  echo 'COMPOSE_FILE=docker-compose.yml:docker-compose.dr.yml'
} >> "$DEPLOY/$env_name"
chmod 600 "$DEPLOY/$env_name"

echo '== Carregando as imagens'
while IFS="$(printf '\t')" read -r id tar; do
  docker load -q -i "$WORK/images/$tar" >/dev/null
done < "$WORK/images.tsv"

# as imagens carregadas não trazem o digest do registro: a stack passa a usá-las pela tag, sem baixar nada
{
  echo 'services:'
  while IFS="$(printf '\t')" read -r service id tag; do
    docker tag "$id" "$tag"
    printf '  %s:\n    image: %s\n    pull_policy: never\n' "$service" "$tag"
  done < "$WORK/services.tsv"
} > "$DEPLOY/docker-compose.dr.yml"

if [ "$START" = 0 ]; then
  echo "Pronto. Arquivos em $DEPLOY."
  exit 0
fi

cd "$DEPLOY"
compose() { docker compose -p "$PROJECT" --env-file "$env_name" -f docker-compose.yml -f docker-compose.dr.yml "$@"; }

echo '== Subindo banco e armazenamento'
compose up -d --no-build --wait postgres storage

echo '== Importando os dados'
compose run --rm --no-deps -T -v "$WORK/backup:/dr:ro" -v "$KEY_FILE:/dr-key:ro" backup-agent php artisan bpm:backup-import --dir=/dr --key-file=/dr-key

echo '== Subindo a aplicação'
compose up -d --no-build

echo "Pronto. A aplicação está em modo seguro: entre como administrador, confira os dados e libere em Configurações, Backup e recuperação."
