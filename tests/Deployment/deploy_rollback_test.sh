#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TEST_DIR="$(mktemp -d)"
trap 'rm -rf "$TEST_DIR"' EXIT

APP_DIR="$TEST_DIR/app"
HTML_DIR="$APP_DIR/html"
FAKE_BIN="$TEST_DIR/bin"
FAKE_LOG="$TEST_DIR/commands.log"
ROLLBACK_SHA="0123456789abcdef0123456789abcdef01234567"

mkdir -p "$HTML_DIR" "$APP_DIR/.deploy-state" "$FAKE_BIN"
: >"$APP_DIR/docker-compose.yml"
cat >"$APP_DIR/.deploy-state/estoque-api.rollback" <<EOF
ROLLBACK_GIT_SHA=$ROLLBACK_SHA
ROLLBACK_IMAGE=sierra-estoque-app:rollback
EOF

cat >"$FAKE_BIN/docker" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf 'docker %s\n' "$*" >>"$FAKE_LOG"
if [[ "${FAKE_ROLLBACK_FAIL:-false}" == "true" && "$1 $2" == "tag sierra-estoque-app:rollback" ]]; then
  exit 1
fi
exit 0
EOF

cat >"$FAKE_BIN/docker-compose" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf 'docker-compose %s\n' "$*" >>"$FAKE_LOG"
if [[ "$*" == *"ps --services --status running"* ]]; then
  echo app
elif [[ "$*" == *"ps -q app"* ]]; then
  echo fake-container
elif [[ "${FAKE_MIGRATION_FAIL:-false}" == "true" && "$*" == *"php artisan migrate --force"* ]]; then
  exit 1
fi
exit 0
EOF

cat >"$FAKE_BIN/git" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf 'git %s\n' "$*" >>"$FAKE_LOG"
exit 0
EOF

chmod +x "$FAKE_BIN/docker" "$FAKE_BIN/docker-compose" "$FAKE_BIN/git"

run_rollback() {
  APP_DIR="$APP_DIR" \
  HTML_DIR="$HTML_DIR" \
  COMPOSE_FILE="$APP_DIR/docker-compose.yml" \
  ROLLBACK_STATE_FILE="$APP_DIR/.deploy-state/estoque-api.rollback" \
  FAKE_LOG="$FAKE_LOG" \
  PATH="$FAKE_BIN:$PATH" \
    bash "$ROOT_DIR/scripts/deploy.sh" --rollback
}

run_deploy_with_migrations() {
  APP_DIR="$APP_DIR" \
  HTML_DIR="$HTML_DIR" \
  COMPOSE_FILE="$APP_DIR/docker-compose.yml" \
  ROLLBACK_STATE_FILE="$APP_DIR/.deploy-state/estoque-api.rollback" \
  AUTO_ROLLBACK=true \
  ROLLBACK_GIT_SHA="$ROLLBACK_SHA" \
  FAKE_LOG="$FAKE_LOG" \
  PATH="$FAKE_BIN:$PATH" \
    bash "$ROOT_DIR/scripts/deploy.sh" --no-git --no-build --migrate
}

run_rollback
grep -Fq "git -C $HTML_DIR reset --hard $ROLLBACK_SHA" "$FAKE_LOG"
grep -Fq 'docker tag sierra-estoque-app:rollback sierra-estoque-app:latest' "$FAKE_LOG"
grep -Fq 'up -d --no-deps --force-recreate app' "$FAKE_LOG"
grep -Fq 'php artisan up' "$FAKE_LOG"

: >"$FAKE_LOG"
if FAKE_MIGRATION_FAIL=true run_deploy_with_migrations; then
  echo "Deploy deveria falhar quando a migration falha." >&2
  exit 1
fi
grep -Fq 'php artisan migrate --force' "$FAKE_LOG"
grep -Fq "git -C $HTML_DIR reset --hard $ROLLBACK_SHA" "$FAKE_LOG"
grep -Fq 'docker tag sierra-estoque-app:rollback sierra-estoque-app:latest' "$FAKE_LOG"
grep -Fq 'php artisan up' "$FAKE_LOG"

: >"$FAKE_LOG"
if FAKE_ROLLBACK_FAIL=true run_rollback; then
  echo "Rollback deveria falhar quando a imagem anterior não pode ser restaurada." >&2
  exit 1
fi
grep -Fq 'php artisan down --render=errors::503' "$FAKE_LOG"

echo "Deploy rollback tests passed."
