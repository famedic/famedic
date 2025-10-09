<?php

namespace App\Models;

use App\Actions\OnlinePharmacy\FetchProductAction;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OnlinePharmacyCartItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $appends = [
        'vitau_product',
        'formatted_price',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    protected function vitauProduct(): Attribute
    {
        return Attribute::make(
            get: fn() => cache()->remember(
                'vitau_product_' . $this->vitau_product_id,
                now()->addDay(),
                fn() => app(FetchProductAction::class)($this->vitau_product_id)
            )
        );
    }

    protected function formattedPrice(): Attribute
    {
        return Attribute::make(
            get: fn() => formattedPrice($this->vitau_product['price'])
        );
    }

    protected function price(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->vitau_product['price']
        );
    }
}
