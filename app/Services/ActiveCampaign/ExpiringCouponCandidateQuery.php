<?php

namespace App\Services\ActiveCampaign;

use App\Enums\CouponApprovalStatus;
use App\Enums\CouponType;
use App\Models\Coupon;
use App\Models\CouponTransaction;
use App\Models\CouponUser;
use Illuminate\Database\Eloquent\Builder;

class ExpiringCouponCandidateQuery
{
    /**
     * @return Builder<CouponUser>
     */
    public function candidatesWithinDays(int $days): Builder
    {
        $now = now();
        $threshold = $now->copy()->addDays($days);

        return CouponUser::query()
            ->whereHas('coupon', function (Builder $query) use ($now, $threshold) {
                $query
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '>', $now)
                    ->where('expires_at', '<=', $threshold)
                    ->whereIn('type', [CouponType::Balance, CouponType::Coupon]);
            })
            ->whereHas('user', function (Builder $query) {
                $query->whereNotNull('email')->where('email', '!=', '');
            })
            ->with([
                'coupon.concept',
                'coupon.parentCoupon.concept',
                'user.customer',
            ])
            ->orderBy('coupon_user.id');
    }

    public function isEligible(CouponUser $assignment): bool
    {
        return $this->skipReason($assignment) === null;
    }

    public function skipReason(CouponUser $assignment): ?string
    {
        $assignment->loadMissing(['coupon', 'user']);

        $user = $assignment->user;
        $email = $user?->email;

        if (! is_string($email) || trim($email) === '' || filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
            return 'no_email';
        }

        if ($assignment->used_at !== null) {
            return 'used';
        }

        $coupon = $assignment->coupon;
        if ($coupon === null) {
            return 'invalid';
        }

        if ((int) $coupon->remaining_cents <= 0) {
            return 'no_remaining';
        }

        if ($coupon->expires_at === null) {
            return 'no_expiry';
        }

        if ($coupon->isExpired()) {
            return 'expired';
        }

        if (! $coupon->is_active) {
            return 'inactive';
        }

        if ($coupon->approval_status !== CouponApprovalStatus::Active) {
            return 'not_authorized';
        }

        if ($coupon->isNotYetValid()) {
            return 'not_yet_valid';
        }

        if (! in_array($coupon->type, [CouponType::Balance, CouponType::Coupon], true)) {
            return 'invalid_type';
        }

        if ($this->hasDefinitiveConsumption($coupon, $assignment)) {
            return 'consumed';
        }

        return null;
    }

    private function hasDefinitiveConsumption(Coupon $coupon, CouponUser $assignment): bool
    {
        if ($assignment->used_at !== null || (int) $coupon->remaining_cents <= 0) {
            return true;
        }

        return CouponTransaction::query()
            ->where('coupon_id', $coupon->id)
            ->where('user_id', $assignment->user_id)
            ->notReversed()
            ->exists()
            && (int) $coupon->remaining_cents === 0;
    }
}
