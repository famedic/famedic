<?php

namespace App\Models;

use App\Enums\PromoType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromoCode extends Model
{
    use HasFactory;
    protected $fillable = [
        'coupon_id',
        'code',
        'promo_type',
        'max_redemptions',
        'max_uses_per_user',
        'redemptions_count',
        'reserved_count',
        'assigned_user_id',
        'assigned_email',
        'assigned_phone',
        'influencer_name',
        'event_name',
        'is_active',
        'metadata',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'promo_type' => PromoType::class,
            'max_redemptions' => 'integer',
            'max_uses_per_user' => 'integer',
            'redemptions_count' => 'integer',
            'reserved_count' => 'integer',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoRedemption::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function hasRemainingCapacity(): bool
    {
        if ($this->max_redemptions === null) {
            return true;
        }

        return $this->redemptions_count < $this->max_redemptions;
    }
}
