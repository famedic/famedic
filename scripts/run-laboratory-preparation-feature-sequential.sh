#!/usr/bin/env bash
# Regresión Feature Fase 1 (LaboratoryPreparation*) sobre MySQL famedic_test.
# Una sola instancia a la vez: RefreshDatabase + migrate compiten si hay varios Pest en paralelo.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

LOCK_FILE="${FAMEDIC_TEST_MYSQL_LOCK:-/tmp/famedic-test-mysql.lock}"
exec 200>"${LOCK_FILE}"
if ! flock -n 200; then
  echo "Otra ejecución ya usa ${LOCK_FILE} (probablemente otro Pest sobre famedic_test)." >&2
  echo "Espera a que termine o elimina el proceso en conflicto." >&2
  exit 1
fi

if docker compose ps --status running app 2>/dev/null | grep -q app; then
  docker compose exec -T app bash -lc 'cd /var/www/html && php vendor/bin/pest tests/Feature/LaboratoryPreparation* --no-coverage'
else
  php vendor/bin/pest tests/Feature/LaboratoryPreparation* --no-coverage
fi
