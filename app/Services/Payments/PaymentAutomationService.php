<?php

namespace App\Services\Payments;

use App\DTOs\Payments\PaymentAutomationContext;
use App\DTOs\Payments\PaymentAutomationResult;
use App\Models\PaymentAttempt;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Single entry point for post-PaymentAttempt automations.
 *
 * Phase 2B: infrastructure only — logs + context resolution.
 * Future phases may wire ActiveCampaign, email, WhatsApp, push, webhooks, etc.
 */
class PaymentAutomationService
{
    public function handleApproved(PaymentAttempt $attempt): PaymentAutomationResult
    {
        return $this->runHandler('handleApproved', $attempt, PaymentAttempt::STATUS_APPROVED);
    }

    public function handleDeclined(PaymentAttempt $attempt): PaymentAutomationResult
    {
        return $this->runHandler('handleDeclined', $attempt, PaymentAttempt::STATUS_DECLINED);
    }

    public function handleError(PaymentAttempt $attempt): PaymentAutomationResult
    {
        return $this->runHandler('handleError', $attempt, PaymentAttempt::STATUS_ERROR);
    }

    private function runHandler(string $handler, PaymentAttempt $attempt, string $expectedStatus): PaymentAutomationResult
    {
        $context = $this->buildContext($attempt);

        Log::info('[PaymentAutomation] '.$handler.' prepared', [
            'handler' => $handler,
            'expected_status' => $expectedStatus,
            'actual_status' => $context->status,
            'context' => $context->toArray(),
        ]);

        // Intentionally no side effects yet (AC / email / WhatsApp / push / webhooks / analytics).

        $result = new PaymentAutomationResult(
            handler: $handler,
            status: $context->status,
            handled: true,
            message: 'Payment automation infrastructure stub — context prepared, no side effects executed.',
            context: $context->toArray(),
            automationsExecuted: false,
        );

        Log::info('[PaymentAutomation] '.$handler.' completed', $result->toArray());

        return $result;
    }

    public function buildContext(PaymentAttempt $attempt): PaymentAutomationContext
    {
        $attempt->loadMissing('customer');

        $customer = $attempt->customer;
        $transaction = $this->resolveTransaction($attempt);
        $order = $this->resolveOrder($transaction);

        return new PaymentAutomationContext(
            attempt: $attempt,
            customer: $customer,
            order: $order,
            transaction: $transaction,
            gateway: $attempt->gateway,
            amountCents: (int) $attempt->amount_cents,
            status: (string) $attempt->status,
            reference: $attempt->reference,
            timestamp: $attempt->processed_at ?? $attempt->updated_at ?? now(),
        );
    }

    private function resolveTransaction(PaymentAttempt $attempt): ?Transaction
    {
        if (! filled($attempt->reference)) {
            return null;
        }

        return Transaction::query()
            ->where('reference_id', $attempt->reference)
            ->latest('id')
            ->first();
    }

    private function resolveOrder(?Transaction $transaction): ?Model
    {
        if (! $transaction) {
            return null;
        }

        $transaction->loadMissing([
            'laboratoryPurchases',
            'onlinePharmacyPurchases',
            'medicalAttentionSubscriptions',
        ]);

        return $transaction->laboratoryPurchases->first()
            ?? $transaction->onlinePharmacyPurchases->first()
            ?? $transaction->medicalAttentionSubscriptions->first()
            ?? null;
    }
}
