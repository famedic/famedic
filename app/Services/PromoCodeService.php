<?php

namespace App\Services;

use App\Enums\CouponApprovalStatus;
use App\Enums\CouponPurchaseType;
use App\Enums\CouponType;
use App\Enums\PromoRedemptionStatus;
use App\Enums\PromoType;
use App\Exceptions\PromoCodeException;
use App\Mail\CouponAuthorizationRequestedMail;
use App\Models\Coupon;
use App\Models\CouponAdminSettings;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PromoCodeService
{
    public function __construct(
        private CouponApplicationService $couponApplicationService,
        private CouponService $couponService,
        private CouponEligibilityFormService $couponEligibilityFormService,
    ) {
    }

    /**
     * @return array{
     *     valid: bool,
     *     discount_cents: int,
     *     message: string,
     *     validation_token: string,
     *     expires_in: int,
     *     benefit_label: string,
     *     remaining_uses: ?int
     * }
     */
    public function validateForCheckout(
        User $user,
        Customer $customer,
        string $rawCode,
        int $cartTotalCents,
        string $cartHash,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $code = PromoCode::normalizeCode($rawCode);
        if ($code === '') {
            throw new PromoCodeException('Código inválido.');
        }

        $promoCode = PromoCode::query()
            ->with('coupon')
            ->where('code', $code)
            ->first();

        if ($promoCode === null || ! $promoCode->is_active) {
            throw new PromoCodeException('Código inválido.');
        }

        $master = $promoCode->coupon;
        if ($master === null) {
            throw new PromoCodeException('Código inválido.');
        }

        $this->assertSharedPromoEligible($promoCode, $user, $cartTotalCents);
        $discountCents = $this->previewDiscountCents($master, $cartTotalCents);

        if ($discountCents <= 0) {
            throw new PromoCodeException('El código no aplica al carrito actual.');
        }

        return DB::transaction(function () use (
            $promoCode,
            $user,
            $customer,
            $cartTotalCents,
            $cartHash,
            $discountCents,
            $master,
            $ipAddress,
            $userAgent,
        ) {
            $promoCode = PromoCode::query()->lockForUpdate()->findOrFail($promoCode->id);
            $this->assertSharedPromoEligible($promoCode, $user, $cartTotalCents);

            $this->releaseStaleValidations($promoCode, $user);

            $token = Str::random(48);

            $redemption = PromoRedemption::create([
                'promo_code_id' => $promoCode->id,
                'user_id' => $user->id,
                'customer_id' => $customer->id,
                'status' => PromoRedemptionStatus::Validated,
                'discount_cents' => $discountCents,
                'validation_token' => $token,
                'cart_hash' => $cartHash,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'validated_at' => now(),
            ]);

            $this->dispatchPromoValidated($redemption);

            $remainingUses = $this->remainingUses($promoCode);

            return [
                'valid' => true,
                'discount_cents' => $discountCents,
                'message' => 'Código aplicado correctamente.',
                'validation_token' => $token,
                'expires_in' => (int) config('promo_codes.validation_ttl_minutes', 15) * 60,
                'benefit_label' => $this->benefitLabel($master, $discountCents),
                'remaining_uses' => $remainingUses,
            ];
        });
    }

    public function clearValidation(User $user, string $validationToken): void
    {
        $redemption = PromoRedemption::query()
            ->where('validation_token', $validationToken)
            ->where('user_id', $user->id)
            ->where('status', PromoRedemptionStatus::Validated)
            ->first();

        if ($redemption === null) {
            return;
        }

        $redemption->update([
            'status' => PromoRedemptionStatus::Released,
            'released_at' => now(),
        ]);

        $this->dispatchPromoReleased($redemption->fresh(['user']), 'user_cleared');
    }

    /**
     * Revalida token antes del cobro.
     *
     * @throws PromoCodeException
     */
    public function resolveValidatedRedemption(
        User $user,
        string $validationToken,
        int $cartTotalCents,
        string $cartHash,
    ): PromoRedemption {
        $redemption = PromoRedemption::query()
            ->with(['promoCode.coupon'])
            ->where('validation_token', $validationToken)
            ->where('user_id', $user->id)
            ->first();

        if ($redemption === null) {
            throw new PromoCodeException('Código inválido.');
        }

        if ($redemption->status === PromoRedemptionStatus::Confirmed) {
            return $redemption;
        }

        if ($redemption->status !== PromoRedemptionStatus::Validated) {
            throw new PromoCodeException('Código inválido.');
        }

        if ($redemption->isValidationExpired()) {
            $redemption->update([
                'status' => PromoRedemptionStatus::Released,
                'released_at' => now(),
            ]);

            $this->dispatchPromoReleased($redemption->fresh(['user']), 'validation_expired');

            throw new PromoCodeException('El código expiró. Vuelve a validarlo.');
        }

        if (! hash_equals($redemption->cart_hash, $cartHash)) {
            throw new PromoCodeException('No aplica al carrito actual.');
        }

        $promoCode = $redemption->promoCode;
        if ($promoCode === null) {
            throw new PromoCodeException('Código inválido.');
        }

        $this->assertSharedPromoEligible($promoCode, $user, $cartTotalCents);

        $expectedDiscount = $this->previewDiscountCents($promoCode->coupon, $cartTotalCents);
        if ($expectedDiscount !== (int) $redemption->discount_cents) {
            throw new PromoCodeException('No aplica al carrito actual.');
        }

        return $redemption;
    }

    /**
     * Confirma redención tras pago exitoso: crea cupón hijo y aplica descuento.
     *
     * @return int Monto descontado en centavos
     */
    public function confirmRedemption(
        User $user,
        string $validationToken,
        LaboratoryPurchase $purchase,
        int $cartTotalCents,
        string $cartHash,
    ): int {
        return DB::transaction(function () use ($user, $validationToken, $purchase, $cartTotalCents, $cartHash) {
            $redemption = PromoRedemption::query()
                ->where('validation_token', $validationToken)
                ->lockForUpdate()
                ->first();

            if ($redemption === null) {
                throw new PromoCodeException('Código inválido.');
            }

            if ($redemption->status === PromoRedemptionStatus::Confirmed) {
                if (
                    $redemption->purchase_type === CouponPurchaseType::Lab->value
                    && (int) $redemption->purchase_id === (int) $purchase->id
                ) {
                    return (int) $redemption->discount_cents;
                }

                throw new PromoCodeException('Este código ya fue utilizado.');
            }

            if ((int) $redemption->user_id !== (int) $user->id) {
                throw new PromoCodeException('Código no aplicable a esta cuenta.');
            }

            if (! hash_equals($redemption->cart_hash, $cartHash)) {
                throw new PromoCodeException('No aplica al carrito actual.');
            }

            if ($redemption->isValidationExpired() && $redemption->status === PromoRedemptionStatus::Validated) {
                $redemption->update([
                    'status' => PromoRedemptionStatus::Released,
                    'released_at' => now(),
                ]);

                $this->dispatchPromoReleased($redemption->fresh(['user']), 'validation_expired');

                throw new PromoCodeException('El código expiró. Vuelve a validarlo.');
            }

            $promoCode = PromoCode::query()
                ->with('coupon')
                ->lockForUpdate()
                ->findOrFail($redemption->promo_code_id);

            $this->assertSharedPromoEligible($promoCode, $user, $cartTotalCents);

            $discountCents = $this->previewDiscountCents($promoCode->coupon, $cartTotalCents);
            if ($discountCents !== (int) $redemption->discount_cents) {
                throw new PromoCodeException('No aplica al carrito actual.');
            }

            if (! $promoCode->hasRemainingCapacity()) {
                throw new PromoCodeException('Código agotado.');
            }

            $capacityQuery = PromoCode::query()->whereKey($promoCode->id);
            if ($promoCode->max_redemptions !== null) {
                $capacityQuery->where('redemptions_count', '<', $promoCode->max_redemptions);
            }

            $updated = $capacityQuery->update([
                'redemptions_count' => DB::raw('redemptions_count + 1'),
            ]);

            if ($updated === 0) {
                throw new PromoCodeException('Código agotado.');
            }

            $childCoupon = $this->couponService->createCampaignChildAssignment(
                $user,
                $promoCode->coupon,
                sendNotification: false,
                skipActiveCampaignCreditAssigned: true,
            );

            $appliedCents = $this->couponApplicationService->applyForLaboratoryPurchase(
                $user,
                $purchase,
                $childCoupon->id,
                skipActiveCampaignCreditRedeemed: true,
            );

            $redemption->update([
                'status' => PromoRedemptionStatus::Confirmed,
                'coupon_id' => $childCoupon->id,
                'purchase_type' => CouponPurchaseType::Lab->value,
                'purchase_id' => $purchase->id,
                'discount_cents' => $appliedCents,
                'confirmed_at' => now(),
            ]);

            $this->dispatchPromoUsed($redemption->fresh(['user', 'promoCode']));

            return $appliedCents;
        });
    }

    public function buildLaboratoryCartHash(Collection $cartItems, int $cartTotalCents): string
    {
        $parts = $cartItems
            ->sortBy('id')
            ->map(fn ($item) => $item->id.':'.$item->laboratory_test_id)
            ->implode('|');

        return hash('sha256', $parts.'|'.$cartTotalCents);
    }

    public function previewDiscountCents(Coupon $master, int $cartTotalCents): int
    {
        if ($master->type !== CouponType::Coupon) {
            return 0;
        }

        return $this->couponApplicationService->resolveDiscountCents(
            $this->templateCoupon($master),
            $cartTotalCents
        );
    }

    public function createSharedPromoCode(
        Coupon $masterCoupon,
        string $code,
        ?int $maxRedemptions = null,
        int $maxUsesPerUser = 1,
        ?int $createdByUserId = null,
        bool $isActive = true,
    ): PromoCode {
        $normalized = PromoCode::normalizeCode($code);
        if ($normalized === '') {
            throw new PromoCodeException('El código promocional no puede estar vacío.');
        }

        if (PromoCode::query()->where('code', $normalized)->exists()) {
            throw new PromoCodeException('Ya existe un código promocional con ese texto.');
        }

        if ($masterCoupon->type !== CouponType::Coupon) {
            throw new PromoCodeException('Los códigos promocionales compartidos requieren un cupón maestro de tipo descuento.');
        }

        return PromoCode::create([
            'coupon_id' => $masterCoupon->id,
            'code' => $normalized,
            'promo_type' => PromoType::Shared,
            'max_redemptions' => $maxRedemptions,
            'max_uses_per_user' => max(1, $maxUsesPerUser),
            'created_by_user_id' => $createdByUserId,
            'is_active' => $isActive,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createSharedPromoFromAdmin(array $data, User $actor): PromoCode
    {
        return DB::transaction(function () use ($data, $actor) {
            if (! empty($data['auto_generate_code'])) {
                $code = $this->generateUniqueCode();
            } else {
                $code = PromoCode::normalizeCode((string) ($data['code'] ?? ''));
                if (PromoCode::query()->where('code', $code)->exists()) {
                    throw new PromoCodeException('Ya existe un código promocional con ese texto.');
                }
            }

            if ($code === '') {
                throw new PromoCodeException('El código promocional no puede estar vacío.');
            }

            $master = $this->createMasterCouponForSharedPromo($data, $actor);

            return $this->createSharedPromoCode(
                $master,
                $code,
                (int) $data['max_redemptions'],
                (int) $data['max_uses_per_user'],
                $actor->id,
                filter_var($data['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
            );
        });
    }

    public function deactivate(PromoCode $promoCode): PromoCode
    {
        $promoCode->update(['is_active' => false]);

        return $promoCode->fresh(['coupon']);
    }

    public function generateUniqueCode(): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) config('promo_codes.code_prefix', 'FAM'))) ?: 'FAM';
        $segmentLength = max(3, min(8, (int) config('promo_codes.code_segment_length', 4)));
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $segment = '';
            for ($i = 0; $i < $segmentLength; $i++) {
                $segment .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $candidate = PromoCode::normalizeCode("{$prefix}-{$segment}");
            if ($candidate !== '' && ! PromoCode::query()->where('code', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new PromoCodeException('No se pudo generar un código único. Intenta de nuevo.');
    }

    public function isCodeAvailable(string $rawCode): bool
    {
        $normalized = PromoCode::normalizeCode($rawCode);

        return $normalized !== '' && ! PromoCode::query()->where('code', $normalized)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function presentForAdminIndex(PromoCode $promoCode): array
    {
        $promoCode->loadMissing('coupon');

        $remaining = $this->remainingUses($promoCode);

        return [
            'id' => $promoCode->id,
            'code' => $promoCode->code,
            'description' => $promoCode->coupon?->description,
            'amount_cents' => (int) ($promoCode->coupon?->amount_cents ?? 0),
            'formatted_amount' => formattedCents((int) ($promoCode->coupon?->amount_cents ?? 0)),
            'valid_from' => $promoCode->coupon?->valid_from?->toIso8601String(),
            'expires_at' => $promoCode->coupon?->expires_at?->toIso8601String(),
            'validity_status' => $promoCode->coupon?->validity_status ?? 'sin_vigencia',
            'redemptions_count' => (int) $promoCode->redemptions_count,
            'max_redemptions' => $promoCode->max_redemptions,
            'remaining_uses' => $remaining,
            'max_uses_per_user' => (int) $promoCode->max_uses_per_user,
            'is_active' => (bool) $promoCode->is_active,
            'master_coupon_active' => (bool) ($promoCode->coupon?->is_active ?? false),
            'master_approval_status' => $promoCode->coupon?->approval_status?->value,
            'created_at' => $promoCode->created_at?->toIso8601String(),
            'shareable_message' => $this->shareableMessage($promoCode),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentForAdminShow(PromoCode $promoCode): array
    {
        $base = $this->presentForAdminIndex($promoCode);
        $promoCode->loadMissing(['coupon', 'createdBy', 'redemptions.user', 'redemptions.customer', 'redemptions.coupon']);

        $base['coupon'] = $promoCode->coupon ? [
            'id' => $promoCode->coupon->id,
            'description' => $promoCode->coupon->description,
            'amount_cents' => (int) $promoCode->coupon->amount_cents,
            'valid_from' => $promoCode->coupon->valid_from?->toIso8601String(),
            'expires_at' => $promoCode->coupon->expires_at?->toIso8601String(),
            'validity_status' => $promoCode->coupon->validity_status,
            'min_purchase_cents' => $promoCode->coupon->min_purchase_cents,
            'formatted_min_purchase' => $promoCode->coupon->formatted_min_purchase,
            'approval_status' => $promoCode->coupon->approval_status?->value,
            'is_active' => (bool) $promoCode->coupon->is_active,
        ] : null;

        $base['created_by'] = $promoCode->createdBy ? [
            'id' => $promoCode->createdBy->id,
            'name' => trim($promoCode->createdBy->full_name ?? $promoCode->createdBy->email),
            'email' => $promoCode->createdBy->email,
        ] : null;

        $base['redemptions'] = $promoCode->redemptions
            ->sortByDesc('id')
            ->values()
            ->map(fn (PromoRedemption $redemption) => [
                'id' => $redemption->id,
                'status' => $redemption->status->value,
                'discount_cents' => (int) $redemption->discount_cents,
                'formatted_discount' => formattedCents((int) $redemption->discount_cents),
                'purchase_type' => $redemption->purchase_type,
                'purchase_id' => $redemption->purchase_id,
                'coupon_id' => $redemption->coupon_id,
                'user' => $redemption->user ? [
                    'id' => $redemption->user->id,
                    'email' => $redemption->user->email,
                    'name' => trim($redemption->user->full_name ?? ''),
                ] : null,
                'customer_id' => $redemption->customer_id,
                'validated_at' => $redemption->validated_at?->toIso8601String(),
                'confirmed_at' => $redemption->confirmed_at?->toIso8601String(),
            ])
            ->all();

        return $base;
    }

    public function shareableMessage(PromoCode $promoCode): string
    {
        $promoCode->loadMissing('coupon');
        $amount = formattedCents((int) ($promoCode->coupon?->amount_cents ?? 0));
        $expires = $promoCode->coupon?->expires_at
            ? localizedDate($promoCode->coupon->expires_at)?->isoFormat('D/MM/YYYY')
            : null;

        $message = "Usa el código {$promoCode->code} en tu checkout de Famedic para obtener {$amount} de descuento.";

        if ($expires) {
            $message .= " Vigente hasta {$expires}.";
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createMasterCouponForSharedPromo(array $data, User $actor): Coupon
    {
        $adminSettings = CouponAdminSettings::singleton();

        if ($adminSettings->max_assignment_amount_cents !== null
            && (int) $data['amount_cents'] > $adminSettings->max_assignment_amount_cents) {
            throw new PromoCodeException('El monto supera el máximo configurado en las reglas de cupones.');
        }

        $this->couponService->assertAssignmentRules((int) $data['amount_cents']);

        $pending = (bool) $adminSettings->require_authorization;
        if ($pending && trim((string) $adminSettings->authorization_email) === '') {
            throw new PromoCodeException('Configura el correo del autorizador en Reglas de cupones antes de exigir autorización.');
        }

        $eligibilityAttributes = $this->couponEligibilityFormService->resolveAttributes($data);

        $coupon = Coupon::create(array_merge([
            'code' => null,
            'description' => $data['description'] ?? null,
            'coupon_concept_id' => null,
            'concept_other' => null,
            'amount_cents' => (int) $data['amount_cents'],
            'remaining_cents' => $pending ? 0 : (int) $data['amount_cents'],
            'max_beneficiaries' => null,
            'type' => CouponType::Coupon,
            'is_active' => $pending ? false : filter_var($data['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'approval_status' => $pending ? CouponApprovalStatus::PendingAuthorization : CouponApprovalStatus::Active,
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
        ], $eligibilityAttributes));

        if ($pending) {
            $plain = (string) random_int(100000, 999999);
            $coupon->authorization_code_hash = Hash::make($plain);
            $coupon->authorization_code_expires_at = now()->addDays(7);
            $coupon->save();

            $email = trim((string) $adminSettings->authorization_email);
            try {
                Mail::to($email)->send(new CouponAuthorizationRequestedMail($coupon, $plain));
            } catch (\Throwable $e) {
                Log::error('promo_master_coupon_authorization_mail_failed', [
                    'coupon_id' => $coupon->id,
                    'error' => $e->getMessage(),
                ]);

                throw new PromoCodeException('No se pudo enviar el correo de autorización del cupón maestro.');
            }
        }

        return $coupon->fresh();
    }

    private function templateCoupon(Coupon $master): Coupon
    {
        $template = $master->replicate();
        $template->remaining_cents = $master->amount_cents;

        return $template;
    }

    private function assertSharedPromoEligible(PromoCode $promoCode, User $user, int $cartTotalCents): void
    {
        if (! $promoCode->is_active) {
            throw new PromoCodeException('Código inválido.');
        }

        if (! $promoCode->hasRemainingCapacity()) {
            throw new PromoCodeException('Código agotado.');
        }

        $master = $promoCode->coupon;
        if ($master === null || ! $master->is_active) {
            throw new PromoCodeException('Código inválido.');
        }

        if ($master->approval_status !== CouponApprovalStatus::Active) {
            throw new PromoCodeException('Código inválido.');
        }

        if ($master->type !== CouponType::Coupon) {
            throw new PromoCodeException('Código inválido.');
        }

        if ($master->isNotYetValid()) {
            throw new PromoCodeException('Código inválido.');
        }

        if ($master->isExpired()) {
            throw new PromoCodeException('Código expirado.');
        }

        if (! $master->meetsMinimumPurchase($cartTotalCents)) {
            throw new PromoCodeException('No cumple compra mínima.');
        }

        $confirmedUses = PromoRedemption::query()
            ->where('promo_code_id', $promoCode->id)
            ->where('user_id', $user->id)
            ->where('status', PromoRedemptionStatus::Confirmed)
            ->count();

        if ($confirmedUses >= $promoCode->max_uses_per_user) {
            throw new PromoCodeException('Código ya usado.');
        }
    }

    private function releaseStaleValidations(PromoCode $promoCode, User $user): void
    {
        $stale = PromoRedemption::query()
            ->with('user')
            ->where('promo_code_id', $promoCode->id)
            ->where('user_id', $user->id)
            ->where('status', PromoRedemptionStatus::Validated)
            ->get();

        foreach ($stale as $redemption) {
            $redemption->update([
                'status' => PromoRedemptionStatus::Released,
                'released_at' => now(),
            ]);

            $this->dispatchPromoReleased($redemption->fresh(['user']), 'superseded_by_new_validation');
        }
    }

    private function remainingUses(PromoCode $promoCode): ?int
    {
        if ($promoCode->max_redemptions === null) {
            return null;
        }

        return max(0, $promoCode->max_redemptions - $promoCode->redemptions_count);
    }

    private function benefitLabel(Coupon $master, int $discountCents): string
    {
        return 'Descuento promocional: '.formattedCents($discountCents);
    }

    private function dispatchPromoValidated(PromoRedemption $redemption): void
    {
        try {
            app(\App\Services\ActiveCampaign\CouponActiveCampaignDispatcher::class)->promoValidated($redemption);
        } catch (\Throwable $e) {
            Log::warning('AC: fallo al encolar promo_validated', [
                'promo_redemption_id' => $redemption->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function dispatchPromoUsed(PromoRedemption $redemption): void
    {
        try {
            app(\App\Services\ActiveCampaign\CouponActiveCampaignDispatcher::class)->promoUsed($redemption);
        } catch (\Throwable $e) {
            Log::warning('AC: fallo al encolar promo_used', [
                'promo_redemption_id' => $redemption->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function dispatchPromoReleased(PromoRedemption $redemption, string $reason): void
    {
        try {
            app(\App\Services\ActiveCampaign\CouponActiveCampaignDispatcher::class)->promoReleased($redemption, $reason);
        } catch (\Throwable $e) {
            Log::warning('AC: fallo al encolar promo_released', [
                'promo_redemption_id' => $redemption->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
