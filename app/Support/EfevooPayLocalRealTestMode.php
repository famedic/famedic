<?php

namespace App\Support;

use App\Models\User;

class EfevooPayLocalRealTestMode
{
    public static function enabled(): bool
    {
        if (! app()->environment('local')) {
            return false;
        }

        return filter_var(config('efevoopay.local_real_tests.enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function activeFor(?User $user = null): bool
    {
        if (! self::enabled() || ! EfevooPayGatewayMode::usesHttpGateway()) {
            return false;
        }

        $user ??= auth()->user();

        if (! $user) {
            return false;
        }

        return self::userIsAllowed($user);
    }

    public static function userIsAllowed(User $user): bool
    {
        $allowedUserId = (int) config('efevoopay.local_real_tests.allowed_user_id', 0);
        if ($allowedUserId > 0 && (int) $user->id === $allowedUserId) {
            return true;
        }

        $allowedEmail = strtolower(trim((string) config('efevoopay.local_real_tests.allowed_user_email', '')));
        if ($allowedEmail !== '' && strtolower(trim((string) $user->email)) === $allowedEmail) {
            return true;
        }

        return false;
    }

    public static function blocksExternalIntegrations(): bool
    {
        return self::enabled() && EfevooPayGatewayMode::usesHttpGateway();
    }

    public static function maxCardVerificationTotalCents(): int
    {
        return max(1, (int) config('efevoopay.local_real_tests.max_card_verification_total_cents', 300));
    }

    public static function maxPaymentAmountCents(): int
    {
        return max(1, (int) config('efevoopay.local_real_tests.max_payment_amount_cents', 1000));
    }

    /**
     * @return array{allowed: bool, reason: string|null}
     */
    public static function validateCardVerificationAmounts(): array
    {
        $getLinkCents = (int) config('efevoopay.three_ds_verification_amount_cents', 150);
        $tokenizeCents = (int) config('efevoopay.tokenization_verification_amount_cents', 150);
        $maxTotal = self::maxCardVerificationTotalCents();

        if ($getLinkCents <= 0 || $tokenizeCents <= 0) {
            return ['allowed' => false, 'reason' => 'verification_amount_invalid'];
        }

        if (($getLinkCents + $tokenizeCents) > $maxTotal) {
            return ['allowed' => false, 'reason' => 'verification_total_exceeds_limit'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * @return array{allowed: bool, reason: string|null}
     */
    public static function validatePaymentAmountCents(int $amountCents, ?int $expectedOrderTotalCents = null): array
    {
        if ($amountCents <= 0) {
            return ['allowed' => false, 'reason' => 'payment_amount_invalid'];
        }

        if ($amountCents > self::maxPaymentAmountCents()) {
            return ['allowed' => false, 'reason' => 'payment_amount_exceeds_limit'];
        }

        if ($expectedOrderTotalCents !== null && $amountCents !== $expectedOrderTotalCents) {
            return ['allowed' => false, 'reason' => 'payment_amount_mismatch'];
        }

        return ['allowed' => true, 'reason' => null];
    }
}
