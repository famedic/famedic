#!/bin/sh
set -e

cd /var/www/html

if [ -f composer.json ] && [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist --optimize-autoloader
    chown -R www-data:www-data vendor 2>/dev/null || true
fi

mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# Directorios escribibles para Chromium (PDFs vía Browsershot / crashpad)
mkdir -p /tmp/.chromium/profile /tmp/.chromium/crashdumps /tmp/.chromium/Crashpad
chmod -R 777 /tmp/.chromium 2>/dev/null || true

# Puppeteer (Browsershot) para PDFs de órdenes de laboratorio
if [ -f package.json ] && command -v npm >/dev/null 2>&1 && [ ! -d node_modules/puppeteer ]; then
    npm ci --omit=dev --no-audit --no-fund 2>/dev/null || npm ci --no-audit --no-fund 2>/dev/null || true
fi

exec docker-php-entrypoint "$@"
