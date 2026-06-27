#!/usr/bin/env bash
set +e
cd /home/developer/projects/famedic
chmod -R u+w storage bootstrap/cache 2>/dev/null || true
{
echo "=== PDO CHECK ==="
php -m | grep -i pdo || true
php -r 'echo extension_loaded("pdo_mysql") ? "pdo_mysql: YES\n" : "pdo_mysql: NO\n";'

echo "=== MYSQL CONNECTION ==="
php artisan db:show --no-ansi 2>&1 || php -r '
try {
  $pdo = new PDO(getenv("DB_CONNECTION") === "mysql" ? "mysql:host=".getenv("DB_HOST").";dbname=".getenv("DB_DATABASE") : "sqlite:".getenv("DB_DATABASE"));
  echo "PDO connect: OK\n";
} catch (Exception $e) { echo "PDO connect FAIL: ".$e->getMessage()."\n"; }
' 2>&1

echo "=== MIGRATE ==="
php artisan migrate --no-ansi 2>&1
echo "MIGRATE_EXIT:$?"

echo "=== TESTS ==="
for f in CouponEligibilityIsolatedTest CouponBeneficiaryIsolatedTest CouponBeneficiaryLinkingIsolatedTest CouponBeneficiaryNotificationsIsolatedTest LaboratoryPurchaseCouponReversalIsolatedTest; do
  echo "--- $f ---"
  php artisan test tests/Feature/Coupons/${f}.php --no-ansi 2>&1
  echo "TEST_${f}_EXIT:$?"
done

echo "=== BUILD PERMS ==="
ls -la public/build 2>&1 | head -5
sudo chown -R $(whoami):$(whoami) public/build 2>&1 || chown -R $(whoami):$(whoami) public/build 2>&1 || true

echo "=== NPM BUILD ==="
npm run build 2>&1
echo "BUILD_EXIT:$?"
} > storage/release-check.log 2>&1