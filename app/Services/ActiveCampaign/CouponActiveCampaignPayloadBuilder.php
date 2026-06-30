<?php

namespace App\Services\ActiveCampaign;

use App\Models\Coupon;
use App\Models\CouponBeneficiary;
use App\Models\CouponTransaction;
use App\Models\CouponUser;
use App\Models\LaboratoryPurchase;
use App\Models\OnlinePharmacyPurchase;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\User;
use App\Services\CouponService;

class CouponActiveCampaignPayloadBuilder
{
    public function __construct(
        private CouponService $couponService,
    ) {}

    public function creditAssigned(Coupon $coupon, CouponUser $assignment, User $user, string $source): array
    {
        $coupon->loadMissing(['concept', 'parentCoupon.concept']);
        $user->loadMissing('customer');
        $balances = $this->balanceSnapshot((int) $user->id);

        return $this->basePayload('credit_assigned', $user, [
            'coupon_id' => $coupon->id,
            'parent_coupon_id' => $coupon->parent_coupon_id,
            'coupon_user_id' => $assignment->id,
            'amount_cents' => (int) $coupon->amount_cents,
            'remaining_cents' => (int) $coupon->remaining_cents,
            'valid_from' => $coupon->valid_from?->toIso8601String(),
            'expires_at' => $coupon->expires_at?->toIso8601String(),
            'min_purchase_cents' => $coupon->min_purchase_cents,
            'coupon_type' => $coupon->type?->value,
            'campaign_name' => $this->resolveCampaignName($coupon),
            'concept' => $this->resolveConceptLabel($coupon),
            'assigned_at' => ($assignment->assigned_at ?? now())->toIso8601String(),
            'source' => $source,
            'saldo_total_cents' => $balances['saldo_total_cents'],
            'saldo_aplicable_cents' => $balances['saldo_aplicable_cents'],
            'saldo_condicionado_cents' => $balances['saldo_condicionado_cents'],
        ]);
    }

    public function creditRedeemed(
        Coupon $coupon,
        CouponUser $assignment,
        CouponTransaction $transaction,
        User $user,
        LaboratoryPurchase|OnlinePharmacyPurchase $purchase,
    ): array {
        $purchaseType = $purchase instanceof LaboratoryPurchase ? 'lab' : 'pharmacy';
        $purchaseKey = $purchaseType === 'lab' ? 'laboratory_purchase_id' : 'pharmacy_purchase_id';

        return $this->basePayload('credit_redeemed', $user, [
            'coupon_id' => $coupon->id,
            'parent_coupon_id' => $coupon->parent_coupon_id,
            'coupon_user_id' => $assignment->id,
            'coupon_transaction_id' => $transaction->id,
            $purchaseKey => $purchase->id,
            'purchase_type' => $purchaseType,
            'amount_cents' => (int) $transaction->amount_used_cents,
            'remaining_cents_after' => (int) $coupon->remaining_cents,
            'purchase_total_cents' => (int) $purchase->total_cents,
            'redeemed_at' => ($transaction->created_at ?? now())->toIso8601String(),
        ]);
    }

    public function creditRestored(
        Coupon $coupon,
        CouponUser $assignment,
        CouponTransaction $transaction,
        User $user,
        LaboratoryPurchase $purchase,
        string $reason,
    ): array {
        $coupon->refresh();
        $balances = $this->balanceSnapshot((int) $user->id);

        return $this->basePayload('credit_restored', $user, [
            'coupon_id' => $coupon->id,
            'parent_coupon_id' => $coupon->parent_coupon_id,
            'coupon_user_id' => $assignment->id,
            'coupon_transaction_id' => $transaction->id,
            'laboratory_purchase_id' => $purchase->id,
            'restored_cents' => (int) $transaction->amount_used_cents,
            'remaining_cents_after' => (int) $coupon->remaining_cents,
            'restored_at' => ($transaction->reversed_at ?? now())->toIso8601String(),
            'reason' => $reason,
            'expires_at' => $coupon->expires_at?->toIso8601String(),
            'is_usable_after_restore' => $this->isCouponUsableAfterRestore($coupon),
            'saldo_total_cents' => $balances['saldo_total_cents'],
            'saldo_aplicable_cents' => $balances['saldo_aplicable_cents'],
            'saldo_condicionado_cents' => $balances['saldo_condicionado_cents'],
        ]);
    }

