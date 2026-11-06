#!/bin/sh
# Entrada alternativa para api / queue-worker / scheduler SEM auto-migrate.
set -e

# Aguarda o PostgreSQL
i=0
until pg_isready -h "${DB_HOST:-postgres}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-bpm_app}" >/dev/null 2>&1; do
  i=$((i+1))
  if [ "$i" -gt 60 ]; then echo "PostgreSQL indisponível" >&2; exit 1; fi
  sleep 2
done

php artisan config:cache >/dev/null
php artisan route:cache >/dev/null

exec "$@"
