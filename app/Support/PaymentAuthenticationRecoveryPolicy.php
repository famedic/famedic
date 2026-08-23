<?php

namespace App\Support;

use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Models\Customer;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationRecoveryContext;

class PaymentAuthenticationRecoveryPolicy
{
    public const ACTION_RETRY = 'retry';

    public const ACTION_DIFFERENT_CARD = 'different_card';

    public const RECOVERY_INTENT_RETRY = 'retry_same_method';

    public const RECOVERY_INTENT_DIFFERENT_CARD = 'different_card';

    /**
     * @return array{
     *     allowed: bool,
     *     block_reason: string|null,
     *     attempts_remaining: int,
     *     cooldown_remaining_seconds: int,
     *     active_attempt: PaymentAuthenticationAttempt|null
     * }
     */
    public function evaluate(
        Customer $customer,
        PaymentAuthenticationAttempt $attempt,
        PaymentAuthenticationRecoveryContext $context,
        string $recoveryAction
    ): array {
        $attemptsRemaining = $this->attemptsRemaining($context);
        $cooldownRemaining = $this->cooldownRemainingSeconds($attempt);
        $activeAttempt = $this->activeAttempt($customer);

        if (! in_array($recoveryAction, [self::ACTION_RETRY, self::ACTION_DIFFERENT_CARD], true)) {
            return $this->blocked('invalid_recovery_action', $attemptsRemaining, $cooldownRemaining, $activeAttempt);
        }

        if ((int) $attempt->customer_id !== (int) $customer->id) {
            abort(404);
        }

        if ((int) $context->customer_id !== (int) $customer->id) {
            abort(404);
        }

        if ($attempt->recovery_context_id !== null && (int) $attempt->recovery_context_id !== (int) $context->id) {
            abort(404);
        }

        if ($context->isExpired() || $context->status === PaymentAuthenticationRecoveryContextStatus::Expired) {
            return $this->blocked('context_expired', $attemptsRemaining, $cooldownRemaining, $activeAttempt);
        }

        if (in_array($context->status, [
            PaymentAuthenticationRecoveryContextStatus::Cancelled,
            PaymentAuthenticationRecoveryContextStatus::Recovered,
        ], true)) {
            return $this->blocked('context_unavailable', $attemptsRemaining, $cooldownRemaining, $activeAttempt);
        }

        if (in_array($attempt->status, [
            PaymentAuthenticationAttemptStatus::Unknown->value,
            PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value,
            PaymentAuthenticationAttemptStatus::Authenticated->value,
            PaymentAuthenticationAttemptStatus::Tokenizing->value,
            PaymentAuthenticationAttemptStatus::Completed->value,
        ], true)) {
            return $this->blocked('status_blocks_recovery', $attemptsRemaining, $cooldownRemaining, $activeAttempt);
        }

        if (! $attempt->isRecoverableTerminal() && ! $attempt->isActive()) {
            return $this->blocked('attempt_not_recoverable', $attemptsRemaining, $cooldownRemaining, $activeAttempt);
        }

        if ($activeAttempt && (int) $activeAttempt->id !== (int) $attempt->id) {
            return $this->blocked('active_attempt_exists', $attemptsRemaining, $cooldownRemaining, $activeAttempt);
        }

        if ($attemptsRemaining <= 0) {
            return $this->blocked('recovery_limit_reached', 0, $cooldownRemaining, $activeAttempt);
        }

        if ($attempt->status === PaymentAuthenticationAttemptStatus::TechnicalError->value && $cooldownRemaining > 0) {
            return $this->blocked('cooldown_active', $attemptsRemaining, $cooldownRemaining, $activeAttempt);
        }

        if ($context->status === PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress && $activeAttempt) {
            return $this->blocked('active_attempt_exists', $attemptsRemaining, $cooldownRemaining, $activeAttempt);
        }

        return [
            'allowed' => true,
            'block_reason' => null,
            'attempts_remaining' => $attemptsRemaining,
            'cooldown_remaining_seconds' => $cooldownRemaining,
            'active_attempt' => $activeAttempt,
        ];
    }

    public function attemptsInWindow(PaymentAuthenticationRecoveryContext $context): int
    {
        $windowMinutes = max(1, (int) config('efevoopay.recovery.attempt_window_minutes', 30));

        return $context->authenticationAttempts()
            ->where('started_at', '>=', now()->subMinutes($windowMinutes))
            ->count();
    }

    public function attemptsRemaining(PaymentAuthenticationRecoveryContext $context): int
    {
        $max = max(1, (int) config('efevoopay.recovery.max_attempts_per_context', 3));

        return max(0, $max - $this->attemptsInWindow($context));
    }

    public function maxAttemptsPerContext(): int
    {
        return max(1, (int) config('efevoopay.recovery.max_attempts_per_context', 3));
    }

    public function cooldownRemainingSeconds(PaymentAuthenticationAttempt $attempt): int
    {
        if ($attempt->status !== PaymentAuthenticationAttemptStatus::TechnicalError->value || ! $attempt->finished_at) {
            return 0;
        }

        $cooldown = max(0, (int) config('efevoopay.recovery.technical_error_cooldown_seconds', 60));
        $elapsed = (int) $attempt->finished_at->diffInSeconds(now());

        return max(0, $cooldown - $elapsed);
    }

    public function activeAttempt(Customer $customer): ?PaymentAuthenticationAttempt
    {
        return PaymentAuthenticationAttempt::query()
            ->where('customer_id', $customer->id)
            ->where('operation_type', PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS)
            ->whereIn('status', PaymentAuthenticationAttemptStatus::activeValues())
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->latest('id')
            ->first();
    }

    public function failureCountInContext(PaymentAuthenticationRecoveryContext $context): int
    {
        return $context->authenticationAttempts()
            ->whereIn('status', PaymentAuthenticationAttemptStatus::recoverableTerminalValues())
            ->count();
    }

    public function shouldPrioritizeDifferentCard(PaymentAuthenticationRecoveryContext $context): bool
    {
        $threshold = max(1, (int) config('efevoopay.recovery.prioritize_different_card_after_failures', 2));

        return $this->failureCountInContext($context) >= $threshold;
    }

    public function recoveryIntentForAction(string $recoveryAction): string
    {
        return $recoveryAction === self::ACTION_DIFFERENT_CARD
            ? self::RECOVERY_INTENT_DIFFERENT_CARD
            : self::RECOVERY_INTENT_RETRY;
    }

    /**
     * @return array{
     *     allowed: bool,
     *     block_reason: string|null,
     *     attempts_remaining: int,
     *     cooldown_remaining_seconds: int,
     *     active_attempt: PaymentAuthenticationAttempt|null
     * }
     */
    private function blocked(
        string $blockReason,
        int $attemptsRemaining,
        int $cooldownRemaining,
        ?PaymentAuthenticationAttempt $activeAttempt
    ): array {
        return [
            'allowed' => false,
            'block_reason' => $blockReason,
            'attempts_remaining' => $attemptsRemaining,
            'cooldown_remaining_seconds' => $cooldownRemaining,
            'active_attempt' => $activeAttempt,
        ];
    }
}
