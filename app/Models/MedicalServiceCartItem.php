<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalServiceCartItem extends Model
{
    use HasFactory;

    public const ITEM_TYPE_MEMBERSHIP = 'membership';

    public const BASIC_ANNUAL_PLAN = 'basic_annual';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_cents' => 'integer',
            'subtotal_cents' => 'integer',
            'requires_appointment' => 'boolean',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(MedicalServiceCart::class, 'cart_id');
    }
}
