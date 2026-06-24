<?php

namespace App\Models;

use App\Services\EfevooPayCommissionCalculator;
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
        'payment_method', // 'stripe', 'efevoopay', 'odessa', 'paypal'
        'payment_provider',
        'reference_id',
        'provider_order_id',
        'provider_transaction_id',
        'payment_status',
        'details',
        'description',
        'gateway', // 'stripe', 'efevoopay', 'odessa', 'paypal'
        'gateway_transaction_id',
        'gateway_status',
        'gateway_response',
        'raw_response',
        'gateway_token',
        'gateway_processed_at',
    ];

    protected $casts = [
        'details' => 'array',
        'gateway_response' => 'array',
        'raw_response' => 'array',
        'gateway_processed_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static $unguarded = true;
    
    protected $appends = [
        'formatted_amount',
        'formatted_created_at',
        'efevoo_commission_breakdown',
    ];

    protected function commissionCents(): Attribute
    {
        return Attribute::make(
            get: function () {
                $cachedCommission = $this->details['commission_cents'] ?? null;

                if ($cachedCommission !== null && (int) $cachedCommission > 0) {
                    return (int) $cachedCommission;
                }

                if ($this->isEfevooPay() && $this->transaction_amount_cents !== null) {
                    return EfevooPayCommissionCalculator::calculate(
                        (int) $this->transaction_amount_cents
                    )['total_cents'];
                }

                if ($this->payment_method === 'stripe' && $this->reference_id) {
                    return $this->fetchAndCacheStripeCommission();
                }

                return (int) ($cachedCommission ?? 0);
            }
        );
    }

    protected function efevooCommissionBreakdown(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->isEfevooPay() || $this->transaction_amount_cents === null) {
                    return null;
                }

                return EfevooPayCommissionCalculator::present((int) $this->transaction_amount_cents);
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
            get: fn () => $this->transaction_amount_cents !== null
                ? formattedCentsPrice($this->transaction_amount_cents)
                : null
        );
    }

    protected function formattedCreatedAt(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->created_at
                ? localizedDate($this->created_at)->isoFormat('D MMM Y h:mm a')
                : null
        );
    }

    public function onlinePharmacyPurchases()
    {
        return $this->morphedByMany(OnlinePharmacyPurchase::class, 'transactionable');
    }

    public function laboratoryPurchases()
    {
        return $this->morphedByMany(LaboratoryPurchase::class, 'transactionable');
    }

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
     * Comisión para exportaciones (Excel). EfevooPay usa el cálculo manual 2.9% + IVA;
     * otros procesadores conservan su lógica existente.
     */
    public function exportCommissionCents(): int
    {
        if ($this->isEfevooPay() && $this->transaction_amount_cents !== null) {
            return EfevooPayCommissionCalculator::calculate(
                (int) $this->transaction_amount_cents
            )['total_cents'];
        }

        return $this->commission_cents;
    }

    public function isPayPal(): bool
    {
        return $this->payment_method === 'paypal' || $this->gateway === 'paypal';
    }

    /**
     * Verificar si es transacción simulada
     */
    public function isSimulated(): bool
    {
        return $this->details['simulated'] ?? false;
    }

    public function isSuccessfulPayment(): bool
    {
        $status = strtolower((string) ($this->payment_status ?? ''));

        if (in_array($status, ['failed', 'refunded', 'declined', 'pending'], true)) {
            return false;
        }

        if (in_array($status, [
            'captured',
            'completed',
            'paid',
            'success',
            'succeeded',
            'credit',
        ], true)) {
            return true;
        }

        $gatewayStatus = strtolower((string) ($this->gateway_status ?? ''));

        if (in_array($gatewayStatus, [
            'completed',
            'captured',
            'paid',
            'success',
            'succeeded',
        ], true)) {
            return true;
        }

        return filled($this->reference_id);
    }
}
