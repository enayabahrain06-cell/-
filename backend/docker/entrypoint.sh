#!/bin/sh
# Container start: cache config/routes/views; the "app" role also runs migrations.
set -e
cd /var/www/html
if [ ! -L public/storage ]; then php artisan storage:link >/dev/null 2>&1 || true; fi
if [ "${DB_CONNECTION:-}" = "sqlite" ] && [ -n "${DB_DATABASE:-}" ] && [ ! -f "$DB_DATABASE" ]; then touch "$DB_DATABASE"; fi
php artisan config:cache
php artisan route:cache
php artisan view:cache
if [ "${CONTAINER_ROLE:-app}" = "app" ] && [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  php artisan migrate --force
  php artisan db:seed --force   # reference data only (roles, settings, templates, surahs, badges); idempotent
fi
exec "$@"
