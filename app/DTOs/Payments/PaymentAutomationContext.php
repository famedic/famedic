<?php

namespace App\DTOs\Payments;

use App\Models\Customer;
use App\Models\PaymentAttempt;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class PaymentAutomationContext
{
    /**
     * @param  Model|null  $order  LaboratoryPurchase|OnlinePharmacyPurchase|MedicalAttentionSubscription when linked
     */
    public function __construct(
        public readonly PaymentAttempt $attempt,
        public readonly ?Customer $customer,
        public readonly ?Model $order,
        public readonly ?Transaction $transaction,
        public readonly ?string $gateway,
        public readonly int $amountCents,
        public readonly string $status,
        public readonly ?string $reference,
        public readonly CarbonInterface $timestamp,
    ) {
    }

    /**
     * Structured payload for logs and future automation channels.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'payment_attempt_id' => $this->attempt->id,
            'customer_id' => $this->customer?->id,
            'order_type' => $this->order ? $this->order::class : null,
            'order_id' => $this->order?->getKey(),
            'transaction_id' => $this->transaction?->id,
            'gateway' => $this->gateway,
            'amount_cents' => $this->amountCents,
            'amount' => $this->amountCents / 100,
            'status' => $this->status,
            'reference' => $this->reference,
            'timestamp' => $this->timestamp->toIso8601String(),
            'processor_code' => $this->attempt->processor_code,
            'processor_message' => $this->attempt->processor_message,
            'processor_transaction_id' => $this->attempt->processor_transaction_id,
        ];
    }
}
