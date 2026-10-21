<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicalServiceCart extends Model
{
    use HasFactory;

    public const SERVICE_LINE = 'medical_services';

    public const STATUS_ACTIVE = 'active';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'credit_applied_cents' => 'integer',
            'total_cents' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MedicalServiceCartItem::class, 'cart_id');
    }

    public function benefits(): HasMany
    {
        return $this->hasMany(MedicalServiceCartBenefit::class, 'cart_id');
    }

    public function scopeActive($query)
    {
        return $query->where('service_line', self::SERVICE_LINE)
            ->where('status', self::STATUS_ACTIVE);
    }
}
