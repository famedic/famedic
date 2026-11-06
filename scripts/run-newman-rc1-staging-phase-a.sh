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

export RC1_CONTEXT_CONVERSATION_ID="${RC1_CONTEXT_CONVERSATION_ID:-$(node -e 'process.stdout.write(require("crypto").randomUUID())')}"
export RC1_CONTEXT_CHANNEL_EVENT_ID="${RC1_CONTEXT_CHANNEL_EVENT_ID:-$(node -e 'process.stdout.write(require("crypto").randomUUID())')}"
export BASE_URL ATIX_SANDBOX_INTEGRATION_KEY_ID ATIX_SANDBOX_HMAC_SECRET RC1_STAGING_PHONE
RUN_DIR="$(mktemp -d "$PRIVATE/phase-a.XXXXXXXX")"
set +e
node scripts/run-newman-secure.cjs A "$RUN_DIR"
NEWMAN_EXIT=$?
set -e
if [[ -f "$RUN_DIR/raw.json" ]]; then
  python3 -B scripts/sanitize-newman-report.py "$RUN_DIR/raw.json" "$RUN_DIR/report.json" "$RUN_DIR/summary.json"
  python3 -B scripts/sanitize-newman-junit.py "$RUN_DIR/raw.xml" "$RUN_DIR/junit.xml"
  python3 -B scripts/scan-evidence.py "$RUN_DIR/report.json" "$RUN_DIR/summary.json" "$RUN_DIR/junit.xml"
fi
if [[ "$NEWMAN_EXIT" -ne 0 ]]; then exit "$NEWMAN_EXIT"; fi
python3 -B scripts/extract-rc1-phase-a-state.py "$RUN_DIR/raw.json" "$RUN_DIR/state.json"
printf '%s\n' "$RUN_DIR/state.json" > "$PRIVATE/phase-a-state-path"
printf '%s\n' 'Fase A completed. State pointer is restricted; enter fresh OTP without shell history before Fase B.'
