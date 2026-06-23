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
        'valid_from',
        'expires_at',
        'min_purchase_cents',
        'max_beneficiaries',
        'type',
        'is_active',
        'approval_status',
        'authorization_code_hash',
        'authorization_code_expires_at',
        'authorized_at',
        'authorized_by_user_id',
        'rejected_reason',
        'rejected_by_user_id',
        'rejected_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $appends = [
        'formatted_min_purchase',
        'validity_status',
    ];

    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'is_active' => 'boolean',
            'approval_status' => CouponApprovalStatus::class,
            'valid_from' => 'datetime',
            'expires_at' => 'datetime',
            'min_purchase_cents' => 'integer',
            'authorization_code_expires_at' => 'datetime',
            'authorized_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function isNotYetValid(): bool
    {
        return $this->valid_from !== null && now()->lt($this->valid_from);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->gt($this->expires_at);
    }

    public function isWithinValidityWindow(): bool
    {
        return ! $this->isNotYetValid() && ! $this->isExpired();
    }

    public function hasMinimumPurchaseRequirement(): bool
    {
        return $this->min_purchase_cents !== null && $this->min_purchase_cents > 0;
    }

    public function meetsMinimumPurchase(int $purchaseTotalCents): bool
    {
        if (! $this->hasMinimumPurchaseRequirement()) {
            return true;
        }

        return $purchaseTotalCents >= (int) $this->min_purchase_cents;
    }

    public function getFormattedMinPurchaseAttribute(): ?string
    {
        if (! $this->hasMinimumPurchaseRequirement()) {
            return null;
        }

        return formattedCentsPrice((int) $this->min_purchase_cents);
    }

    public function getValidityStatusAttribute(): string
    {
        if ($this->valid_from === null && $this->expires_at === null) {
            return 'sin_vigencia';
        }

        if ($this->isNotYetValid()) {
            return 'programado';
        }

        if ($this->isExpired()) {
            return 'vencido';
        }

        return 'vigente';
    }

    public function scopeWithinValidityWindow(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
            })
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now);
            });
    }

    public function parentCoupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'parent_coupon_id');
    }

    public function childCoupons(): HasMany
    {
        return $this->hasMany(Coupon::class, 'parent_coupon_id');
    }

    public function beneficiaries(): HasMany
    {
        return $this->hasMany(CouponBeneficiary::class, 'parent_coupon_id');
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
