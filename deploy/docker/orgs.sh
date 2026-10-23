#!/bin/sh
# Opera as instâncias por organização (ADR-0019): cada organização é uma stack Compose isolada
# (banco, engine, anexos, cofre e volumes próprios) com as mesmas imagens.
#   TMSA → .env        (projeto bpm-tmsa)
#   IMS  → .env.ims    (projeto bpm-ims; modelo em .env.ims.example)
#
#   ./orgs.sh list                       organizações configuradas
#   ./orgs.sh up [org]                   sobe/atualiza (todas, ou só uma)
#   ./orgs.sh ps|pull|build|down [org]
#   ./orgs.sh logs <org> [serviço]
#   ./orgs.sh backup [org]               pg_dump dos dois bancos em ./backups/<org>/<data>/
#   ./orgs.sh exec <org> <cmd...>        ex.: ./orgs.sh exec ims api php artisan bpm:superadmin set-password
set -e
cd "$(dirname "$0")"

orgs() {
  if [ -f .env ]; then echo tmsa; fi
  for f in .env.*; do
    case "$f" in *.example | '.env.*') continue ;; esac
    echo "${f#.env.}"
  done
}

env_file() {
  if [ "$1" = tmsa ]; then echo .env; else echo ".env.$1"; fi
}

compose() {
  org=$1
  shift
  file=$(env_file "$org")
  if [ ! -f "$file" ]; then
    echo "Organização '$org' sem $file (copie de .env.$org.example ou .env.example)." >&2
    exit 1
  fi
  docker compose -p "bpm-$org" --env-file "$file" "$@"
}

backup() {
  dir="backups/$1/$(date +%Y%m%d-%H%M%S)"
  mkdir -p "$dir"
  compose "$1" exec -T postgres pg_dump -U postgres -Fc bpm_app > "$dir/bpm_app.dump"
  compose "$1" exec -T postgres pg_dump -U postgres -Fc flowable > "$dir/flowable.dump"
  echo "$1: $dir (inclua também o volume storagedata e, separada, a chave do cofre)"
}

each() {
  action=$1
  target=$2
  for org in ${target:-$(orgs)}; do
    echo "== $org"
    case "$action" in
      up) compose "$org" up -d ;;
      ps | pull | build | down) compose "$org" "$action" ;;
      backup) backup "$org" ;;
    esac
  done
}

cmd=${1:-}
[ $# -gt 0 ] && shift
case "$cmd" in
  list) orgs ;;
  up | ps | pull | build | down | backup) each "$cmd" "${1:-}" ;;
  logs)
    org=$1
    shift
    compose "$org" logs --tail=200 "$@"
    ;;
  exec)
    org=$1
    shift
    compose "$org" exec "$@"
    ;;
  *)
    sed -n '2,13p' "$0"
    exit 1
    ;;
esac
