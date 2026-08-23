<?php

namespace App\Support;

use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Models\Customer;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationRecoveryContext;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class PaymentAuthenticationRecoveryPayPalOrderHelper
{
    public function __construct(
        private PaymentAuthenticationRecoveryContextManager $contextManager,
        private PaymentAuthenticationRecoveryContextGuard $guard,
        private PaymentAuthenticationRecoveryPayPalPolicy $policy,
        private PaymentAuthenticationRecoveryPaymentCoordinator $coordinator,
        private PaymentAuthenticationAttemptRecorder $recorder
    ) {}

    public function findOwnedContext(Customer $customer, string $contextUuid): PaymentAuthenticationRecoveryContext
    {
        $context = $this->contextManager->findOwned($customer, $contextUuid);

        if (! $context) {
            throw PaymentAuthenticationRecoveryContextException::notFound();
        }

        return $context;
    }

    public function resolveAttempt(PaymentAuthenticationRecoveryContext $context): PaymentAuthenticationAttempt
    {
        $attempt = $this->coordinator->resolveAttemptForContext($context);

        if (! $attempt) {
            throw PaymentAuthenticationRecoveryContextException::notFound();
        }

        return $attempt;
    }

    /**
     * @return array{context: PaymentAuthenticationRecoveryContext, attempt: PaymentAuthenticationAttempt}
     */
    public function bootstrapForOrder(
        Customer $customer,
        string $contextUuid,
        int $expectedAmountCents,
        ?string $purpose = null
    ): array {
        $context = $this->findOwnedContext($customer, $contextUuid);
        $this->contextManager->expireIfNeeded($context);
        $context = $context->fresh() ?? $context;

        $attempt = $this->resolveAttempt($context);
        if ((int) $attempt->customer_id !== (int) $customer->id) {
            abort(404);
        }

        $evaluation = $this->policy->evaluate($customer, $context, $attempt->fresh());
        if (! $evaluation['allowed']) {
            throw PaymentAuthenticationRecoveryStartException::blocked(
                'No es posible continuar con PayPal en este momento.',
                $evaluation['block_reason'] ?? 'unknown',
                $evaluation
            );
        }

        $reused = $this->tryReusePendingOrder($context, $attempt, $expectedAmountCents, $purpose);
        if ($reused !== null) {
            return [
                'context' => $context->fresh(),
                'attempt' => $attempt,
                'reused' => $reused,
            ];
        }

        return [
            'context' => $context,
            'attempt' => $attempt,
            'reused' => null,
        ];
    }

    /**
     * @return array{order_id: string, transaction_id: int}|null
     */
    public function tryReusePendingOrder(
        PaymentAuthenticationRecoveryContext $context,
        PaymentAuthenticationAttempt $attempt,
        int $expectedAmountCents,
        ?string $purpose = null
    ): ?array {
        $pending = $this->policy->pendingRecoveryTransaction($context);

        if (! $pending) {
            return null;
        }

        if ($this->policy->isAmbiguousPendingTransaction($pending)) {
            throw PaymentAuthenticationRecoveryStartException::blocked(
                'Estamos confirmando tu pago con PayPal. Actualiza el estado o contacta soporte.',
                'recovery_confirmation_pending',
                []
            );
        }

        $details = is_array($pending->details) ? $pending->details : [];

        if ((int) $pending->transaction_amount_cents !== $expectedAmountCents) {
            return null;
        }

        if ($purpose !== null && ($details['purpose'] ?? null) !== $purpose) {
            return null;
        }

        $orderId = $pending->provider_order_id ?? $pending->reference_id;

        if (! is_string($orderId) || $orderId === '' || str_starts_with($orderId, 'PAYPAL-PENDING-')) {
            return null;
        }

        $this->coordinator->recordOrderReused($context, $pending, $attempt);

        return [
            'order_id' => $orderId,
            'transaction_id' => $pending->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $baseDetails
     * @return array<string, mixed>
     */
    public function mergeRecoveryDetails(
        array $baseDetails,
        PaymentAuthenticationRecoveryContext $context,
        PaymentAuthenticationAttempt $attempt
    ): array {
        return array_merge($baseDetails, $this->coordinator->recoveryDetailsForTransaction($context, $attempt));
    }

    public function afterOrderCreated(
        PaymentAuthenticationRecoveryContext $context,
        Transaction $transaction,
        PaymentAuthenticationAttempt $attempt,
        bool $reused = false
    ): void {
        if ($reused) {
            return;
        }

        $this->coordinator->linkPendingOrder($context, $transaction, $attempt);
    }

    public function markCreateTimeout(
        PaymentAuthenticationRecoveryContext $context,
        Transaction $transaction,
        PaymentAuthenticationAttempt $attempt
    ): void {
        DB::transaction(function () use ($context, $transaction) {
            $tx = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->first();

            if (! $tx) {
                return;
            }

            $details = is_array($tx->details) ? $tx->details : [];
            $tx->forceFill([
                'details' => array_merge($details, [
                    'recovery_confirmation_pending' => true,
                ]),
            ])->save();

            $locked = PaymentAuthenticationRecoveryContext::query()
                ->whereKey($context->id)
                ->lockForUpdate()
                ->first();

            if ($locked && $locked->status !== PaymentAuthenticationRecoveryContextStatus::Recovered) {
                $locked->forceFill([
                    'recovery_transaction_id' => $tx->id,
                    'recovery_method' => 'paypal',
                ])->save();

                app(PaymentAuthenticationRecoveryContextManager::class)->transition(
                    $locked->fresh(),
                    PaymentAuthenticationRecoveryContextStatus::PaymentInProgress
                );
            }
        });

        $this->recorder->record($attempt, PaymentAuthenticationAttemptEventType::PaypalOrderTimeout, [
            'source' => 'backend',
            'dedupe_key' => 'paypal_order_timeout:'.$transaction->id,
            'metadata' => [
                'context_uuid' => $context->context_uuid,
                'context_type' => $this->typeValue($context),
                'transaction_id' => $transaction->id,
                'detected_by' => 'create_order',
            ],
        ]);

        $this->recorder->record($attempt, PaymentAuthenticationAttemptEventType::RecoveryConfirmationPending, [
            'source' => 'backend',
            'dedupe_key' => 'recovery_confirmation_pending:'.$context->id.':'.$transaction->id,
            'metadata' => [
                'context_uuid' => $context->context_uuid,
                'context_type' => $this->typeValue($context),
                'transaction_id' => $transaction->id,
                'detected_by' => 'create_order_timeout',
            ],
        ]);
    }

    public function markCaptureConfirmationPending(
        PaymentAuthenticationRecoveryContext $context,
        Transaction $transaction,
        PaymentAuthenticationAttempt $attempt,
    ): void {
        DB::transaction(function () use ($context, $transaction) {
            $tx = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->first();

            if ($tx) {
                $details = is_array($tx->details) ? $tx->details : [];
                $tx->forceFill([
                    'details' => array_merge($details, [
                        'recovery_confirmation_pending' => true,
                    ]),
                ])->save();
            }

            $locked = PaymentAuthenticationRecoveryContext::query()
                ->whereKey($context->id)
                ->lockForUpdate()
                ->first();

            if ($locked && $locked->status !== PaymentAuthenticationRecoveryContextStatus::Recovered) {
                $locked->forceFill([
                    'recovery_transaction_id' => $transaction->id,
                    'recovery_method' => $locked->recovery_method ?? 'paypal',
                ])->save();

                if ($locked->status !== PaymentAuthenticationRecoveryContextStatus::PaymentInProgress) {
                    app(PaymentAuthenticationRecoveryContextManager::class)->transition(
                        $locked->fresh(),
                        PaymentAuthenticationRecoveryContextStatus::PaymentInProgress
                    );
                }
            }
        });

        $this->recorder->record($attempt, PaymentAuthenticationAttemptEventType::PaypalCaptureFailed, [
            'source' => 'backend',
            'dedupe_key' => 'paypal_capture_failed:'.$transaction->id,
            'metadata' => [
                'context_uuid' => $context->context_uuid,
                'context_type' => $this->typeValue($context),
                'transaction_id' => $transaction->id,
                'detected_by' => 'capture_order_timeout',
            ],
        ]);

        $this->recorder->record($attempt, PaymentAuthenticationAttemptEventType::RecoveryConfirmationPending, [
            'source' => 'backend',
            'dedupe_key' => 'recovery_confirmation_pending_capture:'.$context->id.':'.$transaction->id,
            'metadata' => [
                'context_uuid' => $context->context_uuid,
                'context_type' => $this->typeValue($context),
                'transaction_id' => $transaction->id,
                'detected_by' => 'capture_order_timeout',
            ],
        ]);
    }

    public function recordOrderRequestStarted(
        PaymentAuthenticationRecoveryContext $context,
        PaymentAuthenticationAttempt $attempt
    ): void {
        $this->recorder->record($attempt, PaymentAuthenticationAttemptEventType::PaypalOrderRequestStarted, [
            'source' => 'backend',
            'dedupe_key' => 'paypal_order_request_started:'.$context->id.':'.now()->format('YmdHi'),
            'metadata' => [
                'context_uuid' => $context->context_uuid,
                'context_type' => $this->typeValue($context),
                'detected_by' => 'create_order',
            ],
        ]);
    }

    /**
     * @return array{context: PaymentAuthenticationRecoveryContext, attempt: PaymentAuthenticationAttempt}|null
     */
    public function resolveRecoveryParticipantsFromTransaction(Transaction $transaction): ?array
    {
        $details = is_array($transaction->details) ? $transaction->details : [];
        $contextUuid = $details['recovery_context_uuid'] ?? null;

        if (! is_string($contextUuid) || $contextUuid === '') {
            return null;
        }

        $context = PaymentAuthenticationRecoveryContext::query()
            ->where('context_uuid', $contextUuid)
            ->first();

        if (! $context) {
            return null;
        }

        $attempt = app(PaymentAuthenticationRecoveryPaymentCoordinator::class)
            ->resolveAttemptForContext($context);

        if (! $attempt) {
            return null;
        }

        return [
            'context' => $context,
            'attempt' => $attempt,
        ];
    }

    public function validateCaptureOwnership(
        Customer $customer,
        Transaction $transaction
    ): ?PaymentAuthenticationRecoveryContext {
        $details = is_array($transaction->details) ? $transaction->details : [];
        $contextUuid = $details['recovery_context_uuid'] ?? null;

        if (! is_string($contextUuid) || $contextUuid === '') {
            return null;
        }

        $context = $this->findOwnedContext($customer, $contextUuid);

        if ((int) ($context->recovery_transaction_id ?? 0) !== (int) $transaction->id
            && (int) ($context->recovered_transaction_id ?? 0) !== (int) $transaction->id) {
            abort(404);
        }

        return $context;
    }

    public function maybeMarkRecovered(Transaction $transaction): void
    {
        $details = is_array($transaction->details) ? $transaction->details : [];
        $contextUuid = $details['recovery_context_uuid'] ?? null;

        if (! is_string($contextUuid) || $contextUuid === '') {
            return;
        }

        $context = PaymentAuthenticationRecoveryContext::query()
            ->where('context_uuid', $contextUuid)
            ->first();

        if (! $context || $context->status === PaymentAuthenticationRecoveryContextStatus::Recovered) {
            return;
        }

        $attempt = $this->coordinator->resolveAttemptForContext($context);

        if (! $attempt) {
            return;
        }

        $this->coordinator->markRecovered($context, $transaction, $attempt);

        $customerId = (int) ($details['customer_id'] ?? 0);
        if ($customerId > 0) {
            $customer = Customer::query()->find($customerId);
            if ($customer) {
                app(PaymentAuthenticationRecoveryPayPalNavigator::class)->clearPreparedCheckout($customer);
            }
        }
    }

    private function typeValue(PaymentAuthenticationRecoveryContext $context): string
    {
        return $context->context_type instanceof \App\Enums\PaymentAuthenticationRecoveryContextType
            ? $context->context_type->value
            : (string) $context->context_type;
    }
}
