<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalServiceCartBenefit extends Model
{
    use HasFactory;

    public const TYPE_COUPON = 'coupon';

    public const TYPE_CREDIT = 'credit';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'applied_cents' => 'integer',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(MedicalServiceCart::class, 'cart_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
