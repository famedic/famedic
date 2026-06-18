<?php
$email = 'queue-test-pend@example.com';
$norm = App\Models\CouponBeneficiary::normalizeEmail($email);
$ben = App\Models\CouponBeneficiary::query()
    ->where('email_normalized', $norm)
    ->where('status', App\Enums\CouponBeneficiaryStatus::PendingUser)
    ->whereNull('child_coupon_id')
    ->whereNull('cancelled_at')
    ->first();
if ($ben) {
    echo "FOUND_BEN:{$ben->id} parent={$ben->parent_coupon_id}\n";
} else {
    $parent = App\Models\Coupon::query()->whereNull('parent_coupon_id')->first()
        ?? App\Models\Coupon::query()->first();
    if (!$parent) {
        echo "NO_COUPON\n";
        return;
    }
    $ben = App\Models\CouponBeneficiary::create([
        'parent_coupon_id' => $parent->id,
        'email' => $email,
        'email_normalized' => $norm,
        'status' => App\Enums\CouponBeneficiaryStatus::PendingUser,
        'source' => App\Enums\CouponBeneficiarySource::Manual,
    ]);
    echo "CREATED_BEN:{$ben->id} parent={$parent->id}\n";
}
$user = App\Models\User::where('email', $email)->first();
if ($user) {
    $user->delete();
    echo "DELETED_OLD_USER\n";
}
$user = App\Models\User::factory()->withUnverifiedEmail()->create([
    'email' => $email,
    'name' => 'Queue Test Pend',
]);
echo "USER:{$user->id} verified=" . ($user->hasVerifiedEmail() ? 'yes' : 'no') . "\n";
$user->markEmailAsVerified();
$user->refresh();
echo "VERIFIED\n";
$result = app(App\Services\CouponBeneficiaryService::class)->linkPendingBeneficiariesForUser($user);
echo "LINK_RESULT: " . json_encode($result) . "\n";
$countActivated = Illuminate\Support\Facades\DB::table('jobs')
    ->where('payload', 'like', '%CouponBalanceActivatedMail%')->count();
echo "JOBS_CouponBalanceActivatedMail:{$countActivated}\n";
$allCoupon = Illuminate\Support\Facades\DB::table('jobs')->where(function ($q) {
    $q->where('payload', 'like', '%CouponBalanceActivatedMail%')
        ->orWhere('payload', 'like', '%CouponAssignedMail%')
        ->orWhere('payload', 'like', '%CouponPendingBalanceInvitationMail%');
})->count();
echo "JOBS_ALL_COUPON_MAIL:{$allCoupon}\n";
echo "JOBS_TOTAL:" . Illuminate\Support\Facades\DB::table('jobs')->count() . "\n";
