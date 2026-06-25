#!/usr/bin/env bash
set -euo pipefail

API_BASE="${API_BASE:-https://estoque.sierra.acadsoft.com.br}"
FRONT_ORIGIN="${FRONT_ORIGIN:-https://sierra.acadsoft.com.br}"
TIMEOUT_SECONDS="${TIMEOUT_SECONDS:-20}"

tmp_dir="$(mktemp -d)"
cleanup() {
  rm -rf "$tmp_dir"
}
trap cleanup EXIT

fail() {
  echo "ERRO: $*" >&2
  exit 1
}

health_headers="$tmp_dir/health.headers"
health_body="$tmp_dir/health.body"

health_code="$(
  curl -sS \
    --max-time "$TIMEOUT_SECONDS" \
    -D "$health_headers" \
    -o "$health_body" \
    -w '%{http_code}' \
    "$API_BASE/api/v1/health"
)"

[[ "$health_code" == "200" ]] || fail "health retornou HTTP $health_code"
grep -q '"status":"ok"' "$health_body" || fail "health nao retornou status ok"

preflight_headers="$tmp_dir/preflight.headers"
preflight_code="$(
  curl -sS \
    --max-time "$TIMEOUT_SECONDS" \
    -X OPTIONS "$API_BASE/api/v1/dashboard/admin?period=month" \
    -H "Origin: $FRONT_ORIGIN" \
    -H "Access-Control-Request-Method: GET" \
    -H "Access-Control-Request-Headers: authorization,accept" \
    -D "$preflight_headers" \
    -o /dev/null \
    -w '%{http_code}'
)"

case "$preflight_code" in
  200|204) ;;
  *) fail "preflight retornou HTTP $preflight_code" ;;
esac

grep -qi '^Access-Control-Allow-Origin:' "$preflight_headers" \
  || fail "preflight sem Access-Control-Allow-Origin"
grep -qi '^Access-Control-Allow-Headers:.*authorization' "$preflight_headers" \
  || fail "preflight sem authorization em Access-Control-Allow-Headers"

echo "OK: dashboard API health e preflight CORS validos."
