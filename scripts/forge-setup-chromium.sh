#!/usr/bin/env bash
# Ejecutar UNA VEZ en el servidor Forge (SSH como usuario forge).
set -euo pipefail

echo "==> Instalando Chromium y dependencias para PDFs (Browsershot)..."
sudo apt-get update

# Ubuntu 22/24: el paquete suele llamarse "chromium" (no siempre chromium-browser)
if apt-cache show chromium &>/dev/null; then
  CHROMIUM_PKG=chromium
elif apt-cache show chromium-browser &>/dev/null; then
  CHROMIUM_PKG=chromium-browser
else
  echo "ERROR: No hay paquete chromium en apt. Prueba: sudo snap install chromium"
  exit 1
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
for candidate in /usr/bin/chromium /usr/bin/chromium-browser /usr/bin/google-chrome-stable /snap/bin/chromium; do
  if [ -x "$candidate" ]; then
    CHROME="$candidate"
    break
  fi
done

if [ -z "$CHROME" ]; then
  echo "ERROR: Chromium instalado pero no hay binario en rutas conocidas."
  echo "Prueba: ls -la /usr/bin/chromium*  &&  command -v chromium"
  exit 1
fi

echo "==> Chromium: $CHROME"
"$CHROME" --version || true

mkdir -p /tmp/.chromium/profile /tmp/.chromium/crashdumps /tmp/.chromium/Crashpad
chmod -R 777 /tmp/.chromium

echo ""
echo "=== Agrega en Forge → Environment (sitio) ==="
echo "BROWSERSHOT_CHROME_PATH=$CHROME"
echo "BROWSERSHOT_CHROME_USER_DATA_DIR=/tmp/.chromium"
echo ""
echo "=== Verifica con Laravel (desde current del sitio) ==="
echo "php artisan laboratory:check-pdf-deps"
