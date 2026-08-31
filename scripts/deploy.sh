#!/usr/bin/env bash
# Deploy rápido para sierra-estoque (Laravel + Docker)
# Uso:
#   ./scripts/deploy.sh [--no-git] [--no-maintenance] [--no-build] [--migrate] [--composer]
#   ./scripts/deploy.sh --rollback

set -euo pipefail

APP_DIR="${APP_DIR:-/home/docker/acadsoft/sierra-estoque}"
HTML_DIR="${HTML_DIR:-$APP_DIR/html}"
COMPOSE_FILE="${COMPOSE_FILE:-$APP_DIR/docker-compose.yml}"
SERVICE="${SERVICE:-app}"
APP_NETWORK="${APP_NETWORK:-web}"

DO_GIT=true
DO_MAINTENANCE=true
DO_BUILD=true
DO_MIGRATE=false
DO_COMPOSER=false
ROLLBACK_ONLY=false

AUTO_ROLLBACK="${AUTO_ROLLBACK:-false}"
ROLLBACK_GIT_SHA="${ROLLBACK_GIT_SHA:-}"
ROLLBACK_IMAGE="${ROLLBACK_IMAGE:-sierra-estoque-app:rollback}"
ROLLBACK_STATE_FILE="${ROLLBACK_STATE_FILE:-$APP_DIR/.deploy-state/estoque-api.rollback}"
ROLLBACK_READY=false

for arg in "$@"; do
  case "$arg" in
    --no-git) DO_GIT=false ;;
    --no-maintenance) DO_MAINTENANCE=false ;;
    --no-build) DO_BUILD=false ;;
    --migrate) DO_MIGRATE=true ;;
    --composer) DO_COMPOSER=true ;;
    --rollback) ROLLBACK_ONLY=true ;;
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

normalize_tracked_permissions() {
  log "Normalizando permissões dos arquivos versionados…"
  (
    cd "$HTML_DIR"
    git ls-files -z | xargs -0 -r chmod a+r
  )

  local directory
  for directory in app bootstrap config database docker public resources routes scripts; do
    if [[ -d "$HTML_DIR/$directory" ]]; then
      find "$HTML_DIR/$directory" -type d -exec chmod a+rx {} +
    fi
  done

  chmod a+rx "$HTML_DIR/docker/prod/entrypoint.sh"
}

write_rollback_state() {
  mkdir -p "$(dirname "$ROLLBACK_STATE_FILE")"
  umask 077
  cat >"$ROLLBACK_STATE_FILE" <<EOF
ROLLBACK_GIT_SHA=$ROLLBACK_GIT_SHA
ROLLBACK_IMAGE=$ROLLBACK_IMAGE
EOF
}

load_rollback_state() {
  [[ -f "$ROLLBACK_STATE_FILE" ]] || {
    echo "Estado de rollback não encontrado: $ROLLBACK_STATE_FILE" >&2
    return 1
  }

  # O arquivo contém somente SHA e tag Docker gerados por este script.
  # shellcheck source=/dev/null
  source "$ROLLBACK_STATE_FILE"
}

artisan() {
  compose exec -T -u www-data "$SERVICE" bash -lc "php artisan $*"
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
  compose exec -T -u www-data "$SERVICE" bash -lc "
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

verify_log_write() {
  log "Validando escrita dos logs como www-data…"
  compose exec -T -u www-data "$SERVICE" php -r '
    $path = "/var/www/html/storage/logs/.deploy-write-smoke";
    if (file_put_contents($path, "ok\n", FILE_APPEND | LOCK_EX) === false) {
        fwrite(STDERR, "log write smoke test failed\n");
        exit(1);
    }
    unlink($path);
  '

  compose exec -T "$SERVICE" bash -lc '
    invalid="$(find /var/www/html/storage/logs -maxdepth 1 -type f ! -user www-data -print -quit)"
    if [[ -n "$invalid" ]]; then
      echo "Arquivo de log fora do proprietário www-data: $invalid" >&2
      exit 1
    fi
  '
}

verify_candidate_database() {
  log "Validando conexão do candidato com o banco antes da troca…"
  [[ -f "$HTML_DIR/.env" ]] || { echo ".env não encontrado: $HTML_DIR/.env"; return 1; }

  docker run --rm \
    --network "$APP_NETWORK" \
    --env-file "$HTML_DIR/.env" \
    --entrypoint php \
    sierra-estoque-app \
    artisan migrate:status >/dev/null
}

verify_database_connection() {
  log "Validando conexão real da aplicação com o banco…"
  compose exec -T -u www-data "$SERVICE" \
    php artisan migrate:status >/dev/null
}

verify_secure_runtime() {
  log "Validando ambiente efetivo de producao e debug desligado..."
  compose exec -T -u www-data "$SERVICE" php -r '
    require "/var/www/html/vendor/autoload.php";
    $app = require "/var/www/html/bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (! $app->environment("production") || (bool) config("app.debug")) {
        fwrite(STDERR, "unsafe effective Laravel configuration\n");
        exit(1);
    }
  '
}

