<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponUser extends Model
{
    protected $table = 'coupon_user';

    public $timestamps = false;

    protected $fillable = [
        'coupon_id',
        'user_id',
        'assigned_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'used_at' => 'datetime',
        ];
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
