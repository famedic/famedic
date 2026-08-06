<?php

namespace App\DTOs\Orders;

use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\MedicalAttentionSubscription;
use App\Models\OnlinePharmacyPurchase;
use App\Models\PaymentAttempt;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class OrderAutomationContext
{
    public const CHANNEL_LABORATORY = 'laboratory';

    public const CHANNEL_PHARMACY = 'pharmacy';

    public const CHANNEL_MEMBERSHIP = 'membership';

    /**
     * @param  Model|null  $order  Primary order entity for this automation channel
     */
    public function __construct(
        public readonly ?Model $order,
        public readonly ?Customer $customer,
        public readonly ?Transaction $transaction,
        public readonly ?PaymentAttempt $paymentAttempt,
        public readonly ?LaboratoryPurchase $laboratoryPurchase,
        public readonly ?OnlinePharmacyPurchase $pharmacyOrder,
        public readonly ?MedicalAttentionSubscription $membership,
        public readonly int $amountCents,
        public readonly ?string $reference,
        public readonly ?string $gateway,
        public readonly CarbonInterface $createdAt,
        public readonly string $channel,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'channel' => $this->channel,
            'order_type' => $this->order ? $this->order::class : null,
            'order_id' => $this->order?->getKey(),
            'customer_id' => $this->customer?->id,
            'transaction_id' => $this->transaction?->id,
            'payment_attempt_id' => $this->paymentAttempt?->id,
            'laboratory_purchase_id' => $this->laboratoryPurchase?->id,
            'pharmacy_order_id' => $this->pharmacyOrder?->id,
            'membership_id' => $this->membership?->id,
            'amount_cents' => $this->amountCents,
            'amount' => $this->amountCents / 100,
            'reference' => $this->reference,
            'gateway' => $this->gateway,
            'created_at' => $this->createdAt->toIso8601String(),
        ];
    }
}
