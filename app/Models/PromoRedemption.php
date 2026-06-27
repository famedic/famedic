<?php

namespace App\Models;

use App\Enums\PromoRedemptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromoRedemption extends Model
{
    protected $fillable = [
        'promo_code_id',
        'user_id',
        'customer_id',
        'coupon_id',
        'purchase_type',
        'purchase_id',
        'status',
        'discount_cents',
        'validation_token',
        'cart_hash',
        'ip_address',
        'user_agent',
        'validated_at',
        'confirmed_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PromoRedemptionStatus::class,
            'discount_cents' => 'integer',
            'validated_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function isValidationExpired(): bool
    {
        if ($this->validated_at === null) {
            return true;
        }

        return $this->validated_at->lt(
            now()->subMinutes((int) config('promo_codes.validation_ttl_minutes', 15))
        );
    }
}
