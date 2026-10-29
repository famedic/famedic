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
JSON_RAW="$OUT_DIR/newman-staging-phase-a-raw.json"
STATE_FILE="$OUT_DIR/.rc1-staging-run-state.local.json"
FOLDER="00 - RC1 Fase A (staging OTP)"

missing=()
for v in BASE_URL ATIX_SANDBOX_INTEGRATION_KEY_ID ATIX_SANDBOX_HMAC_SECRET RC1_STAGING_PHONE; do
  if [[ -z "${!v:-}" ]]; then missing+=("$v"); fi
done

if [[ ${#missing[@]} -gt 0 ]]; then
  echo "BLOCKED: missing env: ${missing[*]}"
  exit 4
fi

base="${BASE_URL:-}"
if [[ "$base" == *"famedic.com.mx"* && "$base" != *"staging"* ]]; then
  echo "BLOCKED: BASE_URL looks like production"
  exit 3
fi

uuidgen_cmd() {
  if command -v uuidgen >/dev/null 2>&1; then uuidgen; else python3 -c 'import uuid; print(uuid.uuid4())'; fi
}

RC1_CONTEXT_CONVERSATION_ID="${RC1_CONTEXT_CONVERSATION_ID:-$(uuidgen_cmd | tr '[:upper:]' '[:lower:]')}"
RC1_CONTEXT_CHANNEL_EVENT_ID="${RC1_CONTEXT_CHANNEL_EVENT_ID:-$(uuidgen_cmd | tr '[:upper:]' '[:lower:]')}"

if command -v newman >/dev/null 2>&1; then NEWMAN=newman; else NEWMAN="npx --yes newman"; fi

ENV_ARGS=(
  --env-var "base_url=${BASE_URL}"
  --env-var "atix_integration_key_id=${ATIX_SANDBOX_INTEGRATION_KEY_ID}"
  --env-var "atix_hmac_secret=${ATIX_SANDBOX_HMAC_SECRET}"
  --env-var "phone=${RC1_STAGING_PHONE}"
  --env-var "rc1_phase=A"
  --env-var "conversation_id=${RC1_CONTEXT_CONVERSATION_ID}"
  --env-var "channel_event_id=${RC1_CONTEXT_CHANNEL_EVENT_ID}"
)

echo "RC1 Fase A — conversation_id=${RC1_CONTEXT_CONVERSATION_ID}"
echo "RC1 Fase A — channel_event_id=${RC1_CONTEXT_CHANNEL_EVENT_ID}"

set +e
$NEWMAN run "$COLLECTION" -e "$ENV_FILE" \
  "${ENV_ARGS[@]}" \
  --folder "$FOLDER" \
  --reporters cli,json \
  --reporter-json-export "$JSON_RAW" \
  --delay-request 250 \
  --timeout-request 90000 \
  --bail true
NEWMAN_EXIT=$?
set -e

if [[ "$NEWMAN_EXIT" -ne 0 ]]; then
  echo "Fase A falló (exit $NEWMAN_EXIT). Revise lookup/request-code antes de pedir OTP."
  rm -f "$JSON_RAW"
  exit "$NEWMAN_EXIT"
fi

python3 scripts/extract-rc1-phase-a-state.py "$JSON_RAW" "$STATE_FILE"
rm -f "$JSON_RAW"

echo ""
echo "Fase A OK. Estado guardado en: $STATE_FILE"
echo "Siguiente paso:"
echo "  1) Admin staging → /admin/otp-movements-monitor → revelar OTP (challenge_id en estado)."
echo "  2) export OTP_CODE=<codigo_fresco>   # no reutilizar OTP de corridas anteriores"
echo "  3) bash scripts/run-newman-rc1-staging-phase-b.sh"

exit 0
