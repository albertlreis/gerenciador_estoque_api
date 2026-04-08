#!/usr/bin/env bash
# Deploy rápido para sierra-estoque (Laravel + Docker)
# Uso:
#   ./scripts/deploy.sh [--no-git] [--no-maintenance] [--no-build] [--migrate] [--composer]

set -euo pipefail

APP_DIR="/home/docker/acadsoft/sierra-estoque"
HTML_DIR="$APP_DIR/html"
COMPOSE_FILE="$APP_DIR/docker-compose.yml"
SERVICE="app"

DO_GIT=true
DO_MAINTENANCE=true
DO_BUILD=true
DO_MIGRATE=false
DO_COMPOSER=false

for arg in "$@"; do
  case "$arg" in
    --no-git) DO_GIT=false ;;
    --no-maintenance) DO_MAINTENANCE=false ;;
    --no-build) DO_BUILD=false ;;
    --migrate) DO_MIGRATE=true ;;
    --composer) DO_COMPOSER=true ;;
    *) echo "Argumento desconhecido: $arg"; exit 2 ;;
  esac
done

compose() {
  if command -v docker-compose >/dev/null 2>&1; then
    docker-compose -f "$COMPOSE_FILE" "$@"
  else
    docker compose -f "$COMPOSE_FILE" "$@"
  fi
}

log() { printf "\n[%s] %s\n" "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }

artisan() {
  compose exec -T "$SERVICE" bash -lc "php artisan $*"
}

wait_for_php() {
  local tries=30
  local i=1
  while (( i <= tries )); do
    if compose exec -T "$SERVICE" bash -lc 'php -v >/dev/null 2>&1'; then
      return 0
    fi
    sleep 1
    ((i++))
  done
  return 1
}

prepare_writable_paths() {
  log "Garantindo diretórios graváveis do Laravel…"
  compose exec -T "$SERVICE" bash -lc '
    set -e
    mkdir -p \
      /var/www/html/storage/framework/cache/data \
      /var/www/html/storage/framework/sessions \
      /var/www/html/storage/framework/views \
      /var/www/html/storage/logs \
      /var/www/html/bootstrap/cache

    chown -R www-data:www-data \
      /var/www/html/storage \
      /var/www/html/bootstrap/cache

    chmod -R ug+rwX \
      /var/www/html/storage \
      /var/www/html/bootstrap/cache
  '
}

clear_runtime_caches() {
  log "Limpando config cache…"
  artisan config:clear

  log "Limpando application cache…"
  artisan cache:clear

  log "Limpando route cache…"
  artisan route:clear

  log "Limpando view cache…"
  artisan view:clear

  log "Limpando event cache…"
  artisan event:clear || true

  log "Limpando arquivos compilados…"
  artisan clear-compiled || true
}

verify_file_cache() {
  log "Validando escrita/leitura do cache de arquivo…"
  compose exec -T "$SERVICE" bash -lc "
    cat >/tmp/cache-smoke.php <<'PHP'
<?php
require '/var/www/html/vendor/autoload.php';
\$app = require '/var/www/html/bootstrap/app.php';
\$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
\$kernel->bootstrap();
cache()->put('deploy-cache-smoke', 'ok', 300);
if (cache()->get('deploy-cache-smoke') !== 'ok') {
    fwrite(STDERR, 'cache smoke test failed' . PHP_EOL);
    exit(1);
}
PHP
    php /tmp/cache-smoke.php
    rm -f /tmp/cache-smoke.php
  "
}

[[ -d "$APP_DIR" ]] || { echo "APP_DIR não encontrado: $APP_DIR"; exit 1; }
[[ -f "$COMPOSE_FILE" ]] || { echo "docker-compose.yml não encontrado"; exit 1; }

log "Projeto: $APP_DIR | Serviço: $SERVICE"

DID_SET_MAINTENANCE=false
cleanup() {
  if $DO_MAINTENANCE && $DID_SET_MAINTENANCE; then
    log "Saindo do maintenance mode (cleanup)…"
    if compose ps --services --status running | grep -qx "$SERVICE"; then
      artisan up || true
    fi
  fi
}
trap cleanup EXIT

if $DO_GIT; then
  log "Atualizando repositório Git…"
  cd "$HTML_DIR"
  git pull --ff-only
else
  log "Pulando git pull"
fi

if $DO_MAINTENANCE; then
  log "Entrando em maintenance mode…"
  if ! compose ps --services --status running | grep -qx "$SERVICE"; then
    compose up -d "$SERVICE"
    wait_for_php || { echo "PHP não respondeu"; exit 1; }
  fi
  artisan down --render="errors::503" || true
  DID_SET_MAINTENANCE=true
else
  log "Pulando maintenance mode…"
fi

log "Fazendo pull da imagem…"
compose pull "$SERVICE" || true

if $DO_BUILD; then
  log "Buildando imagem…"
  compose build "$SERVICE"
else
  log "Pulando build"
fi

log "Subindo serviço…"
compose up -d --no-deps "$SERVICE"

log "Aguardando PHP…"
wait_for_php || { echo "PHP não respondeu"; exit 1; }

prepare_writable_paths

if $DO_COMPOSER; then
  log "Rodando composer install…"
  compose exec -T "$SERVICE" bash -lc 'composer install --no-dev --prefer-dist --optimize-autoloader'
fi

clear_runtime_caches

prepare_writable_paths

log "Gerando config cache…"
artisan config:cache

log "Gerando route cache…"
artisan route:cache

log "Gerando view cache…"
artisan view:cache

verify_file_cache

if $DO_MIGRATE; then
  log "Executando migrations…"

  if ! artisan migrate --force; then
    log "❌ ERRO durante as migrations! Deploy interrompido!"
    exit 1
  fi

  log "Migrations executadas com sucesso!"
else
  log "Pulando migrations (flag --no-migrate)…"
fi

log "Reiniciando workers…"
artisan queue:restart || true

if $DO_MAINTENANCE && $DID_SET_MAINTENANCE; then
  log "Saindo do maintenance mode…"
  artisan up
fi

log "Últimas 150 linhas dos logs…"
CONTAINER_ID="$(compose ps -q "$SERVICE")"
docker logs --tail=150 "$CONTAINER_ID" || true

log "Deploy concluído com sucesso ✅"
