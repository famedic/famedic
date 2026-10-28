#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

if [[ -n "${TEST_DB_DATABASE:-}" ]]; then
  echo "Do not override TEST_DB_DATABASE; this runner uses a private in-memory SQLite database." >&2
  exit 1
fi

if [[ $# -eq 0 ]]; then
  echo "Usage: $0 <pest test paths/options...>" >&2
  exit 1
fi

RUN_ID="$(date +%Y%m%d%H%M%S)_$$_${RANDOM}"
SQLITE_RELATIVE_PATH="storage/framework/testing/famedic_test_${RUN_ID}.sqlite"
SQLITE_DATABASE="/var/www/html/${SQLITE_RELATIVE_PATH}"
SQLITE_CONTAINER_DIR="/var/www/html/$(dirname "${SQLITE_RELATIVE_PATH}")"
SQLITE_FILE_CREATED=0
JUNIT_PATH="${JUNIT_PATH:-storage/logs/junit-sqlite-${RUN_ID}.xml}"
LOG_PATH="${LOG_PATH:-codex/pest-sqlite-${RUN_ID}.log}"
mkdir -p "$(dirname "${JUNIT_PATH}")" "$(dirname "${LOG_PATH}")"
if [[ ! "${SQLITE_RELATIVE_PATH}" =~ ^storage/framework/testing/famedic_test_[0-9]{14}_[0-9]+_[0-9]+\.sqlite$ ]]; then
  echo "Refusing unexpected SQLite test database path: ${SQLITE_RELATIVE_PATH}" >&2
  exit 1
fi
docker compose exec -T app mkdir -p "${SQLITE_CONTAINER_DIR}"
if docker compose exec -T app test -e "${SQLITE_DATABASE}"; then
  echo "Refusing to reuse existing SQLite test database: ${SQLITE_RELATIVE_PATH}" >&2
  exit 1
fi
docker compose exec -T app touch "${SQLITE_DATABASE}"
SQLITE_FILE_CREATED=1
if ! docker compose exec -T app test -f "${SQLITE_DATABASE}"; then
  echo "Could not create isolated SQLite database file: ${SQLITE_RELATIVE_PATH}" >&2
  exit 1
fi
cleanup() {
  if [[ "${SQLITE_FILE_CREATED}" -eq 1 ]]; then
    docker compose exec -T app rm -f -- "${SQLITE_DATABASE}" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

set +e
docker compose exec -T \
  -e APP_ENV=testing \
  -e FAMEDIC_ISOLATED_TEST_RUN=sqlite-memory \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE="${SQLITE_DATABASE}" \
  -e TEST_DB_CONNECTION=sqlite \
  -e TEST_DB_DATABASE="${SQLITE_DATABASE}" \
  -e QUEUE_CONNECTION=sync \
  -e MAIL_MAILER=array \
  -e ACTIVE_CAMPAIGN_ENABLED=false \
  -e ACTIVECAMPAIGN_CART_OUTBOX_ENABLED=false \
  -e ACTIVECAMPAIGN_TAG_ABANDONED_CARTS_ENABLED=false \
  -e ACTIVE_CAMPAIGN_CART_SITE_EVENTS_ENABLED=false \
  -e ACTIVE_CAMPAIGN_CART_TAG_REMOVE_ENABLED=false \
  -e OPENAI_API_KEY= \
  -e JUNIT_PATH="${JUNIT_PATH}" \
  app bash -lc 'cd /var/www/html && exec php vendor/bin/pest "$@" --log-junit "$JUNIT_PATH" --no-coverage' _ "$@" 2>&1 | tee "${LOG_PATH}"
status=${PIPESTATUS[0]}
set -e

if [[ ! -s "${JUNIT_PATH}" ]]; then
  echo "JUnit report was not created or is empty: ${JUNIT_PATH}" >&2
  exit 2
fi

exit "${status}"
