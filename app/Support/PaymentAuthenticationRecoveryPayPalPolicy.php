<?php

namespace App\Support;

use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Enums\PaymentAuthenticationRecoveryContextType;
use App\Models\Customer;
use App\Models\MedicalAttentionSubscription;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationRecoveryContext;
use App\Models\Transaction;

class PaymentAuthenticationRecoveryPayPalPolicy
{
    public function __construct(
        private PaymentAuthenticationRecoveryPolicy $cardPolicy,
        private PaymentAuthenticationRecoveryContextGuard $guard
    ) {}

    /**
     * @return array{
     *     allowed: bool,
     *     block_reason: string|null,
     *     checkout_ready: bool
     * }
     */
    public function evaluate(
        Customer $customer,
        PaymentAuthenticationRecoveryContext $context,
        ?PaymentAuthenticationAttempt $attempt = null,
        ?string $presentation = null
    ): array {
        if ((int) $context->customer_id !== (int) $customer->id) {
            abort(404);
        }

        $type = $context->context_type instanceof PaymentAuthenticationRecoveryContextType
            ? $context->context_type
            : PaymentAuthenticationRecoveryContextType::tryFrom((string) $context->context_type);

        if (! $type?->supportsPayPal()) {
            return $this->blocked('context_type_not_supported');
        }

        if ($context->isExpired() || $context->status === PaymentAuthenticationRecoveryContextStatus::Expired) {
            return $this->blocked('context_expired');
        }

        if (in_array($context->status, [
            PaymentAuthenticationRecoveryContextStatus::Cancelled,
            PaymentAuthenticationRecoveryContextStatus::Recovered,
        ], true)) {
            return $this->blocked('context_unavailable');
        }

        if ($context->recovered_at !== null || $context->recovered_transaction_id !== null) {
            return $this->blocked('recovery_already_completed');
        }

        if ($this->hasCompletedOutcome($customer, $context, $type)) {
            return $this->blocked('purchase_already_completed');
        }

        try {
            $this->guard->assertResources($customer, $context);
        } catch (PaymentAuthenticationRecoveryContextException) {
            return $this->blocked('checkout_invalid');
        }

        if ($attempt) {
            if ((int) $attempt->customer_id !== (int) $customer->id) {
                abort(404);
            }

            if (in_array($attempt->status, [
                PaymentAuthenticationAttemptStatus::Unknown->value,
                PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value,
                PaymentAuthenticationAttemptStatus::Authenticated->value,
                PaymentAuthenticationAttemptStatus::Tokenizing->value,
                PaymentAuthenticationAttemptStatus::Completed->value,
            ], true)) {
                return $this->blocked('status_blocks_recovery');
            }

            if ($presentation && in_array($presentation, [
                'unknown',
                'provider_confirmation_pending',
                'authenticated',
                'tokenizing',
                'completed',
                'context_unavailable',
            ], true)) {
                return $this->blocked('status_blocks_recovery');
            }
        }

        $activeAttempt = $this->cardPolicy->activeAttempt($customer);
        if ($activeAttempt && (! $attempt || (int) $activeAttempt->id !== (int) $attempt->id)) {
            return $this->blocked('active_attempt_exists');
        }

        if ($context->status === PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress) {
            return $this->blocked('authentication_in_progress');
        }

        if ($context->status === PaymentAuthenticationRecoveryContextStatus::PaymentInProgress) {
            $pending = $this->pendingRecoveryTransaction($context);

            if ($pending && $this->isAmbiguousPendingTransaction($pending)) {
                return $this->blocked('recovery_confirmation_pending');
            }

            if ($pending) {
                return [
                    'allowed' => true,
                    'block_reason' => null,
                    'checkout_ready' => true,
                ];
            }

            return $this->blocked('payment_in_progress_without_transaction');
        }

        if ($context->status !== PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable) {
            return $this->blocked('context_status_not_available');
        }

        if ($attempt && ! $attempt->isRecoverableTerminal()) {
            return $this->blocked('attempt_not_recoverable');
        }

        return [
            'allowed' => true,
            'block_reason' => null,
            'checkout_ready' => true,
        ];
    }

    public function pendingRecoveryTransaction(PaymentAuthenticationRecoveryContext $context): ?Transaction
    {
        if (! $context->recovery_transaction_id) {
            return null;
        }

        $transaction = Transaction::query()->find($context->recovery_transaction_id);

        if (! $transaction || ($transaction->payment_status ?? '') !== 'pending') {
            return null;
        }

        return $transaction;
    }

    public function isAmbiguousPendingTransaction(Transaction $transaction): bool
    {
        $details = is_array($transaction->details) ? $transaction->details : [];

        return (bool) ($details['recovery_confirmation_pending'] ?? false);
    }

    private function hasCompletedOutcome(
        Customer $customer,
        PaymentAuthenticationRecoveryContext $context,
        PaymentAuthenticationRecoveryContextType $type
    ): bool {
        if ($type === PaymentAuthenticationRecoveryContextType::LaboratoryCheckout) {
            $brand = \App\Enums\LaboratoryBrand::tryFrom((string) $context->contextDataValue('laboratory_brand'));

            if ($brand && $customer->laboratoryPurchases()->where('brand', $brand->value)->where('created_at', '>=', $context->started_at)->exists()) {
                return true;
            }
        }

        if ($type === PaymentAuthenticationRecoveryContextType::MedicalAttentionCheckout) {
            return $customer->medicalAttentionSubscriptions()->active()->exists()
                || MedicalAttentionSubscription::query()
                    ->where('customer_id', $customer->id)
                    ->where('created_at', '>=', $context->started_at)
                    ->exists();
        }

        return false;
    }

    /**
     * @return array{allowed: bool, block_reason: string|null, checkout_ready: bool}
     */
    private function blocked(string $reason): array
    {
        return [
            'allowed' => false,
            'block_reason' => $reason,
            'checkout_ready' => false,
        ];
    }
}
