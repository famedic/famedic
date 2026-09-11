#!/usr/bin/env bash
set -uo pipefail
cd /home/laliyo/projects/Odessa-Famedic/famedic
reset_test_db() {
  docker compose exec -T app bash -lc 'rm -f database/test_db.sqlite database/test_db.sqlite-journal database/test_db.sqlite-wal && touch database/test_db.sqlite && chmod 666 database/test_db.sqlite' 2>/dev/null || true
}
reset_test_db
RESULT="${1:-/tmp/regression_results.txt}"

run_suite() {
  local suite="$1"
  if [[ "${suite}" =~ ^tests/Feature/ ]]; then
    reset_test_db
  fi
  echo "===== ${suite} =====" | tee -a "${RESULT}"
  if docker compose exec -T app php artisan test "${suite}" 2>&1 | tee -a "${RESULT}"; then
    echo "EXIT:0" | tee -a "${RESULT}"
  else
    echo "EXIT:$?" | tee -a "${RESULT}"
  fi
  echo "" | tee -a "${RESULT}"
}

for suite in "${@:2}"; do
  run_suite "${suite}"
done
