#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

for env_file in "$ROOT/.newman-rc1-staging.env.local" "$HOME/.config/famedic/newman-rc1-staging.env"; do
  if [[ -f "$env_file" ]]; then
    set -a
    # shellcheck disable=SC1090
    source "$env_file"
    set +a
    break
  fi
done

COLLECTION="postman/Famedic-Akubica-v1.3.0-rc1.postman_collection.json"
ENV_FILE="docs/Akubica/v1.3.0-rc1/Famedic-Akubica-v1.3.0-rc1-staging.postman_environment.json"
OUT_DIR="docs/Akubica/v1.3.0-rc1"
JSON_RAW="$OUT_DIR/newman-staging-phase-b-raw.json"
JSON_SAN="$OUT_DIR/newman-staging-report-sanitized.json"
JUNIT_SAN="$OUT_DIR/newman-staging-junit-sanitized.xml"
SUMMARY="$OUT_DIR/newman-staging-summary.json"
STATE_FILE="$OUT_DIR/.rc1-staging-run-state.local.json"
FOLDER="02 - RC1 Fase B (Bearer y carrito)"

missing=()
for v in BASE_URL ATIX_SANDBOX_INTEGRATION_KEY_ID ATIX_SANDBOX_HMAC_SECRET RC1_STAGING_PHONE; do
  if [[ -z "${!v:-}" ]]; then missing+=("$v"); fi
done

if [[ -z "${OTP_CODE:-}" ]]; then
  echo "Fase B detenida: OTP_CODE no configurado."
  echo "Obtenga el OTP en monitor staging (misma ejecución Fase A) y export OTP_CODE antes de continuar."
  echo "No reutilice OTP de ejecuciones anteriores."
  exit 5
fi

if [[ ! -f "$STATE_FILE" ]]; then
  echo "Fase B detenida: falta $STATE_FILE (ejecute scripts/run-newman-rc1-staging-phase-a.sh primero)."
  exit 6
fi

readarray -t STATE_VARS < <(python3 - <<PY
import json
from pathlib import Path
state = json.loads(Path("$STATE_FILE").read_text(encoding="utf-8"))
for k in ("conversation_id", "channel_event_id", "challenge_id"):
    v = state.get(k, "")
    if not v:
        raise SystemExit(f"missing {k} in state file")
    print(f"{k}={v}")
PY
)

CONV=""
EVENT=""
CHALLENGE=""
for line in "${STATE_VARS[@]}"; do
  case "$line" in
    conversation_id=*) CONV="${line#conversation_id=}" ;;
    channel_event_id=*) EVENT="${line#channel_event_id=}" ;;
    challenge_id=*) CHALLENGE="${line#challenge_id=}" ;;
  esac
done

base="${BASE_URL:-}"
if [[ "$base" == *"famedic.com.mx"* && "$base" != *"staging"* ]]; then
  echo "BLOCKED: BASE_URL looks like production"
  exit 3
fi

if [[ ${#missing[@]} -gt 0 ]]; then
  echo "BLOCKED: missing env: ${missing[*]}"
  exit 4
fi

if command -v newman >/dev/null 2>&1; then NEWMAN=newman; else NEWMAN="npx --yes newman"; fi

ENV_ARGS=(
  --env-var "base_url=${BASE_URL}"
  --env-var "atix_integration_key_id=${ATIX_SANDBOX_INTEGRATION_KEY_ID}"
  --env-var "atix_hmac_secret=${ATIX_SANDBOX_HMAC_SECRET}"
  --env-var "phone=${RC1_STAGING_PHONE}"
  --env-var "rc1_phase=B"
  --env-var "conversation_id=${CONV}"
  --env-var "channel_event_id=${EVENT}"
  --env-var "challenge_id=${CHALLENGE}"
  --env-var "otp_code=${OTP_CODE}"
)

echo "RC1 Fase B — challenge_id=${CHALLENGE} (contexto Fase A)"
echo "RC1 Fase B — OTP_CODE configurado (valor no se imprime)"

set +e
$NEWMAN run "$COLLECTION" -e "$ENV_FILE" \
  "${ENV_ARGS[@]}" \
  --folder "$FOLDER" \
  --reporters cli,json,junit \
  --reporter-json-export "$JSON_RAW" \
  --reporter-junit-export "$OUT_DIR/newman-staging-junit-raw.xml" \
  --delay-request 250 \
  --timeout-request 90000 \
  --bail false
NEWMAN_EXIT=$?
set -e

python3 scripts/sanitize-newman-report.py "$JSON_RAW" "$JSON_SAN" "$SUMMARY"
python3 scripts/sanitize-newman-junit.py "$OUT_DIR/newman-staging-junit-raw.xml" "$JUNIT_SAN"
rm -f "$JSON_RAW" "$OUT_DIR/newman-staging-junit-raw.xml"

exit "$NEWMAN_EXIT"
