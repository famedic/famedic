#!/usr/bin/env bash
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
RESULT="${1:-/tmp/regression_unit_ac.txt}"
: > "${RESULT}"
SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/run-regression-batch.sh"
for f in tests/Unit/ActiveCampaign/*.php tests/Unit/Orders/ActiveCampaignOrderDriverTest.php; do
  "${SCRIPT}" "${RESULT}" "${f}"
done
grep -E '^(=====|Tests:|EXIT:)' "${RESULT}"
