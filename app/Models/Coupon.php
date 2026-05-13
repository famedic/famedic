<?php

namespace App\Models;

use App\Enums\CouponApprovalStatus;
use App\Enums\CouponType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_coupon_id',
        'code',
        'description',
        'coupon_concept_id',
        'concept_other',
        'amount_cents',
        'remaining_cents',
        'max_beneficiaries',
        'type',
        'is_active',
        'approval_status',
        'authorization_code_hash',
        'authorization_code_expires_at',
        'authorized_at',
        'authorized_by_user_id',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'is_active' => 'boolean',
            'approval_status' => CouponApprovalStatus::class,
            'authorization_code_expires_at' => 'datetime',
            'authorized_at' => 'datetime',
        ];
    }

    public function parentCoupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'parent_coupon_id');
    }

    public function childCoupons(): HasMany
    {
        return $this->hasMany(Coupon::class, 'parent_coupon_id');
    }

    public function couponUsers(): HasMany
    {
        return $this->hasMany(CouponUser::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CouponTransaction::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function authorizedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_user_id');
    }

    public function concept(): BelongsTo
    {
        return $this->belongsTo(CouponConcept::class, 'coupon_concept_id');
    }

    public function scopeRootCoupons(Builder $query): Builder
    {
        return $query->whereNull('parent_coupon_id');
    }
}