verify_storage_link() {
  log "Validando link publico de storage..."
  artisan storage:link
  compose exec -T "$SERVICE" test -L /var/www/html/public/storage
  compose exec -T "$SERVICE" test -d /var/www/html/public/storage
}

verify_document_worker() {
  log "Validando worker dedicado de documentos..."
  compose config --services | grep -qx document-worker
  compose up -d --no-deps document-worker
  local worker_id
  worker_id="$(compose ps -q --all document-worker)"
  [[ -n "$worker_id" ]] || return 1
  local attempt
  for attempt in $(seq 1 "${WORKER_VERIFY_ATTEMPTS:-30}"); do
    if docker top "$worker_id" -eo pid,args 2>/dev/null | grep -F 'queue:work database --queue=documents' >/dev/null; then
      return 0
    fi
    sleep 1
  done
  docker logs --tail=50 "$worker_id" >&2 || true
  return 1
}

verify_expected_schema() {
  log "Validando schema esperado da observação interna…"
  compose exec -T -u www-data "$SERVICE" php -r '
    require "/var/www/html/vendor/autoload.php";
    $app = require "/var/www/html/bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    if (!Illuminate\Support\Facades\Schema::hasColumn("pedidos", "observacao_interna")) {
        fwrite(STDERR, "coluna pedidos.observacao_interna ausente\n");
        exit(1);
    }
  '
}

assert_not_in_maintenance() {
  log "Validando que a aplicacao nao esta em maintenance mode..."
  if ! compose exec -T "$SERVICE" bash -lc 'cd /var/www/html && test ! -f storage/framework/down'; then
    log "ERRO: Laravel ainda esta em maintenance mode (storage/framework/down). Rode php artisan up antes de finalizar."
    return 1
  fi
}

DID_SET_MAINTENANCE=false

rollback_application() {
  log "Iniciando rollback automático da aplicação…"
  load_rollback_state

  [[ "$ROLLBACK_GIT_SHA" =~ ^[0-9a-f]{40}$ ]] || {
    echo "SHA de rollback inválido: $ROLLBACK_GIT_SHA" >&2
    return 1
  }
  docker image inspect "$ROLLBACK_IMAGE" >/dev/null 2>&1 || {
    echo "Imagem de rollback não encontrada: $ROLLBACK_IMAGE" >&2
    return 1
  }

  if compose ps --services --status running | grep -qx "$SERVICE"; then
    artisan down --render="errors::503" || true
  fi
  DID_SET_MAINTENANCE=true

  git -C "$HTML_DIR" reset --hard "$ROLLBACK_GIT_SHA"
  normalize_tracked_permissions
  docker tag "$ROLLBACK_IMAGE" sierra-estoque-app:latest
  compose up -d --no-deps --force-recreate "$SERVICE"
  wait_for_php || { echo "PHP não respondeu após rollback" >&2; return 1; }
  compose up -d --no-deps --force-recreate nginx

  prepare_writable_paths
  clear_runtime_caches
  artisan config:cache
  artisan route:cache
  artisan view:cache
  verify_secure_runtime
  verify_storage_link
  verify_document_worker
  verify_file_cache
  verify_log_write
  verify_database_connection
  compose exec -T "$SERVICE" curl -fsS http://127.0.0.1/api/v1/health >/dev/null
  artisan up
  DID_SET_MAINTENANCE=false
  assert_not_in_maintenance
  log "Rollback automático concluído com sucesso. O schema do banco foi preservado."
}

