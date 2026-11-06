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

# Idempotente (só cria o bucket se faltar): roda sempre, independente de RUN_MIGRATIONS, para nunca deixar
# o storage de anexos/fotos sem bucket (upload/leitura viram 500 silenciosamente nesse caso).
php artisan bpm:storage-init || echo "Aviso: storage de anexos não inicializado (verifique BPM_S3_*)." >&2

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  php artisan migrate --force
  if [ "${SEED_ON_START:-false}" = "true" ]; then
    php artisan db:seed --force
  fi
fi

exec "$@"
