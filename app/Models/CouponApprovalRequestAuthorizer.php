<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponApprovalRequestAuthorizer extends Model
{
    protected $fillable = [
        'coupon_approval_request_id',
        'administrator_id',
        'user_id',
        'status',
        'acted_at',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'acted_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(CouponApprovalRequest::class, 'coupon_approval_request_id');
    }

    public function administrator(): BelongsTo
    {
        return $this->belongsTo(Administrator::class);
    }

    public function actedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
