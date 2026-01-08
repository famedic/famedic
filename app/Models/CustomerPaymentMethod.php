<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerPaymentMethod extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'gateway',
        'gateway_payment_method_id',
        'gateway_token',
        'last_four',
        'brand',
        'card_type',
        'exp_month',
        'exp_year',
        'alias',
        'metadata',
        'gateway_response',
        'is_default',
        'is_verified',
        'is_active',
        'verified_at',
        'last_used_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'gateway_response' => 'array',
        'is_default' => 'boolean',
        'is_verified' => 'boolean',
        'is_active' => 'boolean',
        'verified_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    protected $appends = [
        'formatted_expiry',
        'masked_number',
        'is_expired',
        'brand_name',
        'brand_logo',
    ];

    /**
     * Relaciones
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'payment_method_id');
    }

    /**
     * Accesores
     */
    protected function formattedExpiry(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->exp_month . '/' . substr($this->exp_year, -2)
        );
    }

    protected function maskedNumber(): Attribute
    {
        return Attribute::make(
            get: fn () => '•••• •••• •••• ' . $this->last_four
        );
    }

    protected function isExpired(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (!$this->exp_year || !$this->exp_month) {
                    return false;
                }
                
                $expiryDate = \Carbon\Carbon::createFromDate(
                    $this->exp_year, 
                    $this->exp_month, 
                    1
                )->endOfMonth();
                
                return now()->gt($expiryDate);
            }
        );
    }

    protected function brandName(): Attribute
    {
        return Attribute::make(
            get: function () {
                return match(strtolower($this->brand)) {
                    'visa' => 'Visa',
                    'mastercard', 'master' => 'Mastercard',
                    'amex', 'american express' => 'American Express',
                    'discover' => 'Discover',
                    'diners' => 'Diners Club',
                    'unionpay' => 'UnionPay',
                    default => ucfirst($this->brand ?? 'Tarjeta'),
                };
            }
        );
    }

    protected function brandLogo(): Attribute
    {
        return Attribute::make(
            get: function () {
                return match(strtolower($this->brand)) {
                    'visa' => 'https://cdn.jsdelivr.net/npm/simple-icons@v9/icons/visa.svg',
                    'mastercard', 'master' => 'https://cdn.jsdelivr.net/npm/simple-icons@v9/icons/mastercard.svg',
                    'amex', 'american express' => 'https://cdn.jsdelivr.net/npm/simple-icons@v9/icons/americanexpress.svg',
                    'discover' => 'https://cdn.jsdelivr.net/npm/simple-icons@v9/icons/discover.svg',
                    'diners' => 'https://cdn.jsdelivr.net/npm/simple-icons@v9/icons/dinersclub.svg',
                    default => null,
                };
            }
        );
    }

    protected function canBeUsed(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->is_active 
                && $this->is_verified 
                && !$this->is_expired
        );
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeByGateway($query, $gateway)
    {
        return $query->where('gateway', $gateway);
    }

    public function scopeUsable($query)
    {
        return $query->active()->verified();
    }

    /**
     * Métodos de negocio
     */
    public function markAsDefault(): self
    {
        // Quitar default de otras tarjetas del mismo cliente
        $this->customer->paymentMethods()
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);
        
        return $this;
    }

    public function markAsVerified(): self
    {
        $this->update([
            'is_verified' => true,
            'verified_at' => now(),
        ]);
        
        return $this;
    }

    public function markAsUsed(): self
    {
        $this->update(['last_used_at' => now()]);
        return $this;
    }

    public function deactivate(): self
    {
        $this->update(['is_active' => false]);
        
        // Si era la tarjeta por defecto, asignar otra activa
        if ($this->is_default) {
            $newDefault = $this->customer->paymentMethods()
                ->active()
                ->verified()
                ->where('id', '!=', $this->id)
                ->first();
            
            if ($newDefault) {
                $newDefault->markAsDefault();
            }
        }
        
        return $this;
    }

    /**
     * Validaciones
     */
    public function belongsToUser($user): bool
    {
        return $this->customer_id === optional($user->customer)->id;
    }
}