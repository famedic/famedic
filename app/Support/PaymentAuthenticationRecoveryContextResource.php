<?php

namespace App\Support;

use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Enums\PaymentAuthenticationRecoveryContextType;
use App\Models\Customer;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationRecoveryContext;

class PaymentAuthenticationRecoveryContextResource
{
    public function __construct(
        private PaymentAuthenticationRecoveryContextGuard $guard,
        private PaymentAuthenticationRecoveryReturnBuilder $returnBuilder
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function make(
        PaymentAuthenticationRecoveryContext $context,
        Customer $customer,
        ?PaymentAuthenticationAttempt $attempt = null
    ): array {
        $type = $context->context_type instanceof PaymentAuthenticationRecoveryContextType
            ? $context->context_type
            : PaymentAuthenticationRecoveryContextType::tryFrom((string) $context->context_type);

        $status = $context->status instanceof PaymentAuthenticationRecoveryContextStatus
            ? $context->status
            : PaymentAuthenticationRecoveryContextStatus::tryFrom((string) $context->status);

        $recoverableTerminalAttempt = $attempt?->isRecoverableTerminal() ?? false;
        $blocked = $this->recoveryIsBlocked($context, $attempt);
        $usable = $status && in_array($status->value, PaymentAuthenticationRecoveryContextStatus::reusableValues(), true)
            && ! $context->isExpired();
        $canRetry = $status === PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable
            || ($status === PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress && $recoverableTerminalAttempt);

        return [
            'context_uuid' => $context->context_uuid,
            'context_type' => $type?->value ?? PaymentAuthenticationRecoveryContextType::UNKNOWN,
            'status' => $status?->value ?? (string) $context->status,
            'supports_paypal' => $usable && ! $blocked && ($type?->supportsPayPal() ?? false),
            'supports_another_card' => $usable && ! $blocked && ($type?->supportsAnotherCard() ?? false),
            'supports_retry' => $usable && ! $blocked && $canRetry,
            'has_saved_cart' => $this->guard->hasSavedCart($customer, $context),
            'return_action' => $this->returnBuilder->action($customer, $context),
            'expires_at' => $context->expires_at?->toISOString(),
            'support_reference' => $attempt?->support_reference
                ?? $context->authenticationAttempts()->latest('id')->value('support_reference'),
        ];
    }

    private function recoveryIsBlocked(
        PaymentAuthenticationRecoveryContext $context,
        ?PaymentAuthenticationAttempt $attempt
    ): bool {
        $status = $context->status instanceof PaymentAuthenticationRecoveryContextStatus
            ? $context->status
            : PaymentAuthenticationRecoveryContextStatus::tryFrom((string) $context->status);

        if ($status === PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress
            && ! ($attempt?->isRecoverableTerminal() ?? false)) {
            return true;
        }

        if (! $attempt) {
            return false;
        }

        return in_array($attempt->status, [
            PaymentAuthenticationAttemptStatus::Unknown->value,
            PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value,
            PaymentAuthenticationAttemptStatus::Authenticated->value,
            PaymentAuthenticationAttemptStatus::Tokenizing->value,
        ], true);
    }
}
