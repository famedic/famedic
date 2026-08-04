<?php

namespace App\Models;

use App\Enums\CouponPurchaseType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponTransaction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'coupon_id',
        'user_id',
        'purchase_type',
        'purchase_id',
        'amount_used_cents',
        'reversed_at',
        'reversed_by_user_id',
        'reversal_reason',
    ];

    protected function casts(): array
    {
        return [
            'purchase_type' => CouponPurchaseType::class,
            'created_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CouponTransaction $model) {
            if ($model->created_at === null) {
                $model->created_at = now();
            }
        });
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reversedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by_user_id');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function scopeNotReversed(Builder $query): Builder
    {
        return $query->whereNull('reversed_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->notReversed();
    }
}
