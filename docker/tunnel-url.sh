#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

status="$(docker compose ps cloudflared --format '{{.Status}}' 2>/dev/null || true)"

if [[ -z "$status" ]] || [[ "$status" != Up* ]]; then
  echo "cloudflared no está corriendo."
  echo "Levanta el stack con: docker compose up -d"
  exit 1
fi

url="$(docker compose logs cloudflared 2>&1 | grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' | tail -1)"

if [[ -z "$url" ]]; then
  echo "cloudflared está arriba pero aún no hay URL en los logs."
  echo "Espera unos segundos y vuelve a ejecutar: bash docker/tunnel-url.sh"
  echo "O revisa: docker compose logs -f cloudflared"
  exit 1
fi

echo "$url"
