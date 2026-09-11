#!/usr/bin/env bash
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
RESULT="${1:-/tmp/regression_unit_ac_individual.txt}"
: > "${RESULT}"
for f in tests/Unit/ActiveCampaign/*.php tests/Unit/Orders/ActiveCampaignOrderDriverTest.php; do
  scripts/run-regression-batch.sh "${RESULT}" "${f}"
done
echo "===== UNIT AC SUMMARY =====" | tee -a "${RESULT}"
grep -E '^(=====|Tests:|EXIT:)' "${RESULT}" | tee -a "${RESULT}"
failures=$(grep -c 'EXIT:[^0]' "${RESULT}" || true)
echo "FAIL_SUITES:${failures}" | tee -a "${RESULT}"