    public function creditRevoked(
        Coupon $coupon,
        CouponUser $assignment,
        User $user,
        int $remainingBeforeCents,
        ?int $actorUserId,
        ?string $reason,
    ): array {
        $balances = $this->balanceSnapshot((int) $user->id);

        return $this->basePayload('credit_revoked', $user, [
            'coupon_id' => $coupon->id,
            'parent_coupon_id' => $coupon->parent_coupon_id,
            'coupon_user_id' => $assignment->id,
            'amount_cents' => (int) $coupon->amount_cents,
            'remaining_cents_before' => $remainingBeforeCents,
            'remaining_cents_after' => (int) $coupon->remaining_cents,
            'revoked_at' => now()->toIso8601String(),
            'actor_user_id' => $actorUserId,
            'reason' => $reason,
            'saldo_total_cents' => $balances['saldo_total_cents'],
            'saldo_aplicable_cents' => $balances['saldo_aplicable_cents'],
            'saldo_condicionado_cents' => $balances['saldo_condicionado_cents'],
        ]);
    }

    public function idempotencyKeyForAssigned(int $couponUserId): string
    {
        return "credit_assigned:coupon_user:{$couponUserId}";
    }

    public function idempotencyKeyForRedeemed(int $couponTransactionId): string
    {
        return "credit_redeemed:coupon_transaction:{$couponTransactionId}";
    }

    public function idempotencyKeyForRestored(int $couponTransactionId): string
    {
        return "credit_restored:coupon_transaction:{$couponTransactionId}";
    }

    public function idempotencyKeyForRevoked(int $couponUserId): string
    {
        return "credit_revoked:coupon_user:{$couponUserId}";
    }

    public function idempotencyKeyForExpiring(int $couponUserId): string
    {
        return "credit_expiring:coupon_user:{$couponUserId}";
    }

    public function creditExpiring(CouponUser $assignment): array
    {
        $assignment->loadMissing([
            'coupon.concept',
            'coupon.parentCoupon.concept',
            'user.customer',
        ]);

        $coupon = $assignment->coupon;
        $expiresAt = $coupon?->expires_at;
        $daysToExpire = $expiresAt !== null
            ? max(0, (int) now()->startOfDay()->diffInDays($expiresAt->copy()->startOfDay(), false))
            : null;

        $user = $assignment->user;
        if ($user === null) {
            throw new \InvalidArgumentException('creditExpiring requiere usuario con email.');
        }

        $balances = $this->balanceSnapshot((int) $user->id);

        return $this->basePayload('credit_expiring', $user, [
            'coupon_id' => $coupon?->id,
            'parent_coupon_id' => $coupon?->parent_coupon_id,
            'coupon_user_id' => $assignment->id,
            'amount_cents' => (int) ($coupon?->amount_cents ?? 0),
            'remaining_cents' => (int) ($coupon?->remaining_cents ?? 0),
            'expires_at' => $expiresAt?->toIso8601String(),
            'days_to_expire' => $daysToExpire,
            'min_purchase_cents' => $coupon?->min_purchase_cents,
            'coupon_type' => $coupon?->type?->value,
            'campaign_name' => $coupon ? $this->resolveCampaignName($coupon) : null,
            'concept' => $coupon ? $this->resolveConceptLabel($coupon) : null,
            'saldo_total_cents' => $balances['saldo_total_cents'],
            'saldo_aplicable_cents' => $balances['saldo_aplicable_cents'],
            'saldo_condicionado_cents' => $balances['saldo_condicionado_cents'],
        ]);
    }

    public function idempotencyKeyForPendingCreated(int $beneficiaryId): string
    {
        return "pending_beneficiary_created:coupon_beneficiary:{$beneficiaryId}";
    }

    public function idempotencyKeyForPendingActivated(int $beneficiaryId): string
    {
        return "pending_beneficiary_activated:coupon_beneficiary:{$beneficiaryId}";
    }

    public function idempotencyKeyForPendingCancelled(int $beneficiaryId): string
    {
        return "pending_beneficiary_cancelled:coupon_beneficiary:{$beneficiaryId}";
    }

    public function idempotencyKeyForPromoValidated(int $promoRedemptionId): string
    {
        return "promo_validated:promo_redemption:{$promoRedemptionId}";
    }

    public function idempotencyKeyForPromoUsed(int $promoRedemptionId): string
    {
        return "promo_used:promo_redemption:{$promoRedemptionId}";
    }

    public function idempotencyKeyForPromoReleased(int $promoRedemptionId): string
    {
        return "promo_released:promo_redemption:{$promoRedemptionId}";
    }

