#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
API_DIR="$ROOT_DIR/api"
PORT="${INTEGRATION_PORT:-8001}"
BASE_URL="http://127.0.0.1:${PORT}"
PHP_BIN="${PHP_BIN:-php}"

TMP_DIR="$(mktemp -d)"
DB_PATH_OVERRIDE="$TMP_DIR/integration.sqlite"
SESSION_SAVE_PATH="$TMP_DIR/sessions"

export DB_PATH_OVERRIDE
export INTEGRATION_BASE_URL="$BASE_URL"
export SESSION_SAVE_PATH

cleanup() {
  if [[ -n "${SERVER_PID:-}" ]]; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
    wait "$SERVER_PID" >/dev/null 2>&1 || true
  fi
  rm -rf "$TMP_DIR"
}

trap cleanup EXIT

mkdir -p "$SESSION_SAVE_PATH"

"$PHP_BIN" -d session.save_path="$SESSION_SAVE_PATH" -S "127.0.0.1:${PORT}" -t "$API_DIR" "$API_DIR/index.php" >"$TMP_DIR/php-server.log" 2>&1 &
SERVER_PID=$!

for _ in {1..30}; do
  if command -v curl >/dev/null 2>&1; then
    if curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/get_config" | grep -qE '^(200|401)$'; then
      break
    fi
  else
    if wget -qO- "$BASE_URL/get_config" >/dev/null 2>&1; then
      break
    fi
  fi
  sleep 0.2
done

if [[ ! -f "$API_DIR/vendor/bin/phpunit" ]]; then
  echo "phpunit not found at $API_DIR/vendor/bin/phpunit. Run composer install first." >&2
  exit 1
fi

(cd "$API_DIR" && "$PHP_BIN" vendor/bin/phpunit --testsuite Integration)
