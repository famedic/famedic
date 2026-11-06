#!/usr/bin/env bash
set -euo pipefail
set +x
umask 077
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
for env_file in "$ROOT/.newman-rc1-staging.env.local" "$HOME/.config/famedic/newman-rc1-staging.env"; do
  if [[ -f "$env_file" ]]; then
    set -a
    source "$env_file"
    set +a
    break
  fi
done
# Raw and runtime state stay outside docs/deliverables. No historical files removed.
PRIVATE="$ROOT/.private-akubica-evidence"
mkdir -p "$PRIVATE"
chmod 700 "$PRIVATE"

POINTER="$PRIVATE/phase-a-state-path"
if [[ ! -f "$POINTER" ]]; then printf '%s\n' 'BLOCKED: missing phase A state' >&2; exit 1; fi
IFS= read -r RC1_STATE_FILE < "$POINTER"
export RC1_STATE_FILE
# OTP is read without echo if it was not supplied in the inherited environment.
if [[ -z "${OTP_CODE:-}" ]]; then
  if [[ ! -t 0 ]]; then printf '%s\n' 'BLOCKED: missing OTP input' >&2; exit 1; fi
  IFS= read -r -s -p 'Fresh OTP: ' OTP_CODE
  printf '\n'
fi
export OTP_CODE BASE_URL ATIX_SANDBOX_INTEGRATION_KEY_ID ATIX_SANDBOX_HMAC_SECRET RC1_STAGING_PHONE
RUN_DIR="$(mktemp -d "$PRIVATE/phase-b.XXXXXXXX")"
set +e
node scripts/run-newman-secure.cjs B "$RUN_DIR"
NEWMAN_EXIT=$?
set -e
unset OTP_CODE
if [[ -f "$RUN_DIR/raw.json" ]]; then
  python3 -B scripts/sanitize-newman-report.py "$RUN_DIR/raw.json" "$RUN_DIR/report.json" "$RUN_DIR/summary.json"
  python3 -B scripts/sanitize-newman-junit.py "$RUN_DIR/raw.xml" "$RUN_DIR/junit.xml"
  python3 -B scripts/scan-evidence.py "$RUN_DIR/report.json" "$RUN_DIR/summary.json" "$RUN_DIR/junit.xml"
fi
exit "$NEWMAN_EXIT"