    public function promoValidated(PromoRedemption $redemption): array
    {
        $redemption->loadMissing(['promoCode.coupon', 'user', 'customer']);
        $promoCode = $redemption->promoCode;
        $master = $promoCode?->coupon;
        $validationTtlMinutes = (int) config('promo_codes.validation_ttl_minutes', 15);

        return [
            'event_type' => 'promo_validated',
            'promo_code_id' => $promoCode?->id,
            'promo_redemption_id' => $redemption->id,
            'coupon_id' => $master?->id,
            'code' => $promoCode?->code,
            'promo_type' => $promoCode?->promo_type?->value,
            'user_id' => $redemption->user_id,
            'customer_id' => $redemption->customer_id,
            'email' => $redemption->user?->email,
            'discount_cents' => (int) $redemption->discount_cents,
            'min_purchase_cents' => $master?->min_purchase_cents,
            'expires_at' => $master?->expires_at?->toIso8601String(),
            'validation_expires_at' => $redemption->validated_at?->copy()
                ->addMinutes($validationTtlMinutes)
                ->toIso8601String(),
            'max_redemptions' => $promoCode?->max_redemptions,
            'max_uses_per_user' => $promoCode?->max_uses_per_user,
            'remaining_uses' => $this->remainingPromoUses($promoCode),
            'validated_at' => $redemption->validated_at?->toIso8601String(),
        ];
    }

    public function promoUsed(PromoRedemption $redemption): array
    {
        $redemption->loadMissing(['promoCode.coupon', 'user', 'customer', 'coupon']);

        $purchaseType = $redemption->purchase_type;

        return [
            'event_type' => 'promo_used',
            'promo_code_id' => $redemption->promo_code_id,
            'promo_redemption_id' => $redemption->id,
            'coupon_id' => $redemption->promoCode?->coupon_id,
            'child_coupon_id' => $redemption->coupon_id,
            'code' => $redemption->promoCode?->code,
            'promo_type' => $redemption->promoCode?->promo_type?->value,
            'user_id' => $redemption->user_id,
            'customer_id' => $redemption->customer_id,
            'email' => $redemption->user?->email,
            'discount_cents' => (int) $redemption->discount_cents,
            'purchase_type' => $purchaseType,
            'purchase_id' => $redemption->purchase_id,
            'laboratory_purchase_id' => $purchaseType === 'lab' ? $redemption->purchase_id : null,
            'confirmed_at' => $redemption->confirmed_at?->toIso8601String(),
        ];
    }

    public function promoReleased(PromoRedemption $redemption, ?string $releaseReason = null): array
    {
        $redemption->loadMissing(['promoCode', 'user', 'customer']);

        return [
            'event_type' => 'promo_released',
            'promo_code_id' => $redemption->promo_code_id,
            'promo_redemption_id' => $redemption->id,
            'code' => $redemption->promoCode?->code,
            'promo_type' => $redemption->promoCode?->promo_type?->value,
            'user_id' => $redemption->user_id,
            'customer_id' => $redemption->customer_id,
            'email' => $redemption->user?->email,
            'released_at' => $redemption->released_at?->toIso8601String(),
            'release_reason' => $releaseReason,
        ];
    }

    public function pendingBeneficiaryCreated(CouponBeneficiary $beneficiary): array
    {
        $beneficiary->loadMissing(['parentCoupon.concept']);
        $parent = $beneficiary->parentCoupon;
        $amountCents = (int) ($parent?->amount_cents ?? 0);

        return [
            'event_type' => 'pending_beneficiary_created',
            'beneficiary_id' => $beneficiary->id,
            'parent_coupon_id' => $beneficiary->parent_coupon_id,
            'email' => $beneficiary->email,
            'first_name' => $beneficiary->first_name,
            'paternal_lastname' => $beneficiary->paternal_lastname,
            'maternal_lastname' => $beneficiary->maternal_lastname,
            'last_name' => $this->formatBeneficiaryLastName($beneficiary),
            'amount_cents' => $amountCents,
            'remaining_cents' => $amountCents,
            'valid_from' => $parent?->valid_from?->toIso8601String(),
            'expires_at' => $parent?->expires_at?->toIso8601String(),
            'min_purchase_cents' => $parent?->min_purchase_cents,
            'coupon_type' => $parent?->type?->value,
            'campaign_name' => $parent ? $this->resolveCampaignName($parent) : null,
            'concept' => $parent ? $this->resolveConceptLabel($parent) : null,
            'source' => $beneficiary->source?->value ?? 'manual',
            'created_at' => ($beneficiary->created_at ?? now())->toIso8601String(),
        ];
    }

