<?php

namespace App\Services\Orders;

use App\DTOs\Orders\OrderAutomationContext;
use App\DTOs\Orders\OrderAutomationDispatchResult;
use App\Models\LaboratoryPurchase;
use App\Models\MedicalAttentionSubscription;
use App\Models\OnlinePharmacyPurchase;
use App\Models\PaymentAttempt;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

/**
 * Single authorized entry point for post-order automations.
 *
 * PaymentAutomationService = payment outcome only.
 * OrderAutomationService   = order exists and is complete.
 *
 * Phase 2F: fans out to registered drivers via OrderAutomationDispatcher.
 * Not wired from checkout / fulfill / observers yet.
 */
class OrderAutomationService
{
    public function __construct(
        private OrderAutomationDispatcher $dispatcher,
    ) {
    }

    public function handleLaboratoryOrder(OrderAutomationContext $context): OrderAutomationDispatchResult
    {
        return $this->runHandler('handleLaboratoryOrder', $context, OrderAutomationContext::CHANNEL_LABORATORY);
    }

    public function handlePharmacyOrder(OrderAutomationContext $context): OrderAutomationDispatchResult
    {
        return $this->runHandler('handlePharmacyOrder', $context, OrderAutomationContext::CHANNEL_PHARMACY);
    }

    public function handleMembershipOrder(OrderAutomationContext $context): OrderAutomationDispatchResult
    {
        return $this->runHandler('handleMembershipOrder', $context, OrderAutomationContext::CHANNEL_MEMBERSHIP);
    }

    /**
     * Build a laboratory order context for future callers (fulfill / observers).
     */
    public function contextForLaboratory(LaboratoryPurchase $purchase): OrderAutomationContext
    {
        $purchase->loadMissing(['customer.user', 'transactions']);

        $transaction = $purchase->transactions->sortByDesc('id')->first();
        $paymentAttempt = $this->resolvePaymentAttempt($transaction);

        return new OrderAutomationContext(
            order: $purchase,
            customer: $purchase->customer,
            transaction: $transaction,
            paymentAttempt: $paymentAttempt,
            laboratoryPurchase: $purchase,
            pharmacyOrder: null,
            membership: null,
            amountCents: (int) ($purchase->total_cents ?? $transaction?->transaction_amount_cents ?? 0),
            reference: $transaction?->reference_id,
            gateway: $transaction?->gateway ?? $transaction?->payment_method,
            createdAt: $purchase->created_at ?? now(),
            channel: OrderAutomationContext::CHANNEL_LABORATORY,
        );
    }

    /**
     * Build a pharmacy order context for future callers.
     */
    public function contextForPharmacy(OnlinePharmacyPurchase $purchase): OrderAutomationContext
    {
        $purchase->loadMissing(['customer.user', 'transactions']);

        $transaction = $purchase->transactions->sortByDesc('id')->first();
        $paymentAttempt = $this->resolvePaymentAttempt($transaction);

        return new OrderAutomationContext(
            order: $purchase,
            customer: $purchase->customer,
            transaction: $transaction,
            paymentAttempt: $paymentAttempt,
            laboratoryPurchase: null,
            pharmacyOrder: $purchase,
            membership: null,
            amountCents: (int) ($purchase->total_cents ?? $transaction?->transaction_amount_cents ?? 0),
            reference: $transaction?->reference_id,
            gateway: $transaction?->gateway ?? $transaction?->payment_method,
            createdAt: $purchase->created_at ?? now(),
            channel: OrderAutomationContext::CHANNEL_PHARMACY,
        );
    }

    /**
     * Build a membership order context for future callers.
     */
    public function contextForMembership(MedicalAttentionSubscription $subscription): OrderAutomationContext
    {
        $subscription->loadMissing(['customer.user', 'transactions']);

        $transaction = $subscription->transactions->sortByDesc('id')->first();
        $paymentAttempt = $this->resolvePaymentAttempt($transaction);

        return new OrderAutomationContext(
            order: $subscription,
            customer: $subscription->customer,
            transaction: $transaction,
            paymentAttempt: $paymentAttempt,
            laboratoryPurchase: null,
            pharmacyOrder: null,
            membership: $subscription,
            amountCents: (int) ($transaction?->transaction_amount_cents
                ?? config('famedic.medical_attention_subscription_price_cents', 0)),
            reference: $transaction?->reference_id,
            gateway: $transaction?->gateway ?? $transaction?->payment_method,
            createdAt: $subscription->created_at ?? now(),
            channel: OrderAutomationContext::CHANNEL_MEMBERSHIP,
        );
    }

    private function runHandler(
        string $handler,
        OrderAutomationContext $context,
        string $expectedChannel,
    ): OrderAutomationDispatchResult {
        Log::info('[Order Automation] '.$handler.' prepared', [
            'handler' => $handler,
            'expected_channel' => $expectedChannel,
            'actual_channel' => $context->channel,
            'context' => $context->toArray(),
        ]);

        if ($context->channel !== $expectedChannel) {
            $result = new OrderAutomationDispatchResult(
                drivers: [],
                successful: 0,
                failed: 0,
                durationMs: 0,
                operations: [],
                errors: [[
                    'driver' => null,
                    'status' => 'skipped',
                    'message' => "Order automation channel mismatch: expected {$expectedChannel}, got {$context->channel}.",
                    'error' => 'channel_mismatch',
                    'retryable' => false,
                ]],
                channel: $context->channel,
                context: $context->toArray(),
                handled: false,
                status: 'skipped',
                message: "Order automation channel mismatch: expected {$expectedChannel}, got {$context->channel}.",
            );

            Log::warning('[Order Automation] '.$handler.' skipped — channel mismatch', $result->toArray());

            return $result;
        }

        $result = $this->dispatcher->dispatch($context);

        Log::info('[Order Automation] '.$handler.' completed', $result->toArray());

        return $result;
    }

    private function resolvePaymentAttempt(?Transaction $transaction): ?PaymentAttempt
    {
        if (! $transaction || ! filled($transaction->reference_id)) {
            return null;
        }

        return PaymentAttempt::query()
            ->where('reference', $transaction->reference_id)
            ->latest('id')
            ->first();
    }
}
