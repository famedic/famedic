<?php

namespace App\Services;

use App\Enums\CouponApprovalStatus;
use App\Enums\CouponPurchaseType;
use App\Enums\CouponType;
use App\Exceptions\CouponApplicationException;
use App\Exceptions\CouponReversalException;
use App\Models\Coupon;
use App\Models\CouponAuditLog;
use App\Models\CouponTransaction;
use App\Models\CouponUser;
use App\Models\LaboratoryPurchase;
use App\Models\OnlinePharmacyPurchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CouponApplicationService
{
    /**
     * Aplica el crédito completo a una compra de laboratorio (uso total, sin parcial en saldo).
     *
     * @return int Monto descontado en centavos
     */
    public function applyForLaboratoryPurchase(
        User $user,
        LaboratoryPurchase $purchase,
        int $couponId,
        bool $skipActiveCampaignCreditRedeemed = false,
    ): int {
        return DB::transaction(function () use ($user, $purchase, $couponId, $skipActiveCampaignCreditRedeemed) {
            $coupon = Coupon::query()->whereKey($couponId)->lockForUpdate()->firstOrFail();

            $assignment = CouponUser::query()
                ->where('coupon_id', $coupon->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCouponApplicable($coupon, $assignment, $purchase->total_cents);

            $discountCents = $this->resolveDiscountCents($coupon, $purchase->total_cents);

            $coupon->remaining_cents = 0;
            $coupon->save();

            $assignment->used_at = now();
            $assignment->save();

            $transaction = CouponTransaction::create([
                'coupon_id' => $coupon->id,
                'user_id' => $user->id,
                'purchase_type' => CouponPurchaseType::Lab,
                'purchase_id' => $purchase->id,
                'amount_used_cents' => $discountCents,
            ]);

            $purchase->coupon_discount_cents = $discountCents;
            $purchase->save();

            if (! $skipActiveCampaignCreditRedeemed) {
                app(\App\Services\ActiveCampaign\CouponActiveCampaignDispatcher::class)
                    ->creditRedeemed($coupon, $assignment, $transaction, $user, $purchase);
            }

            return $discountCents;
        });
    }

    /**
     * @return int Monto descontado en centavos
     */
    public function applyForPharmacyPurchase(
        User $user,
        OnlinePharmacyPurchase $purchase,
        int $couponId,
        bool $skipActiveCampaignCreditRedeemed = false,
    ): int {
        return DB::transaction(function () use ($user, $purchase, $couponId, $skipActiveCampaignCreditRedeemed) {
            $coupon = Coupon::query()->whereKey($couponId)->lockForUpdate()->firstOrFail();

            $assignment = CouponUser::query()
                ->where('coupon_id', $coupon->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCouponApplicable($coupon, $assignment, $purchase->total_cents);

            $discountCents = $this->resolveDiscountCents($coupon, $purchase->total_cents);

            $coupon->remaining_cents = 0;
            $coupon->save();

            $assignment->used_at = now();
            $assignment->save();

            $transaction = CouponTransaction::create([
                'coupon_id' => $coupon->id,
                'user_id' => $user->id,
                'purchase_type' => CouponPurchaseType::Pharmacy,
                'purchase_id' => $purchase->id,
                'amount_used_cents' => $discountCents,
            ]);

            $purchase->coupon_discount_cents = $discountCents;
            $purchase->save();

            if (! $skipActiveCampaignCreditRedeemed) {
                app(\App\Services\ActiveCampaign\CouponActiveCampaignDispatcher::class)
                    ->creditRedeemed($coupon, $assignment, $transaction, $user, $purchase);
            }

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

    public function resolveDiscountCents(Coupon $coupon, int $purchaseTotalCents): int
    {
        if ($coupon->type === CouponType::Coupon) {
            return min((int) $coupon->remaining_cents, max(0, $purchaseTotalCents));
        }

        return (int) $coupon->remaining_cents;
    }

    private function assertCouponApplicable(Coupon $coupon, CouponUser $assignment, int $purchaseTotalCents): void
    {
        if (! $coupon->is_active) {
            throw new CouponApplicationException('El crédito no está activo.');
        }

        if ($coupon->approval_status !== CouponApprovalStatus::Active) {
            throw new CouponApplicationException('El crédito no está autorizado.');
        }

        if (! in_array($coupon->type, [CouponType::Balance, CouponType::Coupon], true)) {
            throw new CouponApplicationException('Tipo de crédito no soportado.');
        }

        if ($assignment->used_at !== null) {
            throw new CouponApplicationException('Este crédito ya fue utilizado.');
        }

        if ($coupon->remaining_cents <= 0) {
            throw new CouponApplicationException('El crédito no tiene saldo disponible.');
        }

        if ($coupon->type === CouponType::Balance && $coupon->remaining_cents > $purchaseTotalCents) {
            throw new CouponApplicationException(
                'Tu saldo es mayor al total de la compra, no puede aplicarse en esta compra.'
            );
        }

        if ($coupon->isNotYetValid()) {
            $fecha = localizedDate($coupon->valid_from)?->isoFormat('D [de] MMMM [de] YYYY');
            $label = $coupon->type === CouponType::Coupon ? 'cupón' : 'saldo a favor';

            throw new CouponApplicationException(
                "Tu {$label} estará disponible a partir del {$fecha}."
            );
        }

        if ($coupon->isExpired()) {
            $fecha = localizedDate($coupon->expires_at)?->isoFormat('D [de] MMMM [de] YYYY');
            $label = $coupon->type === CouponType::Coupon ? 'cupón' : 'saldo a favor';

            throw new CouponApplicationException(
                "Tu {$label} venció el {$fecha}."
            );
        }

        if (! $coupon->meetsMinimumPurchase($purchaseTotalCents)) {
            $label = $coupon->type === CouponType::Coupon ? 'cupón' : 'saldo a favor';

            throw new CouponApplicationException(
                "Para usar tu {$label} necesitas una compra mínima de ".$coupon->formatted_min_purchase.'.'
            );
        }
    }

    /**
     * Revierte el saldo a favor aplicado a un pedido de laboratorio cancelado.
     *
     * @return int Monto restaurado en centavos (0 si no había cupón activo o ya fue revertido)
     *
     * @throws CouponReversalException
     */
    public function reverseForLaboratoryPurchase(
        LaboratoryPurchase $purchase,
        ?User $actor = null,
        string $reason = 'laboratory_purchase_cancelled'
    ): int {
        return DB::transaction(function () use ($purchase, $actor, $reason) {
            $couponTransaction = CouponTransaction::query()
                ->where('purchase_type', CouponPurchaseType::Lab)
                ->where('purchase_id', $purchase->id)
                ->notReversed()
                ->lockForUpdate()
                ->first();

            if ($couponTransaction === null) {
                $alreadyReversed = CouponTransaction::query()
                    ->where('purchase_type', CouponPurchaseType::Lab)
                    ->where('purchase_id', $purchase->id)
                    ->whereNotNull('reversed_at')
                    ->exists();

                if ($alreadyReversed) {
                    return 0;
                }

                if ((int) ($purchase->coupon_discount_cents ?? 0) > 0) {
                    throw new CouponReversalException(
                        'El pedido tiene descuento por cupón registrado pero no existe una transacción de cupón activa.'
                    );
                }

                return 0;
            }

            $amountUsedCents = (int) $couponTransaction->amount_used_cents;
            if ($amountUsedCents <= 0) {
                throw new CouponReversalException('El monto usado del cupón debe ser mayor a cero.');
            }

            $coupon = Coupon::query()
                ->whereKey($couponTransaction->coupon_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($coupon->type !== CouponType::Balance) {
                throw new CouponReversalException('Solo se pueden revertir créditos de tipo saldo a favor.');
            }

            $assignment = CouponUser::query()
                ->where('coupon_id', $coupon->id)
                ->where('user_id', $couponTransaction->user_id)
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                throw new CouponReversalException('No se encontró la asignación del cupón al usuario.');
            }

            $purchase->loadMissing('customer.user');
            $purchaseUserId = $purchase->customer?->user?->id;

            if ($purchaseUserId === null || (int) $purchaseUserId !== (int) $couponTransaction->user_id) {
                throw new CouponReversalException(
                    'La asignación del cupón no corresponde al usuario del pedido.'
                );
            }

            if ($assignment->used_at === null) {
                throw new CouponReversalException(
                    'El cupón no está marcado como utilizado para este pedido.'
                );
            }

            $coupon->remaining_cents = $amountUsedCents;
            $coupon->save();

            $assignment->used_at = null;
            $assignment->save();

            $couponTransaction->reversed_at = now();
            $couponTransaction->reversed_by_user_id = $actor?->id;
            $couponTransaction->reversal_reason = $reason;
            $couponTransaction->save();

            CouponAuditLog::create([
                'type' => 'application',
                'action' => 'reverse_coupon_application',
                'status' => 'completed',
                'actor_user_id' => $actor?->id,
                'coupon_id' => $coupon->id,
                'context' => [
                    'purchase_type' => CouponPurchaseType::Lab->value,
                    'purchase_id' => $purchase->id,
                    'coupon_id' => $coupon->id,
                    'coupon_transaction_id' => $couponTransaction->id,
                    'user_id' => $couponTransaction->user_id,
                    'amount_restored_cents' => $amountUsedCents,
                    'reason' => $reason,
                    'actor_user_id' => $actor?->id,
                ],
            ]);

            $user = $purchase->customer?->user ?? User::query()->find($couponTransaction->user_id);
            if ($user !== null) {
                app(\App\Services\ActiveCampaign\CouponActiveCampaignDispatcher::class)->creditRestored(
                    $coupon,
                    $assignment,
                    $couponTransaction,
                    $user,
                    $purchase,
                    $reason,
                );
            }

            return $amountUsedCents;
        });
    }
}
