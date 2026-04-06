<?php

namespace App\Services;

use App\Enums\CouponPurchaseType;
use App\Enums\CouponType;
use App\Exceptions\CouponApplicationException;
use App\Models\Coupon;
use App\Models\CouponTransaction;
use App\Models\CouponUser;
use App\Models\LaboratoryPurchase;
use App\Models\OnlinePharmacyPurchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CouponApplicationService
{
    /**
     * Aplica el cupón completo a una compra de laboratorio (uso total, sin parcial).
     *
     * @return int Monto descontado en centavos
     */
    public function applyForLaboratoryPurchase(User $user, LaboratoryPurchase $purchase, int $couponId): int
    {
        return DB::transaction(function () use ($user, $purchase, $couponId) {
            $coupon = Coupon::query()->whereKey($couponId)->lockForUpdate()->firstOrFail();

            $assignment = CouponUser::query()
                ->where('coupon_id', $coupon->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCouponApplicable($coupon, $assignment, $purchase->total_cents);

            $discountCents = $coupon->remaining_cents;

            $coupon->remaining_cents = 0;
            $coupon->save();

            $assignment->used_at = now();
            $assignment->save();

            CouponTransaction::create([
                'coupon_id' => $coupon->id,
                'user_id' => $user->id,
                'purchase_type' => CouponPurchaseType::Lab,
                'purchase_id' => $purchase->id,
                'amount_used_cents' => $discountCents,
            ]);

            $purchase->coupon_discount_cents = $discountCents;
            $purchase->save();

            return $discountCents;
        });
    }

    /**
     * @return int Monto descontado en centavos
     */
    public function applyForPharmacyPurchase(User $user, OnlinePharmacyPurchase $purchase, int $couponId): int
    {
        return DB::transaction(function () use ($user, $purchase, $couponId) {
            $coupon = Coupon::query()->whereKey($couponId)->lockForUpdate()->firstOrFail();

            $assignment = CouponUser::query()
                ->where('coupon_id', $coupon->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCouponApplicable($coupon, $assignment, $purchase->total_cents);

            $discountCents = $coupon->remaining_cents;

            $coupon->remaining_cents = 0;
            $coupon->save();

            $assignment->used_at = now();
            $assignment->save();

            CouponTransaction::create([
                'coupon_id' => $coupon->id,
                'user_id' => $user->id,
                'purchase_type' => CouponPurchaseType::Pharmacy,
                'purchase_id' => $purchase->id,
                'amount_used_cents' => $discountCents,
            ]);

            $purchase->coupon_discount_cents = $discountCents;
            $purchase->save();

            return $discountCents;
        });
    }

    /**
     * Valida reglas de negocio sin persistir (checkout previo al pago).
     *
     * @throws CouponApplicationException
     */
    public function validateApplication(User $user, int $couponId, int $purchaseTotalCents): void
    {
        $coupon = Coupon::query()->findOrFail($couponId);

        $assignment = CouponUser::query()
            ->where('coupon_id', $coupon->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertCouponApplicable($coupon, $assignment, $purchaseTotalCents);
    }

    private function assertCouponApplicable(Coupon $coupon, CouponUser $assignment, int $purchaseTotalCents): void
    {
        if (! $coupon->is_active) {
            throw new CouponApplicationException('El cupón no está activo.');
        }

        if ($coupon->type !== CouponType::Balance) {
            throw new CouponApplicationException('Tipo de cupón no soportado.');
        }

        if ($assignment->used_at !== null) {
            throw new CouponApplicationException('Este cupón ya fue utilizado.');
        }

        if ($coupon->remaining_cents <= 0) {
            throw new CouponApplicationException('El cupón no tiene saldo disponible.');
        }

        if ($coupon->remaining_cents > $purchaseTotalCents) {
            throw new CouponApplicationException(
                'Tu saldo es mayor al total de la compra, no puede aplicarse en esta compra.'
            );
        }
    }
}