handle_exit() {
  local status=$?
  trap - EXIT

  if (( status != 0 )) && $ROLLBACK_ONLY; then
    log "ERRO: rollback solicitado falhou; mantendo a aplicação em manutenção."
    if compose ps --services --status running | grep -qx "$SERVICE"; then
      artisan down --render="errors::503" || true
    fi
  elif (( status != 0 )) && [[ "$AUTO_ROLLBACK" == "true" ]] && $ROLLBACK_READY; then
    if ! rollback_application; then
      log "ERRO: rollback automático falhou; mantendo a aplicação em manutenção."
      if compose ps --services --status running | grep -qx "$SERVICE"; then
        artisan down --render="errors::503" || true
      fi
    fi
  elif (( status != 0 )) && $DO_MAINTENANCE && $DID_SET_MAINTENANCE; then
    log "Saindo do maintenance mode (cleanup)…"
    if compose ps --services --status running | grep -qx "$SERVICE"; then
      artisan up || true
    fi
  fi

  exit "$status"
}
trap handle_exit EXIT

[[ -d "$APP_DIR" ]] || { echo "APP_DIR não encontrado: $APP_DIR"; exit 1; }
[[ -f "$COMPOSE_FILE" ]] || { echo "docker-compose.yml não encontrado"; exit 1; }

log "Projeto: $APP_DIR | Serviço: $SERVICE"

if $ROLLBACK_ONLY; then
  ROLLBACK_READY=true
  rollback_application
  exit 0
fi

if [[ "$AUTO_ROLLBACK" == "true" ]]; then
  [[ "$ROLLBACK_GIT_SHA" =~ ^[0-9a-f]{40}$ ]] || {
    echo "AUTO_ROLLBACK exige ROLLBACK_GIT_SHA válido" >&2
    exit 1
  }
  docker image inspect sierra-estoque-app:latest >/dev/null 2>&1 || {
    echo "Imagem atual sierra-estoque-app:latest não encontrada para rollback" >&2
    exit 1
  }
  docker tag sierra-estoque-app:latest "$ROLLBACK_IMAGE"
  write_rollback_state
  ROLLBACK_READY=true
  log "Rollback preparado com SHA $ROLLBACK_GIT_SHA e imagem $ROLLBACK_IMAGE."
fi

if $DO_GIT; then
  log "Atualizando repositório Git…"
  cd "$HTML_DIR"
  git pull --ff-only
else
  log "Pulando git pull"
fi

normalize_tracked_permissions

if $DO_BUILD; then
  log "Buildando imagem…"
  docker build \
    --file "$HTML_DIR/Dockerfile.prod" \
    --tag sierra-estoque-app \
    "$HTML_DIR"
  verify_candidate_database
else
  log "Pulando build"
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

log "Subindo serviço…"
compose up -d --no-deps "$SERVICE"

log "Aguardando PHP…"
wait_for_php || { echo "PHP não respondeu"; exit 1; }

log "Recriando Nginx para resolver o upstream atual..."
compose up -d --no-deps --force-recreate nginx

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

verify_secure_runtime
verify_storage_link
verify_file_cache
verify_log_write
verify_database_connection

if $DO_MIGRATE; then
  log "Executando migrations…"

  if ! artisan migrate --force; then
    log "❌ ERRO durante as migrations! Deploy interrompido!"
    exit 1
  fi

  log "Migrations executadas com sucesso!"
  verify_expected_schema
else
  log "Pulando migrations (flag --no-migrate)…"
fi

log "Reiniciando workers…"
artisan queue:restart || true
verify_document_worker

if $DO_MAINTENANCE && $DID_SET_MAINTENANCE; then
  log "Saindo do maintenance mode…"
  artisan up
  DID_SET_MAINTENANCE=false
fi

log "Últimas 150 linhas dos logs…"
assert_not_in_maintenance

CONTAINER_ID="$(compose ps -q "$SERVICE")"
docker logs --tail=150 "$CONTAINER_ID" || true

log "Deploy concluído com sucesso ✅"
