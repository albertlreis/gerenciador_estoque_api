#!/usr/bin/env bash

set -Eeuo pipefail

CONFIG_FILE="${SIERRA_BACKUP_CONFIG:-/etc/sierra-backup.env}"
if [[ -f "$CONFIG_FILE" ]]; then
  # shellcheck source=/dev/null
  source "$CONFIG_FILE"
fi

BACKUP_ROOT="${BACKUP_ROOT:-/home/docker/acadsoft/backups/sierra}"
RCLONE_REMOTE="${RCLONE_REMOTE:-sierra_backup_crypt:Sierra/production}"
MYSQL_CONTAINER="${MYSQL_CONTAINER:-mysql_server}"
DB_NAME="${DB_NAME:-sierra}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"
ESTOQUE_APP_DIR="${ESTOQUE_APP_DIR:-/home/docker/acadsoft/sierra-estoque/html}"
AUTH_APP_DIR="${AUTH_APP_DIR:-/home/docker/acadsoft/sierra-auth/html}"
BACKUP_TIMEZONE="${BACKUP_TIMEZONE:-America/Sao_Paulo}"

DO_UPLOAD=true
case "${1:-}" in
  --no-upload)
    DO_UPLOAD=false
    ;;
  "")
    ;;
  *)
    echo "Uso: $0 [--no-upload]" >&2
    exit 2
    ;;
esac

umask 077

RUN_DATE="$(TZ="$BACKUP_TIMEZONE" date '+%F')"
TIMESTAMP="$(TZ="$BACKUP_TIMEZONE" date '+%Y%m%d-%H%M%S')"
DAY_DIR="$BACKUP_ROOT/$RUN_DATE"
TMP_DIR="$DAY_DIR/.tmp-$TIMESTAMP"
MANIFEST="$DAY_DIR/manifest-$TIMESTAMP.txt"

DB_ARCHIVE="$DAY_DIR/db-$DB_NAME-$TIMESTAMP.sql.gz"
ESTOQUE_ARCHIVE="$DAY_DIR/files-estoque-storage-$TIMESTAMP.tar.gz"
AUTH_ARCHIVE="$DAY_DIR/files-auth-storage-$TIMESTAMP.tar.gz"
SECRETS_ARCHIVE="$DAY_DIR/secrets-sierra-$TIMESTAMP.tar.gz"

log() {
  printf '[%s] %s\n' "$(date -Is)" "$*"
}

fail() {
  log "ERRO: $*"
  exit 1
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || fail "comando obrigatorio nao encontrado: $1"
}

require_path() {
  [[ -e "$1" ]] || fail "caminho obrigatorio nao encontrado: $1"
}

cleanup() {
  rm -rf "$TMP_DIR"
}
trap cleanup EXIT

append_file_manifest() {
  local file="$1"
  local label="$2"

  require_path "$file"
  local size
  size="$(du -h "$file" | awk '{print $1}')"

  log "Artefato $label: $(basename "$file") ($size)"
  (cd "$DAY_DIR" && sha256sum "$(basename "$file")") >> "$MANIFEST"
}

create_database_dump() {
  log "Gerando dump do banco $DB_NAME no container $MYSQL_CONTAINER..."

  docker inspect "$MYSQL_CONTAINER" >/dev/null 2>&1 || fail "container MySQL nao encontrado: $MYSQL_CONTAINER"

  docker exec "$MYSQL_CONTAINER" sh -lc '
    set -eu
    MYSQL_PWD="${MYSQL_ROOT_PASSWORD:-}" mysqldump \
      -uroot \
      --single-transaction \
      --quick \
      --routines \
      --triggers \
      --events \
      --default-character-set=utf8mb4 \
      "$1"
  ' sh "$DB_NAME" | gzip -9 > "$DB_ARCHIVE"

  gzip -t "$DB_ARCHIVE"
  append_file_manifest "$DB_ARCHIVE" "database"
}

create_storage_archive() {
  local app_dir="$1"
  local output="$2"
  local label="$3"

  require_path "$app_dir/storage/app"
  log "Compactando storage/app de $label..."

  tar -C "$app_dir" -czf "$output" storage/app
  tar -tzf "$output" >/dev/null
  append_file_manifest "$output" "$label"
}

create_secrets_archive() {
  log "Compactando .env das APIs em pacote separado..."

  require_path "$ESTOQUE_APP_DIR/.env"
  require_path "$AUTH_APP_DIR/.env"

  mkdir -p "$TMP_DIR/secrets/estoque" "$TMP_DIR/secrets/auth"
  install -m 600 "$ESTOQUE_APP_DIR/.env" "$TMP_DIR/secrets/estoque/.env"
  install -m 600 "$AUTH_APP_DIR/.env" "$TMP_DIR/secrets/auth/.env"

  tar -C "$TMP_DIR" -czf "$SECRETS_ARCHIVE" secrets
  tar -tzf "$SECRETS_ARCHIVE" >/dev/null
  append_file_manifest "$SECRETS_ARCHIVE" "secrets"
}

validate_manifest() {
  log "Validando checksums..."
  (cd "$DAY_DIR" && sha256sum -c "$(basename "$MANIFEST")")
}

upload_to_drive() {
  if [[ "$DO_UPLOAD" == false ]]; then
    log "Upload desabilitado por --no-upload."
    return 0
  fi

  require_command rclone

  log "Enviando backup para $RCLONE_REMOTE/$RUN_DATE..."
  rclone mkdir "$RCLONE_REMOTE/$RUN_DATE"
  rclone copy "$DAY_DIR" "$RCLONE_REMOTE/$RUN_DATE" \
    --filter "+ *-$TIMESTAMP.*" \
    --filter "+ manifest-$TIMESTAMP.txt" \
    --filter "- *" \
    --transfers 4 \
    --checkers 8
}

prune_local() {
  log "Aplicando retencao local de $RETENTION_DAYS dias..."
  find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -mtime +"$RETENTION_DAYS" -print -exec rm -rf {} \;
}

prune_remote() {
  if [[ "$DO_UPLOAD" == false ]]; then
    return 0
  fi

  require_command rclone

  log "Aplicando retencao remota de $RETENTION_DAYS dias..."
  rclone delete "$RCLONE_REMOTE" --min-age "${RETENTION_DAYS}d" || fail "falha ao aplicar retencao remota"
  rclone rmdirs "$RCLONE_REMOTE" --leave-root || true
}

main() {
  require_command docker
  require_command tar
  require_command gzip
  require_command sha256sum
  require_command find
  require_command awk

  require_path "$ESTOQUE_APP_DIR"
  require_path "$AUTH_APP_DIR"

  mkdir -p "$DAY_DIR" "$TMP_DIR"
  : > "$MANIFEST"

  log "Iniciando backup Sierra em $DAY_DIR..."
  create_database_dump
  create_storage_archive "$ESTOQUE_APP_DIR" "$ESTOQUE_ARCHIVE" "estoque-storage"
  create_storage_archive "$AUTH_APP_DIR" "$AUTH_ARCHIVE" "auth-storage"
  create_secrets_archive
  validate_manifest
  upload_to_drive
  prune_local
  prune_remote

  log "Backup concluido com sucesso."
}

main
