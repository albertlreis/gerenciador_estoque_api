#!/usr/bin/env sh
set -eu

mkdir -p \
  /var/www/html/storage/framework/cache \
  /var/www/html/storage/framework/sessions \
  /var/www/html/storage/framework/testing \
  /var/www/html/storage/framework/views \
  /var/www/html/storage/logs \
  /var/www/html/bootstrap/cache

chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

run_artisan() {
  su -s /bin/sh -c "php artisan $*" www-data
}

run_artisan storage:link >/dev/null 2>&1 || true

if [ "${APP_ENV:-production}" = "production" ]; then
  run_artisan config:cache >/dev/null 2>&1 || true
  run_artisan route:cache >/dev/null 2>&1 || true
  run_artisan view:cache >/dev/null 2>&1 || true
fi

exec "$@"
