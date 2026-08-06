<?php

namespace App\Services\Automation;

use App\DTOs\Orders\OrderAutomationContext;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\MedicalAttentionSubscription;
use App\Models\OnlinePharmacyPurchase;
use App\Models\PaymentAttempt;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Rebuilds OrderAutomationContext from a serialized payload (queue-safe).
 * Does not call OrderAutomationService.
 */
class AutomationContextRehydrator
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function rehydrate(array $payload): OrderAutomationContext
    {
        $channel = (string) ($payload['channel'] ?? '');
        if ($channel === '') {
            throw new InvalidArgumentException('Automation payload missing channel.');
        }

        $customer = isset($payload['customer_id'])
            ? Customer::query()->find($payload['customer_id'])
            : null;

        $transaction = isset($payload['transaction_id'])
            ? Transaction::query()->find($payload['transaction_id'])
            : null;

        $paymentAttempt = isset($payload['payment_attempt_id'])
            ? PaymentAttempt::query()->find($payload['payment_attempt_id'])
            : null;

        $laboratoryPurchase = isset($payload['laboratory_purchase_id'])
            ? LaboratoryPurchase::query()->find($payload['laboratory_purchase_id'])
            : null;

        $pharmacyOrder = isset($payload['pharmacy_order_id'])
            ? OnlinePharmacyPurchase::query()->find($payload['pharmacy_order_id'])
            : null;

        $membership = isset($payload['membership_id'])
            ? MedicalAttentionSubscription::query()->find($payload['membership_id'])
            : null;

        $order = $this->resolveOrder($payload, $laboratoryPurchase, $pharmacyOrder, $membership);

        $createdAt = isset($payload['created_at'])
            ? Carbon::parse($payload['created_at'])
            : now();

        return new OrderAutomationContext(
            order: $order,
            customer: $customer,
            transaction: $transaction,
            paymentAttempt: $paymentAttempt,
            laboratoryPurchase: $laboratoryPurchase,
            pharmacyOrder: $pharmacyOrder,
            membership: $membership,
            amountCents: (int) ($payload['amount_cents'] ?? 0),
            reference: $payload['reference'] ?? null,
            gateway: $payload['gateway'] ?? null,
            createdAt: $createdAt,
            channel: $channel,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveOrder(
        array $payload,
        ?LaboratoryPurchase $laboratoryPurchase,
        ?OnlinePharmacyPurchase $pharmacyOrder,
        ?MedicalAttentionSubscription $membership,
    ): ?Model {
        if ($laboratoryPurchase) {
            return $laboratoryPurchase;
        }
        if ($pharmacyOrder) {
            return $pharmacyOrder;
        }
        if ($membership) {
            return $membership;
        }

        $type = $payload['order_type'] ?? null;
        $id = $payload['order_id'] ?? null;
        if (is_string($type) && class_exists($type) && $id) {
            return $type::query()->find($id);
        }

        return null;
    }
}
