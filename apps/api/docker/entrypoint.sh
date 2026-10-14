#!/bin/sh
# Entrada comum para api / queue-worker / scheduler.
set -e

# Aguarda o PostgreSQL (healthcheck do compose cobre o caso normal; isto cobre reinícios).
i=0
until pg_isready -h "${DB_HOST:-postgres}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-bpm_app}" >/dev/null 2>&1; do
  i=$((i+1))
  if [ "$i" -gt 60 ]; then echo "PostgreSQL indisponível" >&2; exit 1; fi
  sleep 2
done

php artisan config:cache >/dev/null
php artisan route:cache >/dev/null

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  php artisan migrate --force
  php artisan bpm:storage-init || echo "Aviso: storage de anexos não inicializado (verifique BPM_S3_*)." >&2
  if [ "${SEED_ON_START:-false}" = "true" ]; then
    php artisan db:seed --force
  fi
fi

exec "$@"
