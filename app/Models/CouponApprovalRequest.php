<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CouponApprovalRequest extends Model
{
    protected $fillable = [
        'type',
        'status',
        'requested_by_user_id',
        'rejected_by_user_id',
        'coupon_id',
        'required_approvals',
        'current_approvals',
        'before_state',
        'after_state',
        'payload',
        'rejected_at',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'before_state' => 'array',
            'after_state' => 'array',
            'payload' => 'array',
            'rejected_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function authorizers(): HasMany
    {
        return $this->hasMany(CouponApprovalRequestAuthorizer::class);
    }
}
