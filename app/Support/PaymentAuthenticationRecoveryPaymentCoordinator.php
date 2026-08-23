<?php

namespace App\Support;

use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationRecoveryContext;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class PaymentAuthenticationRecoveryPaymentCoordinator
{
    public function __construct(
        private PaymentAuthenticationRecoveryContextManager $contextManager,
        private PaymentAuthenticationAttemptRecorder $recorder,
        private PaymentAuthenticationSensitiveCardDataStore $cardDataStore
    ) {}

    public function linkPendingOrder(
        PaymentAuthenticationRecoveryContext $context,
        Transaction $transaction,
        PaymentAuthenticationAttempt $attempt
    ): PaymentAuthenticationRecoveryContext {
        return DB::transaction(function () use ($context, $transaction, $attempt) {
            $locked = PaymentAuthenticationRecoveryContext::query()
                ->whereKey($context->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->forceFill([
                'recovery_transaction_id' => $transaction->id,
                'recovery_method' => 'paypal',
            ])->save();

            $this->contextManager->transition(
                $locked->fresh(),
                PaymentAuthenticationRecoveryContextStatus::PaymentInProgress
            );

            $this->recorder->record($attempt, PaymentAuthenticationAttemptEventType::PaypalOrderCreated, [
                'source' => 'backend',
                'dedupe_key' => 'paypal_order_created:'.$transaction->id,
                'metadata' => [
                    'context_uuid' => $locked->context_uuid,
                    'context_type' => $this->typeValue($locked),
                    'transaction_id' => $transaction->id,
                    'provider_order_id' => $transaction->provider_order_id ?? $transaction->reference_id,
                    'detected_by' => 'create_order',
                ],
            ]);

            return $locked->fresh();
        });
    }

    public function recordOrderReused(
        PaymentAuthenticationRecoveryContext $context,
        Transaction $transaction,
        PaymentAuthenticationAttempt $attempt
    ): void {
        $this->recorder->record($attempt, PaymentAuthenticationAttemptEventType::PaypalOrderReused, [
            'source' => 'backend',
            'dedupe_key' => 'paypal_order_reused:'.$transaction->id,
            'metadata' => [
                'context_uuid' => $context->context_uuid,
                'context_type' => $this->typeValue($context),
                'transaction_id' => $transaction->id,
                'provider_order_id' => $transaction->provider_order_id ?? $transaction->reference_id,
                'detected_by' => 'create_order',
            ],
        ]);
    }

    public function markRecovered(
        PaymentAuthenticationRecoveryContext $context,
        Transaction $transaction,
        PaymentAuthenticationAttempt $attempt
    ): PaymentAuthenticationRecoveryContext {
        return DB::transaction(function () use ($context, $transaction, $attempt) {
            $locked = PaymentAuthenticationRecoveryContext::query()
                ->whereKey($context->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === PaymentAuthenticationRecoveryContextStatus::Recovered) {
                return $locked;
            }

            $this->cardDataStore->purgeForRecoveryContext($locked, 'context_recovered', [
                'stage' => 'recovery_completed',
                'detected_by' => 'famedic',
            ]);

            $locked->forceFill([
                'status' => PaymentAuthenticationRecoveryContextStatus::Recovered->value,
                'recovered_at' => $locked->recovered_at ?? now(),
                'recovered_transaction_id' => $transaction->id,
                'recovery_method' => 'paypal',
                'recovery_transaction_id' => null,
            ])->save();

            $this->recorder->record($attempt, PaymentAuthenticationAttemptEventType::RecoveryCompleted, [
                'source' => 'backend',
                'dedupe_key' => 'recovery_completed:'.$locked->id.':'.$transaction->id,
                'metadata' => [
                    'context_uuid' => $locked->context_uuid,
                    'context_type' => $this->typeValue($locked),
                    'transaction_id' => $transaction->id,
                    'provider_order_id' => $transaction->provider_order_id ?? $transaction->reference_id,
                    'detected_by' => 'paypal_capture',
                ],
            ]);

            $this->recorder->record($attempt, PaymentAuthenticationAttemptEventType::PaypalCaptureSucceeded, [
                'source' => 'backend',
                'dedupe_key' => 'paypal_capture_succeeded:'.$transaction->id,
                'metadata' => [
                    'context_uuid' => $locked->context_uuid,
                    'context_type' => $this->typeValue($locked),
                    'transaction_id' => $transaction->id,
                    'provider_order_id' => $transaction->provider_order_id ?? $transaction->reference_id,
                    'detected_by' => 'paypal_capture',
                ],
            ]);

            return $locked->fresh();
        });
    }

    public function releaseAfterPayPalCancel(
        PaymentAuthenticationRecoveryContext $context,
        ?Transaction $transaction,
        PaymentAuthenticationAttempt $attempt
    ): PaymentAuthenticationRecoveryContext {
        return DB::transaction(function () use ($context, $transaction, $attempt) {
            $locked = PaymentAuthenticationRecoveryContext::query()
                ->whereKey($context->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === PaymentAuthenticationRecoveryContextStatus::Recovered) {
                return $locked;
            }

            if ($transaction && ($transaction->payment_status ?? '') === 'captured') {
                return $locked;
            }

            $locked->forceFill([
                'recovery_transaction_id' => null,
                'recovery_method' => 'paypal',
            ])->save();

            $this->contextManager->transition(
                $locked->fresh(),
                PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable
            );

            $this->recorder->record($attempt, PaymentAuthenticationAttemptEventType::PaypalCancelled, [
                'source' => 'frontend',
                'dedupe_key' => 'paypal_cancelled:'.$locked->id.':'.($transaction?->id ?? 'none'),
                'metadata' => [
                    'context_uuid' => $locked->context_uuid,
                    'context_type' => $this->typeValue($locked),
                    'transaction_id' => $transaction?->id,
                    'detected_by' => 'paypal_on_cancel',
                ],
            ]);

            return $locked->fresh();
        });
    }

    public function resolveAttemptForContext(PaymentAuthenticationRecoveryContext $context): ?PaymentAuthenticationAttempt
    {
        if ($context->root_authentication_attempt_id) {
            return PaymentAuthenticationAttempt::query()->find($context->root_authentication_attempt_id);
        }

        return $context->authenticationAttempts()->latest('id')->first();
    }

    public function recoveryDetailsForTransaction(
        PaymentAuthenticationRecoveryContext $context,
        PaymentAuthenticationAttempt $attempt
    ): array {
        return [
            'recovery_context_id' => $context->id,
            'recovery_context_uuid' => $context->context_uuid,
            'recovery_source' => '3ds_recovery',
            'recovery_method' => 'paypal',
            'failed_authentication_attempt_id' => $attempt->id,
        ];
    }

    private function typeValue(PaymentAuthenticationRecoveryContext $context): string
    {
        return $context->context_type instanceof \App\Enums\PaymentAuthenticationRecoveryContextType
            ? $context->context_type->value
            : (string) $context->context_type;
    }
}
