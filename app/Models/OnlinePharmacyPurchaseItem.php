<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OnlinePharmacyPurchaseItem extends Model
{
    use HasFactory, SoftDeletes;

    protected static $unguarded = true;

    protected $appends = [
        'formatted_price',
        'formatted_subtotal',
        'formatted_tax',
        'formatted_discount',
        'formatted_total',
    ];

    protected function formattedPrice(): Attribute
    {
        return Attribute::make(
            get: fn() => formattedCentsPrice($this->price_cents),
        );
    }

    protected function formattedSubtotal(): Attribute
    {
        return Attribute::make(
            get: fn() => formattedCentsPrice($this->subtotal_cents),
        );
    }

    protected function formattedTax(): Attribute
    {
        return Attribute::make(
            get: fn() => formattedCentsPrice($this->tax_cents),
        );
    }

    protected function formattedDiscount(): Attribute
    {
        return Attribute::make(
            get: fn() => formattedCentsPrice($this->discount_cents),
        );
    }

    protected function formattedTotal(): Attribute
    {
        return Attribute::make(
            get: fn() => formattedCentsPrice($this->total_cents),
        );
    }

    public function onlinePharmacyPurchase()
    {
        return $this->belongsTo(OnlinePharmacyPurchase::class);
    }
}
