<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stripe\BalanceTransaction;
use Stripe\Charge;
use Stripe\PaymentIntent;
use Stripe\Stripe;

class Transaction extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'transaction_amount_cents',
        'payment_method', // 'stripe', 'efevoopay', 'odessa'
        'reference_id',
        'details',
        'description',
        'gateway', // 'stripe', 'efevoopay', 'odessa'
        'gateway_transaction_id',
        'gateway_status',
        'gateway_response',
        'gateway_token',
        'gateway_processed_at',
    ];

    protected $casts = [
        'details' => 'array',
        'gateway_response' => 'array',
        'gateway_processed_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static $unguarded = true;
    
    protected $appends = [
        'formatted_amount',
        'formatted_created_at',
    ];

    protected function commissionCents(): Attribute
    {
        return Attribute::make(
            get: function () {
                $cachedCommission = $this->details['commission_cents'] ?? null;

                if ($cachedCommission !== null && $cachedCommission > 0) {
                    return $cachedCommission;
                }

                if ($this->payment_method === 'stripe' && $this->reference_id) {
                    return $this->fetchAndCacheStripeCommission();
                }

                return $cachedCommission ?? 0;
            }
        );
    }

    protected function formattedCommission(): Attribute
    {
        return Attribute::make(
            get: fn() => formattedCentsPrice($this->commission_cents)
        );
    }

    protected function formattedAmount(): Attribute
    {
        return Attribute::make(
            get: fn() => formattedCentsPrice($this->transaction_amount_cents)
        );
    }

    protected function formattedCreatedAt(): Attribute
    {
        return Attribute::make(
            get: fn() => localizedDate($this->created_at)->isoFormat('D MMM Y h:mm a')
        );
    }

    public function onlinePharmacyPurchases()
    {
        return $this->morphedByMany(OnlinePharmacyPurchase::class, 'transactionable');
    }

    /*public function laboratoryPurchases()
    {
        return $this->morphedByMany(LaboratoryPurchase::class, 'transactionable');
    }*/

    public function medicalAttentionSubscriptions()
    {
        return $this->morphedByMany(MedicalAttentionSubscription::class, 'transactionable');
    }

    private function fetchAndCacheStripeCommission(): int
    {
        try {
            Stripe::setApiKey(config('services.stripe.secret'));

            $paymentIntent = PaymentIntent::retrieve($this->reference_id);
            $stripeCharge = Charge::retrieve($paymentIntent->latest_charge);

            if ($stripeCharge->balance_transaction === null) {
                $this->updateQuietly(['details' => array_merge($this->details ?? [], [
                    'commission_cents' => 0,
                    'commission_fetched_at' => now()->toIso8601String(),
                ])]);

                return 0;
            }

            $balanceTransaction = BalanceTransaction::retrieve($stripeCharge->balance_transaction);
            $commissionCents = (int) ($balanceTransaction->fee ?? 0);

            $this->updateQuietly(['details' => array_merge($this->details ?? [], [
                'commission_cents' => $commissionCents,
                'commission_fetched_at' => now()->toIso8601String(),
            ])]);

            return $commissionCents;
        } catch (\Exception $e) {
            $this->updateQuietly(['details' => array_merge($this->details ?? [], [
                'commission_cents' => 0,
                'commission_fetched_at' => now()->toIso8601String(),
            ])]);

            return 0;
        }
    }

    /**
     * Obtener el cliente a través de los detalles
     */
    public function customer()
    {
        $customerId = $this->details['customer_id'] ?? null;
        if ($customerId) {
            return Customer::find($customerId);
        }
        return null;
    }

    /**
     * Obtener el token de EfevooPay
     */
    public function efevooToken()
    {
        $tokenId = $this->details['token_id'] ?? null;
        if ($tokenId) {
            return EfevooToken::find($tokenId);
        }
        return null;
    }

    /**
     * Formatear el monto
     */
    public function getFormattedAmountAttribute()
    {
        return number_format($this->transaction_amount_cents / 100, 2);
    }

    /**
     * Verificar si es transacción de EfevooPay
     */
    public function isEfevooPay(): bool
    {
        return $this->payment_method === 'efevoopay' || $this->gateway === 'efevoopay';
    }

    /**
     * Verificar si es transacción simulada
     */
    public function isSimulated(): bool
    {
        return $this->details['simulated'] ?? false;
    }
}
