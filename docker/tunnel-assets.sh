#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# Vite dev crea public/hot y fuerza localhost:5173 → pantalla en blanco en el túnel.
rm -f public/hot

if [[ -f public/hot ]]; then
  echo "[tunnel-assets] AVISO: public/hot sigue presente; detén el contenedor node (perfil dev)."
fi

if [[ "${TUNNEL_REBUILD_ASSETS:-false}" == "true" ]] || [[ ! -f public/build/manifest.json ]]; then
  echo "[tunnel-assets] Compilando frontend (npm run build)..."
  npm ci
  npm run build
else
  echo "[tunnel-assets] manifest.json OK; omitiendo build (TUNNEL_REBUILD_ASSETS=true para forzar)."
fi
