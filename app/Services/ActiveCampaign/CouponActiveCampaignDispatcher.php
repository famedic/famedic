<?php

namespace App\Services\ActiveCampaign;

use App\Jobs\ActiveCampaign\DispatchActiveCampaignCouponEventJob;
use App\Models\ActiveCampaignDispatch;
use App\Models\Coupon;
use App\Models\CouponBeneficiary;
use App\Models\CouponTransaction;
use App\Models\CouponUser;
use App\Models\LaboratoryPurchase;
use App\Models\OnlinePharmacyPurchase;
use App\Models\PromoRedemption;
use App\Models\User;
use App\Enums\PromoRedemptionStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CouponActiveCampaignDispatcher
{
    public function __construct(
        private ActiveCampaignDispatchService $dispatchService,
        private CouponActiveCampaignPayloadBuilder $payloadBuilder,
    ) {}

    public function creditAssigned(Coupon $coupon, CouponUser $assignment, User $user, string $source): void
    {
        if (! $this->canDispatchForUser($user)) {
            return;
        }

        DB::afterCommit(function () use ($coupon, $assignment, $user, $source) {
            $this->enqueue(
                eventType: 'credit_assigned',
                idempotencyKey: $this->payloadBuilder->idempotencyKeyForAssigned((int) $assignment->id),
                entityType: 'coupon_user',
                entityId: (int) $assignment->id,
                relatedEntityType: 'coupon',
                relatedEntityId: (int) $coupon->id,
                user: $user,
                payload: $this->payloadBuilder->creditAssigned($coupon, $assignment, $user, $source),
            );
        });
    }

    public function creditRedeemed(
        Coupon $coupon,
        CouponUser $assignment,
        CouponTransaction $transaction,
        User $user,
        LaboratoryPurchase|OnlinePharmacyPurchase $purchase,
    ): void {
        if (! $this->canDispatchForUser($user)) {
            return;
        }

        DB::afterCommit(function () use ($coupon, $assignment, $transaction, $user, $purchase) {
            $this->enqueue(
                eventType: 'credit_redeemed',
                idempotencyKey: $this->payloadBuilder->idempotencyKeyForRedeemed((int) $transaction->id),
                entityType: 'coupon_transaction',
                entityId: (int) $transaction->id,
                relatedEntityType: 'coupon',
                relatedEntityId: (int) $coupon->id,
                user: $user,
                payload: $this->payloadBuilder->creditRedeemed($coupon, $assignment, $transaction, $user, $purchase),
            );
        });
    }

    public function creditRestored(
        Coupon $coupon,
        CouponUser $assignment,
        CouponTransaction $transaction,
        User $user,
        LaboratoryPurchase $purchase,
        string $reason,
    ): void {
        if (! $this->canDispatchForUser($user)) {
            return;
        }

        DB::afterCommit(function () use ($coupon, $assignment, $transaction, $user, $purchase, $reason) {
            $this->enqueue(
                eventType: 'credit_restored',
                idempotencyKey: $this->payloadBuilder->idempotencyKeyForRestored((int) $transaction->id),
                entityType: 'coupon_transaction',
                entityId: (int) $transaction->id,
                relatedEntityType: 'coupon',
                relatedEntityId: (int) $coupon->id,
                user: $user,
                payload: $this->payloadBuilder->creditRestored($coupon, $assignment, $transaction, $user, $purchase, $reason),
            );
        });
    }

    public function creditRevoked(
        Coupon $coupon,
        CouponUser $assignment,
        User $user,
        int $remainingBeforeCents,
        ?int $actorUserId,
        ?string $reason,
    ): void {
        if (! $this->canDispatchForUser($user)) {
            return;
        }

        DB::afterCommit(function () use ($coupon, $assignment, $user, $remainingBeforeCents, $actorUserId, $reason) {
            $this->enqueue(
                eventType: 'credit_revoked',
                idempotencyKey: $this->payloadBuilder->idempotencyKeyForRevoked((int) $assignment->id),
                entityType: 'coupon_user',
                entityId: (int) $assignment->id,
                relatedEntityType: 'coupon',
                relatedEntityId: (int) $coupon->id,
                user: $user,
                payload: $this->payloadBuilder->creditRevoked(
                    $coupon,
                    $assignment,
                    $user,
                    $remainingBeforeCents,
                    $actorUserId,
                    $reason,
                ),
            );
        });
    }

    public function dispatchCreditExpiring(CouponUser $assignment, bool $force = false): bool
    {
        if (! $force && ! $this->dispatchService->isCouponsExpiringEnabled()) {
            return false;
        }

        if (! $this->dispatchService->isCouponsEnabled()) {
            return false;
        }

        $resolved = $this->resolveAssignmentForDispatch($assignment);
        if (! $this->canDispatchCreditExpiring($resolved)) {
            return false;
        }

        try {
            $user = $resolved->user;
            if ($user === null) {
                return false;
            }

            $coupon = $resolved->coupon;
            if ($coupon === null) {
                return false;
            }

            $idempotencyKey = $this->payloadBuilder->idempotencyKeyForExpiring((int) $resolved->id);

            $existing = $this->dispatchService->createOrSkipByIdempotencyKey([
                'event_type' => 'credit_expiring',
                'idempotency_key' => $idempotencyKey,
                'entity_type' => 'coupon_user',
                'entity_id' => (int) $resolved->id,
                'related_entity_type' => 'coupon',
                'related_entity_id' => (int) $coupon->id,
                'user_id' => $user->id,
                'customer_id' => $user->customer?->id,
                'email' => (string) $user->email,
                'payload' => $this->payloadBuilder->creditExpiring($resolved),
            ]);

            if (! $existing->wasRecentlyCreated || $existing->status !== ActiveCampaignDispatch::STATUS_PENDING) {
                return false;
            }

            DispatchActiveCampaignCouponEventJob::dispatch($existing->id);

            return true;
        } catch (Throwable $e) {
            Log::warning('AC Credit expiring: fallo al encolar dispatch (no afecta dominio)', [
                'coupon_user_id' => $assignment->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function pendingBeneficiaryCreated(CouponBeneficiary $beneficiary): void
    {
        if (! $this->canDispatchPendingBeneficiaryCreated($beneficiary)) {
            return;
        }

        DB::afterCommit(function () use ($beneficiary) {
            try {
                $fresh = $this->resolveBeneficiaryForDispatch($beneficiary, ['parentCoupon.concept']);
                if (! $this->canDispatchPendingBeneficiaryCreated($fresh)) {
                    return;
                }

                $this->enqueueBeneficiary(
                    eventType: 'pending_beneficiary_created',
                    idempotencyKey: $this->payloadBuilder->idempotencyKeyForPendingCreated((int) $fresh->id),
                    entityType: 'coupon_beneficiary',
                    entityId: (int) $fresh->id,
                    relatedEntityType: 'coupon',
                    relatedEntityId: (int) $fresh->parent_coupon_id,
                    email: (string) $fresh->email,
                    userId: null,
                    customerId: null,
                    payload: $this->payloadBuilder->pendingBeneficiaryCreated($fresh),
                );
            } catch (Throwable $e) {
                $this->logBeneficiaryDispatchFailure('pending_beneficiary_created', $beneficiary->id, $e);
            }
        });
    }

    public function pendingBeneficiaryActivated(
        CouponBeneficiary $beneficiary,
        User $user,
        Coupon $childCoupon,
        CouponUser $couponUser,
    ): void {
        if (! $this->canDispatchForUser($user)) {
            return;
        }

        DB::afterCommit(function () use ($beneficiary, $user, $childCoupon, $couponUser) {
            try {
                $fresh = $this->resolveBeneficiaryForDispatch($beneficiary);
                if (! $fresh->isAssigned() || $fresh->child_coupon_id === null) {
                    return;
                }

                $this->enqueueBeneficiary(
                    eventType: 'pending_beneficiary_activated',
                    idempotencyKey: $this->payloadBuilder->idempotencyKeyForPendingActivated((int) $fresh->id),
                    entityType: 'coupon_beneficiary',
                    entityId: (int) $fresh->id,
                    relatedEntityType: 'coupon',
                    relatedEntityId: (int) $childCoupon->id,
                    email: (string) $user->email,
                    userId: (int) $user->id,
                    customerId: $user->customer?->id,
                    payload: $this->payloadBuilder->pendingBeneficiaryActivated($fresh, $user, $childCoupon, $couponUser),
                );
            } catch (Throwable $e) {
                $this->logBeneficiaryDispatchFailure('pending_beneficiary_activated', $beneficiary->id, $e);
            }
        });
    }

    public function pendingBeneficiaryCancelled(
        CouponBeneficiary $beneficiary,
        ?int $actorUserId = null,
        ?string $reason = null,
    ): void {
        if (! $this->dispatchService->isCouponsEnabled()) {
            return;
        }

        $email = $beneficiary->email;
        if (! is_string($email) || trim($email) === '' || ! filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            return;
        }

        DB::afterCommit(function () use ($beneficiary, $actorUserId, $reason) {
            try {
                $fresh = $this->resolveBeneficiaryForDispatch($beneficiary, ['parentCoupon.concept']);
                if (! $fresh->isCancelled()) {
                    return;
                }

                $this->enqueueBeneficiary(
                    eventType: 'pending_beneficiary_cancelled',
                    idempotencyKey: $this->payloadBuilder->idempotencyKeyForPendingCancelled((int) $fresh->id),
                    entityType: 'coupon_beneficiary',
                    entityId: (int) $fresh->id,
                    relatedEntityType: 'coupon',
                    relatedEntityId: (int) $fresh->parent_coupon_id,
                    email: (string) $fresh->email,
                    userId: null,
                    customerId: null,
                    payload: $this->payloadBuilder->pendingBeneficiaryCancelled($fresh, $actorUserId, $reason),
                );
            } catch (Throwable $e) {
                $this->logBeneficiaryDispatchFailure('pending_beneficiary_cancelled', $beneficiary->id, $e);
            }
        });
    }

    public function promoValidated(PromoRedemption $redemption): void
    {
        if (! $this->canDispatchForPromoRedemption($redemption)) {
            return;
        }

        DB::afterCommit(function () use ($redemption) {
            try {
                $fresh = $this->resolvePromoRedemptionForDispatch($redemption);
                if ($fresh->status !== PromoRedemptionStatus::Validated) {
                    return;
                }

                $email = (string) ($fresh->user?->email ?? '');
                $this->enqueueBeneficiary(
                    eventType: 'promo_validated',
                    idempotencyKey: $this->payloadBuilder->idempotencyKeyForPromoValidated((int) $fresh->id),
                    entityType: 'promo_redemption',
                    entityId: (int) $fresh->id,
                    relatedEntityType: 'promo_code',
                    relatedEntityId: (int) $fresh->promo_code_id,
                    email: $email,
                    userId: $fresh->user_id,
                    customerId: $fresh->customer_id,
                    payload: $this->payloadBuilder->promoValidated($fresh),
                );
            } catch (Throwable $e) {
                $this->logPromoDispatchFailure('promo_validated', $redemption->id, $e);
            }
        });
    }

    public function promoUsed(PromoRedemption $redemption): void
    {
        if (! $this->canDispatchForPromoRedemption($redemption)) {
            return;
        }

        DB::afterCommit(function () use ($redemption) {
            try {
                $fresh = $this->resolvePromoRedemptionForDispatch($redemption);
                if ($fresh->status !== PromoRedemptionStatus::Confirmed) {
                    return;
                }

                $email = (string) ($fresh->user?->email ?? '');
                $this->enqueueBeneficiary(
                    eventType: 'promo_used',
                    idempotencyKey: $this->payloadBuilder->idempotencyKeyForPromoUsed((int) $fresh->id),
                    entityType: 'promo_redemption',
                    entityId: (int) $fresh->id,
                    relatedEntityType: 'promo_code',
                    relatedEntityId: (int) $fresh->promo_code_id,
                    email: $email,
                    userId: $fresh->user_id,
                    customerId: $fresh->customer_id,
                    payload: $this->payloadBuilder->promoUsed($fresh),
                );
            } catch (Throwable $e) {
                $this->logPromoDispatchFailure('promo_used', $redemption->id, $e);
            }
        });
    }

    public function promoReleased(PromoRedemption $redemption, ?string $releaseReason = null): void
    {
        if (! $this->canDispatchForPromoRedemption($redemption)) {
            return;
        }

        DB::afterCommit(function () use ($redemption, $releaseReason) {
            try {
                $fresh = $this->resolvePromoRedemptionForDispatch($redemption);
                if ($fresh->status !== PromoRedemptionStatus::Released) {
                    return;
                }

                $email = (string) ($fresh->user?->email ?? '');
                $this->enqueueBeneficiary(
                    eventType: 'promo_released',
                    idempotencyKey: $this->payloadBuilder->idempotencyKeyForPromoReleased((int) $fresh->id),
                    entityType: 'promo_redemption',
                    entityId: (int) $fresh->id,
                    relatedEntityType: 'promo_code',
                    relatedEntityId: (int) $fresh->promo_code_id,
                    email: $email,
                    userId: $fresh->user_id,
                    customerId: $fresh->customer_id,
                    payload: $this->payloadBuilder->promoReleased($fresh, $releaseReason),
                );
            } catch (Throwable $e) {
                $this->logPromoDispatchFailure('promo_released', $redemption->id, $e);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function enqueue(
        string $eventType,
        string $idempotencyKey,
        string $entityType,
        ?int $entityId,
        ?string $relatedEntityType,
        ?int $relatedEntityId,
        User $user,
        array $payload,
    ): void {
        try {
            if (! $this->dispatchService->isCouponsEnabled()) {
                return;
            }

            $email = $user->email;
            if (! is_string($email) || trim($email) === '') {
                return;
            }

            $this->enqueueBeneficiary(
                eventType: $eventType,
                idempotencyKey: $idempotencyKey,
                entityType: $entityType,
                entityId: $entityId,
                relatedEntityType: $relatedEntityType,
                relatedEntityId: $relatedEntityId,
                email: $email,
                userId: $user->id,
                customerId: $user->customer?->id,
                payload: $payload,
            );
        } catch (Throwable $e) {
            Log::warning('AC Coupon: fallo al encolar dispatch (no afecta dominio)', [
                'event_type' => $eventType,
                'idempotency_key' => $idempotencyKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function enqueueBeneficiary(
        string $eventType,
        string $idempotencyKey,
        string $entityType,
        ?int $entityId,
        ?string $relatedEntityType,
        ?int $relatedEntityId,
        string $email,
        ?int $userId,
        ?int $customerId,
        array $payload,
    ): void {
        if (! $this->dispatchService->isCouponsEnabled()) {
            return;
        }

        if (trim($email) === '') {
            return;
        }

        $dispatch = $this->dispatchService->createOrSkipByIdempotencyKey([
            'event_type' => $eventType,
            'idempotency_key' => $idempotencyKey,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'related_entity_type' => $relatedEntityType,
            'related_entity_id' => $relatedEntityId,
            'user_id' => $userId,
            'customer_id' => $customerId,
            'email' => $email,
            'payload' => $payload,
        ]);

        if (! $dispatch->wasRecentlyCreated || $dispatch->status !== ActiveCampaignDispatch::STATUS_PENDING) {
            return;
        }

        DispatchActiveCampaignCouponEventJob::dispatch($dispatch->id);
    }

    private function canDispatchPendingBeneficiaryCreated(CouponBeneficiary $beneficiary): bool
    {
        if (! $this->dispatchService->isCouponsEnabled()) {
            return false;
        }

        if (! $beneficiary->isPendingUser()) {
            return false;
        }

        if ($beneficiary->user_id !== null || $beneficiary->child_coupon_id !== null) {
            return false;
        }

        if ($beneficiary->cancelled_at !== null || $beneficiary->isCancelled()) {
            return false;
        }

        $email = $beneficiary->email;

        return is_string($email)
            && trim($email) !== ''
            && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
    }

    private function logBeneficiaryDispatchFailure(string $eventType, int $beneficiaryId, Throwable $e): void
    {
        Log::warning('AC Beneficiary: fallo al encolar dispatch (no afecta dominio)', [
            'event_type' => $eventType,
            'beneficiary_id' => $beneficiaryId,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * @param  list<string>  $relations
     */
    private function resolveBeneficiaryForDispatch(CouponBeneficiary $beneficiary, array $relations = []): CouponBeneficiary
    {
        $resolved = $beneficiary->exists
            ? ($beneficiary->fresh($relations) ?? $beneficiary)
            : $beneficiary;

        if ($relations !== []) {
            $resolved->loadMissing($relations);
        }

        return $resolved;
    }

    private function resolvePromoRedemptionForDispatch(PromoRedemption $redemption): PromoRedemption
    {
        $relations = ['promoCode.coupon', 'user', 'customer', 'coupon'];

        $resolved = $redemption->exists
            ? ($redemption->fresh($relations) ?? $redemption)
            : $redemption;

        $resolved->loadMissing($relations);

        return $resolved;
    }

    private function canDispatchForPromoRedemption(PromoRedemption $redemption): bool
    {
        if (! $this->dispatchService->isCouponsEnabled()) {
            return false;
        }

        $email = $redemption->user?->email;

        return is_string($email)
            && trim($email) !== ''
            && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
    }

    private function logPromoDispatchFailure(string $eventType, int $redemptionId, Throwable $e): void
    {
        Log::warning('AC Promo: fallo al encolar dispatch (no afecta dominio)', [
            'event_type' => $eventType,
            'promo_redemption_id' => $redemptionId,
            'error' => $e->getMessage(),
        ]);
    }

    private function canDispatchForUser(User $user): bool
    {
        if (! $this->dispatchService->isCouponsEnabled()) {
            return false;
        }

        if ($user->id === null) {
            return false;
        }

        $email = $user->email;

        return is_string($email) && trim($email) !== '';
    }

    private function canDispatchCreditExpiring(CouponUser $assignment): bool
    {
        if (! $this->dispatchService->isCouponsEnabled()) {
            return false;
        }

        $user = $assignment->user;
        if ($user === null || ! $this->canDispatchForUser($user)) {
            return false;
        }

        return app(ExpiringCouponCandidateQuery::class)->isEligible($assignment);
    }

    /**
     * @param  list<string>  $relations
     */
    private function resolveAssignmentForDispatch(CouponUser $assignment, array $relations = []): CouponUser
    {
        $defaultRelations = [
            'coupon.concept',
            'coupon.parentCoupon.concept',
            'user.customer',
        ];
        $relations = $relations === [] ? $defaultRelations : $relations;

        $resolved = $assignment->exists
            ? ($assignment->fresh($relations) ?? $assignment)
            : $assignment;

        $resolved->loadMissing($relations);

        return $resolved;
    }
}
