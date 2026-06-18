#!/bin/bash
set -e
cd /var/www/html
for i in $(seq 1 15); do
  echo "=== queue:work run $i (max-jobs=3) ==="
  php artisan queue:work --max-jobs=3 --no-ansi 2>&1 || true
  php artisan tinker --execute='include "storage/coupon_queue_count.php";' 2>&1
  if grep -q 'REMAINING_COUPON_JOBS:0' /tmp/coupon_count_out 2>/dev/null; then
    break
  fi
done
