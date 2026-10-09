#!/usr/bin/env bash
set -uo pipefail
cd /home/usuario/projects/famedic
mkdir -p scripts
OUT=scripts/agent-pest-unit-output.txt
{
  docker compose exec -T app bash -lc 'cd /var/www/html && php vendor/bin/pest tests/Unit/LaboratoryPreparation/ --no-coverage'
  ec=$?
  echo "EXIT_CODE=$ec"
  exit $ec
} > "$OUT" 2>&1
