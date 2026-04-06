<?php

namespace App\Models;

use App\Enums\CouponPurchaseType;
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
    ];

    protected function casts(): array
    {
        return [
            'purchase_type' => CouponPurchaseType::class,
            'created_at' => 'datetime',
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
}
