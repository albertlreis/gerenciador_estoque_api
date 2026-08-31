#!/usr/bin/env sh
set -eu

mkdir -p \
  /var/www/html/storage/framework/cache \
  /var/www/html/storage/framework/sessions \
  /var/www/html/storage/framework/testing \
  /var/www/html/storage/framework/views \
  /var/www/html/storage/app/public \
  /var/www/html/storage/app/document-exports \
  /var/www/html/storage/logs \
  /var/www/html/bootstrap/cache

chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

run_artisan() {
  su -s /bin/sh -c "php artisan $*" www-data
}

assert_production_environment() {
  configured_env="${APP_ENV:-$(php -r '$values = @parse_ini_file("/var/www/html/.env", false, INI_SCANNER_RAW) ?: []; echo $values["APP_ENV"] ?? "";')}"
  configured_debug="${APP_DEBUG:-$(php -r '$values = @parse_ini_file("/var/www/html/.env", false, INI_SCANNER_RAW) ?: []; echo $values["APP_DEBUG"] ?? "";')}"
  [ "$configured_env" = "production" ] || {
    echo "Refusing to start: APP_ENV must be production." >&2
    exit 1
  }
  case "$(printf '%s' "$configured_debug" | tr '[:upper:]' '[:lower:]')" in
    false|0|off|no) ;;
    *) echo "Refusing to start: APP_DEBUG must be false." >&2; exit 1 ;;
  esac
}

assert_effective_configuration() {
  su -s /bin/sh -c 'php -r '\''require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if (! $app->environment("production") || (bool) config("app.debug")) { fwrite(STDERR, "Unsafe effective Laravel configuration.\n"); exit(1); }'\''' www-data
}

assert_storage_link() {
  link_path=/var/www/html/public/storage
  expected_target=/var/www/html/storage/app/public

  if [ -L "$link_path" ]; then
    actual_target="$(readlink -f "$link_path")"
    [ "$actual_target" = "$expected_target" ] || {
      echo "Refusing to start: public/storage points to $actual_target, expected $expected_target." >&2
      exit 1
    }
  elif [ -e "$link_path" ]; then
    echo "Refusing to start: public/storage exists and is not a symbolic link." >&2
    exit 1
  else
    run_artisan storage:link
  fi

  [ -L "$link_path" ] && [ "$(readlink -f "$link_path")" = "$expected_target" ] || {
    echo "Refusing to start: public/storage link is unavailable or invalid." >&2
    exit 1
  }
}

assert_production_environment
assert_storage_link

if [ "${SIERRA_RUNTIME_ROLE:-app}" = "worker" ]; then
  attempts=0
  while [ ! -f /var/www/html/bootstrap/cache/config.php ] && [ "$attempts" -lt 30 ]; do
    attempts=$((attempts + 1))
    sleep 2
  done
  test -f /var/www/html/bootstrap/cache/config.php || {
    echo "Refusing to start worker: application cache was not built." >&2
    exit 1
  }
else
  run_artisan config:cache
  run_artisan route:cache
  run_artisan view:cache
fi
assert_effective_configuration

exec "$@"
