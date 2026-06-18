<?php
$allCoupon = Illuminate\Support\Facades\DB::table('jobs')->where(function ($q) {
    $q->where('payload', 'like', '%CouponBalanceActivatedMail%')
        ->orWhere('payload', 'like', '%CouponAssignedMail%')
        ->orWhere('payload', 'like', '%CouponPendingBalanceInvitationMail%');
})->count();
$total = Illuminate\Support\Facades\DB::table('jobs')->count();
file_put_contents('/tmp/coupon_count_out', "REMAINING_COUPON_JOBS:{$allCoupon}\nJOBS_TOTAL:{$total}\n");
echo "REMAINING_COUPON_JOBS:{$allCoupon}\n";
echo "JOBS_TOTAL:{$total}\n";