    public function pendingBeneficiaryActivated(
        CouponBeneficiary $beneficiary,
        User $user,
        Coupon $childCoupon,
        CouponUser $couponUser,
    ): array {
        $childCoupon->loadMissing(['concept', 'parentCoupon.concept']);
        $user->loadMissing('customer');
        $balances = $this->balanceSnapshot((int) $user->id);

        return $this->basePayload('pending_beneficiary_activated', $user, [
            'beneficiary_id' => $beneficiary->id,
            'parent_coupon_id' => $beneficiary->parent_coupon_id,
            'child_coupon_id' => $childCoupon->id,
            'coupon_user_id' => $couponUser->id,
            'amount_cents' => (int) $childCoupon->amount_cents,
            'remaining_cents' => (int) $childCoupon->remaining_cents,
            'valid_from' => $childCoupon->valid_from?->toIso8601String(),
            'expires_at' => $childCoupon->expires_at?->toIso8601String(),
            'min_purchase_cents' => $childCoupon->min_purchase_cents,
            'coupon_type' => $childCoupon->type?->value,
            'campaign_name' => $this->resolveCampaignName($childCoupon),
            'concept' => $this->resolveConceptLabel($childCoupon),
            'activated_at' => ($beneficiary->activated_at ?? now())->toIso8601String(),
            'saldo_total_cents' => $balances['saldo_total_cents'],
            'saldo_aplicable_cents' => $balances['saldo_aplicable_cents'],
            'saldo_condicionado_cents' => $balances['saldo_condicionado_cents'],
        ]);
    }

    public function pendingBeneficiaryCancelled(
        CouponBeneficiary $beneficiary,
        ?int $actorUserId = null,
        ?string $reason = null,
    ): array {
        $beneficiary->loadMissing(['parentCoupon.concept']);
        $parent = $beneficiary->parentCoupon;

        return [
            'event_type' => 'pending_beneficiary_cancelled',
            'beneficiary_id' => $beneficiary->id,
            'parent_coupon_id' => $beneficiary->parent_coupon_id,
            'email' => $beneficiary->email,
            'first_name' => $beneficiary->first_name,
            'paternal_lastname' => $beneficiary->paternal_lastname,
            'maternal_lastname' => $beneficiary->maternal_lastname,
            'cancelled_at' => ($beneficiary->cancelled_at ?? now())->toIso8601String(),
            'actor_user_id' => $actorUserId,
            'reason' => $reason,
            'campaign_name' => $parent ? $this->resolveCampaignName($parent) : null,
        ];
    }

    /**
     * @return array{saldo_total_cents: int, saldo_aplicable_cents: int, saldo_condicionado_cents: int}
     */
    public function balanceSnapshot(int $userId): array
    {
        $presentation = $this->couponService->buildCheckoutCreditPresentation($userId, 0);

        return [
            'saldo_total_cents' => (int) ($presentation['total_balance_cents'] ?? 0),
            'saldo_aplicable_cents' => (int) ($presentation['applicable_balance_cents'] ?? 0),
            'saldo_condicionado_cents' => (int) ($presentation['conditional_balance_cents'] ?? 0),
        ];
    }

    private function isCouponUsableAfterRestore(Coupon $coupon): bool
    {
        return $coupon->is_active
            && (int) $coupon->remaining_cents > 0
            && ! $coupon->isExpired()
            && ! $coupon->isNotYetValid();
    }

    private function formatBeneficiaryLastName(CouponBeneficiary $beneficiary): ?string
    {
        $parts = array_filter([
            $beneficiary->paternal_lastname,
            $beneficiary->maternal_lastname,
        ], fn ($value) => filled($value));

        if ($parts === []) {
            return null;
        }

        return implode(' ', $parts);
    }

    private function remainingPromoUses(?PromoCode $promoCode): ?int
    {
        if ($promoCode === null || $promoCode->max_redemptions === null) {
            return null;
        }

        return max(0, (int) $promoCode->max_redemptions - (int) $promoCode->redemptions_count);
    }

    private function resolveCampaignName(Coupon $coupon): ?string
    {
        if (filled($coupon->description)) {
            return $coupon->description;
        }

        if ($coupon->parentCoupon !== null && filled($coupon->parentCoupon->description)) {
            return $coupon->parentCoupon->description;
        }

        return null;
    }

    private function resolveConceptLabel(Coupon $coupon): ?string
    {
        if (filled($coupon->concept_other)) {
            return $coupon->concept_other;
        }

        if ($coupon->concept?->title) {
            return $coupon->concept->title;
        }

        if ($coupon->parentCoupon?->concept_other) {
            return $coupon->parentCoupon->concept_other;
        }

        return $coupon->parentCoupon?->concept?->title;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function basePayload(string $eventType, User $user, array $extra): array
    {
        $user->loadMissing('customer');

        return array_merge([
            'event_type' => $eventType,
            'user_id' => $user->id,
            'customer_id' => $user->customer?->id,
            'email' => $user->email,
        ], $extra);
    }
}
