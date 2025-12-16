<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\SoftDeletes;

class LaboratoryQuoteItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'gda_id',
        'name',
        'description',
        'feature_list',
        'indications',
        'price_cents',
        'quantity',
        'laboratory_quote_id',
    ];

    protected $casts = [
        'feature_list' => 'array',
    ];

    protected $appends = [
        'formatted_price',
        'total_price_cents',
        'formatted_total_price',
    ];

    // Relaciones
    public function laboratoryQuote(): BelongsTo
    {
        return $this->belongsTo(LaboratoryQuote::class);
    }

    // Accessors
    protected function formattedPrice(): Attribute
    {
        return Attribute::make(
            get: fn() => formattedCentsPrice($this->price_cents)
        );
    }

    protected function totalPriceCents(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->price_cents * $this->quantity
        );
    }

    protected function formattedTotalPrice(): Attribute
    {
        return Attribute::make(
            get: fn() => formattedCentsPrice($this->total_price_cents)
        );
    }

    // Métodos de negocio
    public function isPackage(): bool
    {
        return !empty($this->feature_list) && is_array($this->feature_list);
    }

    public function getFeaturesCount(): int
    {
        return $this->isPackage() ? count($this->feature_list) : 0;
    }
}