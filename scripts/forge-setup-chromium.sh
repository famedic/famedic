#!/usr/bin/env bash
# Ejecutar UNA VEZ en el servidor Forge (SSH como forge) para PDFs de órdenes de laboratorio.
set -euo pipefail

echo "==> Instalando Chromium y dependencias para Puppeteer/Browsershot..."
sudo apt-get update
# En Ubuntu 22+/24+ el paquete puede llamarse chromium o chromium-browser
if apt-cache show chromium-browser &>/dev/null; then
  CHROMIUM_PKG=chromium-browser
else
  CHROMIUM_PKG=chromium
fi

sudo apt-get install -y \
  "$CHROMIUM_PKG" \
  libatk1.0-0 \
  libatk-bridge2.0-0 \
  libcups2 \
  libdrm2 \
  libgbm1 \
  libnss3 \
  libxcomposite1 \
  libxdamage1 \
  libxrandr2 \
  fonts-liberation

CHROME=""
for candidate in /usr/bin/chromium-browser /usr/bin/chromium /snap/bin/chromium; do
  if [ -x "$candidate" ]; then
    CHROME="$candidate"
    break
  fi
done

if [ -z "$CHROME" ]; then
  echo "ERROR: No se encontró chromium después de instalar. Revisa manualmente con: which chromium-browser"
  exit 1
fi

echo "==> Chromium encontrado en: $CHROME"
"$CHROME" --version || true

mkdir -p /tmp/.chromium/profile /tmp/.chromium/crashdumps /tmp/.chromium/Crashpad
chmod -R 777 /tmp/.chromium

echo ""
echo "Listo. En Forge → Environment del sitio, agrega:"
echo "  BROWSERSHOT_CHROME_PATH=$CHROME"
echo "  BROWSERSHOT_CHROME_USER_DATA_DIR=/tmp/.chromium"
echo ""
echo "Tras cada deploy, asegúrate de que exista node_modules (npm ci en el script de deploy)."
