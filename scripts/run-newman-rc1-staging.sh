#!/usr/bin/env bash
# Corrida completa RC1 staging: Fase A → (OTP manual) → Fase B si OTP_CODE está definido.
# Recomendado: scripts/run-newman-rc1-staging-phase-a.sh luego phase-b.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# Credenciales locales opcionales (gitignored); no commitear secretos.
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
JSON_RAW="$OUT_DIR/newman-staging-raw.json"
JSON_SAN="$OUT_DIR/newman-staging-report-sanitized.json"
JUNIT_SAN="$OUT_DIR/newman-staging-junit-sanitized.xml"
SUMMARY="$OUT_DIR/newman-staging-summary.json"

missing=()
for v in BASE_URL ATIX_SANDBOX_INTEGRATION_KEY_ID ATIX_SANDBOX_HMAC_SECRET RC1_STAGING_PHONE RC1_STAGING_UNKNOWN_PHONE; do
  if [[ -z "${!v:-}" ]]; then missing+=("$v"); fi
done

python3 <<'PY'
import json, sys
c = json.load(open("postman/Famedic-Akubica-v1.3.0-rc1.postman_collection.json"))
name = c.get("info", {}).get("name", "")
ver = c.get("info", {}).get("version", "")
if "v1.3.0-rc1" not in name and ver != "1.3.0-rc1":
    sys.exit("Collection is not RC1")
print("collection_ok", name, ver)
PY

base="${BASE_URL:-}"
if [[ -z "$base" ]]; then
  echo "BLOCKED: BASE_URL empty"
  exit 2
fi
if [[ "$base" == *"famedic.com.mx/api/v1"* && "$base" != *"staging"* ]]; then
  echo "BLOCKED: BASE_URL looks like production"
  exit 3
fi
if [[ ${#missing[@]} -gt 0 ]]; then
  echo "BLOCKED: missing env: ${missing[*]}"
  exit 4
fi

if command -v newman >/dev/null 2>&1; then
  NEWMAN=newman
else
  NEWMAN="npx --yes newman"
fi

ENV_ARGS=(
  --env-var "base_url=${BASE_URL}"
  --env-var "atix_integration_key_id=${ATIX_SANDBOX_INTEGRATION_KEY_ID}"
  --env-var "atix_hmac_secret=${ATIX_SANDBOX_HMAC_SECRET}"
)
[[ -n "${RC1_STAGING_PHONE:-}" ]] && ENV_ARGS+=(--env-var "phone=${RC1_STAGING_PHONE}")
[[ -n "${RC1_STAGING_UNKNOWN_PHONE:-}" ]] && ENV_ARGS+=(--env-var "unknown_phone=${RC1_STAGING_UNKNOWN_PHONE}")
uuidgen_cmd() {
  if command -v uuidgen >/dev/null 2>&1; then uuidgen; else python3 -c 'import uuid; print(uuid.uuid4())'; fi
}
RUN_CONV="${RC1_CONTEXT_CONVERSATION_ID:-$(uuidgen_cmd | tr '[:upper:]' '[:lower:]')}"
RUN_EVENT="${RC1_CONTEXT_CHANNEL_EVENT_ID:-$(uuidgen_cmd | tr '[:upper:]' '[:lower:]')}"
ENV_ARGS+=(
  --env-var "rc1_phase=A"
  --env-var "conversation_id=${RUN_CONV}"
  --env-var "channel_event_id=${RUN_EVENT}"
)

FOLDERS=(--folder "00 - RC1 Fase A (staging OTP)" --folder "01 - ATIX phone lookup (contratos)")
if [[ -n "${OTP_CODE:-}" ]]; then
  ENV_ARGS+=(--env-var "otp_code=${OTP_CODE}")
  FOLDERS+=(--folder "02 - RC1 Fase B (Bearer y carrito)")
else
  echo "Aviso: OTP_CODE vacío — se omitirá Fase B (verify-code y endpoints protegidos)."
  echo "Ejecute Fase A, obtenga OTP en monitor, luego: OTP_CODE=... bash scripts/run-newman-rc1-staging-phase-b.sh"
fi

set +e
$NEWMAN run "$COLLECTION" -e "$ENV_FILE" \
  "${ENV_ARGS[@]}" \
  "${FOLDERS[@]}" \
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
