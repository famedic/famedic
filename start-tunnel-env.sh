#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

echo "==> Famedic: levantando ambiente Docker con Cloudflare Tunnel"

if ! command -v docker >/dev/null 2>&1; then
  echo "Error: Docker no está disponible en esta terminal."
  echo "Abre Docker Desktop y vuelve a ejecutar este script."
  exit 1
fi

if [[ ! -f .env ]]; then
  if [[ -f .env.example ]]; then
    echo "==> No existe .env; copiando .env.example"
    cp .env.example .env
  else
    echo "Error: no existe .env ni .env.example."
    exit 1
  fi
fi

echo "==> Usando perfil tunnel"
export COMPOSE_PROFILES=tunnel

echo "==> Deteniendo Vite dev server si estaba activo"
docker compose stop node >/dev/null 2>&1 || true

echo "==> Levantando contenedores"
docker compose up -d --remove-orphans

echo "==> Preparando assets compilados para el tunel"
docker compose run --rm tunnel-assets

echo "==> Reiniciando cloudflared para usar el estado limpio"
started_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
docker compose restart cloudflared >/dev/null

echo "==> Esperando URL publica del tunel"
for attempt in {1..20}; do
  url="$(
    docker compose logs --since "$started_at" cloudflared 2>/dev/null \
      | grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' \
      | tail -1 \
      || true
  )"
  if [[ "$url" == https://*.trycloudflare.com ]]; then
    echo
    echo "Ambiente listo."
    echo "Local:  http://localhost:8080"
    echo "Tunel:  $url"
    echo
    exit 0
  fi

  sleep 2
done

echo
echo "El ambiente se levanto, pero aun no encontre la URL del tunel."
echo "Revisa los logs con:"
echo "  docker compose logs -f cloudflared"
echo
exit 1
