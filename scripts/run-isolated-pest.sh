#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

SUFFIX="$(date +%Y%m%d%H%M%S)_$$_${RANDOM}"
if [[ -n "${TEST_DB_DATABASE:-}" ]]; then
  echo "Do not override TEST_DB_DATABASE; each invocation owns a fresh disposable database." >&2
  exit 1
fi
TEST_DB_DATABASE="famedic_test_description_ia_${SUFFIX}"
JUNIT_PATH="${JUNIT_PATH:-storage/logs/junit-${TEST_DB_DATABASE}.xml}"
LOG_PATH="${LOG_PATH:-codex/pest-${TEST_DB_DATABASE}.log}"

if [[ $# -eq 0 ]]; then
  echo "Usage: $0 <pest test paths/options...>" >&2
  exit 1
fi

mkdir -p "$(dirname "${JUNIT_PATH}")" "$(dirname "${LOG_PATH}")"

DB_CREATED=0
cleanup() {
  if [[ "${DB_CREATED}" == "1" ]]; then
    docker compose exec -T mysql mysql -uroot -proot -e "DROP DATABASE \`${TEST_DB_DATABASE}\`;" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

docker compose exec -T mysql mysql -uroot -proot -e "CREATE DATABASE \`${TEST_DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`${TEST_DB_DATABASE}\`.* TO 'famedic'@'%'; FLUSH PRIVILEGES;"
DB_CREATED=1

set +e
docker compose exec -T \
  -e APP_ENV=testing \
  -e FAMEDIC_ISOLATED_TEST_RUN=1 \
  -e DB_CONNECTION=mysql \
  -e DB_HOST=mysql \
  -e DB_DATABASE="${TEST_DB_DATABASE}" \
  -e TEST_DB_CONNECTION=mysql \
  -e TEST_DB_DATABASE="${TEST_DB_DATABASE}" \
  -e DB_USERNAME=famedic \
  -e DB_PASSWORD=famedic \
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
