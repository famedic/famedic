<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponAuditLog extends Model
{
    protected $fillable = [
        'type',
        'action',
        'status',
        'actor_user_id',
        'approved_by_user_id',
        'coupon_id',
        'coupon_approval_request_id',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(CouponApprovalRequest::class, 'coupon_approval_request_id');
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
