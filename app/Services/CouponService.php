<?php

namespace App\Services;

use App\Enums\CouponType;
use App\Mail\CouponAssignedMail;
use App\Models\Coupon;
use App\Models\CouponUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class CouponService
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

    public function getUserBalance(int $userId): int
    {
        return (int) Coupon::query()
            ->where('coupons.is_active', true)
            ->where('coupons.remaining_cents', '>', 0)
            ->where('coupons.type', CouponType::Balance)
            ->join('coupon_user', 'coupon_user.coupon_id', '=', 'coupons.id')
            ->where('coupon_user.user_id', $userId)
            ->whereNull('coupon_user.used_at')
            ->sum('coupons.remaining_cents');
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{id:int, remaining_cents:int, code:?string}>
     */
    public function getAvailableCoupons(int $userId): \Illuminate\Support\Collection
    {
        return Coupon::query()
            ->select(['coupons.id', 'coupons.remaining_cents', 'coupons.code'])
            ->where('coupons.is_active', true)
            ->where('coupons.remaining_cents', '>', 0)
            ->where('coupons.type', CouponType::Balance)
            ->join('coupon_user', 'coupon_user.coupon_id', '=', 'coupons.id')
            ->where('coupon_user.user_id', $userId)
            ->whereNull('coupon_user.used_at')
            ->orderBy('coupons.id')
            ->get();
    }

    public function assignCouponToUser(User $user, int $amountCents, bool $sendNotification = true, ?string $code = null): Coupon
    {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('El monto del cupón debe ser mayor a cero.');
        }

        return DB::transaction(function () use ($user, $amountCents, $sendNotification, $code) {
            $coupon = Coupon::create([
                'code' => $code,
                'amount_cents' => $amountCents,
                'remaining_cents' => $amountCents,
                'type' => CouponType::Balance,
                'is_active' => true,
            ]);

            CouponUser::create([
                'coupon_id' => $coupon->id,
                'user_id' => $user->id,
                'assigned_at' => now(),
            ]);

            if ($sendNotification) {
                $formatted = formattedCentsPrice($amountCents);
                $this->notificationService->createNotification(
                    $user,
                    'coupon_assigned',
                    'Saldo a favor disponible',
                    "Tienes {$formatted} disponibles en tu cuenta."
                );

                Mail::to($user->email)->send(new CouponAssignedMail($user, $amountCents));
            }

            return $coupon;
        });
    }
}
