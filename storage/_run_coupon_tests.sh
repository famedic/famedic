#!/bin/sh
set -e
cd /home/developer/projects/famedic
rm -f storage/test-run-output2.txt
touch database/test_db.sqlite
chmod -R u+w storage bootstrap/cache database/test_db.sqlite 2>/dev/null || true
{
echo "=== MIGRATE FRESH (via test bootstrap) ==="
php artisan migrate:fresh --force --no-ansi --env=testing 2>&1 || true

echo "=== REVERSAL TEST ==="
php artisan test tests/Feature/Coupons/LaboratoryPurchaseCouponReversalTest.php --no-ansi 2>&1
echo "EXIT_REVERSAL:$?"

echo "=== PHASE3 TEST ==="
php artisan test tests/Feature/Coupons/LaboratoryPurchaseCouponReversalPhase3Test.php --no-ansi 2>&1
echo "EXIT_PHASE3:$?"

} > storage/test-run-output2.txt 2>&1
cat storage/test-run-output2.txt