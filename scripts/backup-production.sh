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
LOCAL_RETENTION_DAYS="${LOCAL_RETENTION_DAYS:-7}"
REMOTE_RETENTION_DAYS="${REMOTE_RETENTION_DAYS:-30}"
ESTOQUE_APP_DIR="${ESTOQUE_APP_DIR:-/home/docker/acadsoft/sierra-estoque/html}"
AUTH_APP_DIR="${AUTH_APP_DIR:-/home/docker/acadsoft/sierra-auth/html}"
BACKUP_TIMEZONE="${BACKUP_TIMEZONE:-America/Sao_Paulo}"

DO_UPLOAD=true
VERIFY_AND_PRUNE_ONLY=false
PRUNE_ONLY=false
for arg in "$@"; do
  case "$arg" in
    --no-upload) DO_UPLOAD=false ;;
    --verify-and-prune) VERIFY_AND_PRUNE_ONLY=true ;;
    --prune-only) PRUNE_ONLY=true ;;
    *) echo "Uso: $0 [--no-upload] [--verify-and-prune|--prune-only]" >&2; exit 2 ;;
  esac
done

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

  verify_backup_set "$MANIFEST"
}

verify_backup_set() {
  local manifest="$1"
  local day_dir day timestamp marker remote_day filename

  day_dir="$(dirname "$manifest")"
  day="$(basename "$day_dir")"
  filename="$(basename "$manifest")"
  timestamp="${filename#manifest-}"
  timestamp="${timestamp%.txt}"
  marker="$day_dir/.remote-verified-$timestamp"
  remote_day="$RCLONE_REMOTE/$day"

  if [[ -f "$marker" ]]; then
    log "Conjunto ja verificado: $day/$timestamp"
    return 0
  fi

  [[ "$day" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || fail "diretorio de backup invalido: $day_dir"
  (cd "$day_dir" && sha256sum -c "$filename")

  log "Comparando conjunto $day/$timestamp com o remoto criptografado..."
  rclone check "$day_dir" "$remote_day" \
    --one-way \
    --download \
    --include "*-$timestamp.*"

  while read -r checksum artifact; do
    [[ -n "$checksum" && -n "$artifact" ]] || continue
    rclone lsf "$remote_day/$artifact" --files-only | grep -Fxq "$artifact" \
      || fail "artefato remoto ausente: $remote_day/$artifact"
  done < "$manifest"
  rclone lsf "$remote_day/$filename" --files-only | grep -Fxq "$filename" \
    || fail "manifesto remoto ausente: $remote_day/$filename"

  gzip -t "$day_dir/db-$DB_NAME-$timestamp.sql.gz"
  printf 'verified_at=%s\nremote=%s\n' "$(date -Is)" "$remote_day" > "$marker"
  log "Conjunto verificado e liberado para retencao local: $day/$timestamp"
}

verify_existing_sets() {
  local manifest
  require_command rclone
  while IFS= read -r manifest; do
    if ! (verify_backup_set "$manifest"); then
      log "AVISO: conjunto mantido localmente porque a verificacao remota falhou: $manifest"
    fi
  done < <(find "$BACKUP_ROOT" -mindepth 2 -maxdepth 2 -type f -name 'manifest-*.txt' | sort)
}

remove_verified_extra_sets() {
  local day_dir manifest timestamp marker keep=true
  while IFS= read -r day_dir; do
    keep=true
    while IFS= read -r manifest; do
      if [[ "$keep" == true ]]; then
        keep=false
        continue
      fi
      timestamp="$(basename "$manifest")"
      timestamp="${timestamp#manifest-}"
      timestamp="${timestamp%.txt}"
      marker="$day_dir/.remote-verified-$timestamp"
      [[ -f "$marker" ]] || continue
      log "Removendo conjunto local redundante verificado: $(basename "$day_dir")/$timestamp"
      find "$day_dir" -maxdepth 1 -type f \( -name "*-$timestamp.*" -o -name ".remote-verified-$timestamp" \) -delete
    done < <(find "$day_dir" -maxdepth 1 -type f -name 'manifest-*.txt' | sort -r)
  done < <(find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -name '????-??-??' | sort)
}

prune_local() {
  local day_dir unresolved
  log "Aplicando retencao local de $LOCAL_RETENTION_DAYS dias somente a conjuntos verificados..."
  remove_verified_extra_sets

  while IFS= read -r day_dir; do
    [[ "$(realpath "$(dirname "$day_dir")")" == "$(realpath "$BACKUP_ROOT")" ]] \
      || fail "recusa em remover caminho fora da raiz de backup: $day_dir"
    [[ "$(basename "$day_dir")" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] \
      || fail "recusa em remover diretorio inesperado: $day_dir"
    unresolved="$(find "$day_dir" -maxdepth 1 -type f -name 'manifest-*.txt' | while read -r manifest; do timestamp="$(basename "$manifest")"; timestamp="${timestamp#manifest-}"; timestamp="${timestamp%.txt}"; [[ -f "$day_dir/.remote-verified-$timestamp" ]] || echo "$manifest"; done)"
    [[ -z "$unresolved" ]] || { log "Mantendo dia nao verificado: $day_dir"; continue; }
    log "Removendo dia local expirado e verificado: $day_dir"
    rm -rf -- "$day_dir"
  done < <(find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -name '????-??-??' -mtime +"$LOCAL_RETENTION_DAYS" | sort)
}

prune_remote() {
  if [[ "$DO_UPLOAD" == false ]]; then
    return 0
  fi

  require_command rclone

  log "Aplicando retencao remota de $REMOTE_RETENTION_DAYS dias..."
  rclone delete "$RCLONE_REMOTE" --min-age "${REMOTE_RETENTION_DAYS}d" || fail "falha ao aplicar retencao remota"
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

  if [[ "$PRUNE_ONLY" == true ]]; then
    prune_local
    log "Retencao local dos conjuntos previamente verificados concluida."
    return
  fi

  if [[ "$VERIFY_AND_PRUNE_ONLY" == true ]]; then
    verify_existing_sets
    prune_local
    prune_remote
    log "Verificacao e retencao concluidas."
    return
  fi

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
