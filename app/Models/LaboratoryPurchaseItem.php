<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LaboratoryPurchaseItem extends Model
{
    use HasFactory, SoftDeletes;

    protected static $unguarded = true;

    protected $appends = [
        'formatted_price',
    ];

    protected function formattedPrice(): Attribute
    {
        return Attribute::make(
            get: fn() => formattedCentsPrice($this->price_cents),
        );
    }

    public function laboratoryPurchase()
    {
        return $this->belongsTo(LaboratoryPurchase::class);
    }
}
