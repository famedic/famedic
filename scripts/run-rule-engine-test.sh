#!/usr/bin/env bash
set -uo pipefail
cd /home/usuario/projects/famedic
OUT=/home/usuario/projects/famedic/scripts/rule-engine-test-output.txt
{
  echo "=== started $(date -Iseconds) ==="
  docker compose exec -T app bash -lc 'cd /var/www/html && php vendor/bin/pest tests/Unit/LaboratoryPreparation/LaboratoryPreparationRuleEngineTest.php --no-coverage'
  echo "=== exit code: $? ==="
} >"$OUT" 2>&1
