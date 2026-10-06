#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

echo "El túnel arranca solo con 'docker compose up -d' si COMPOSE_PROFILES=tunnel en .env."
echo "Mostrando logs de cloudflared (Ctrl+C no apaga el túnel)..."
echo

docker compose logs -f cloudflared
