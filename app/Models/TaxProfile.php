<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaxProfile extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $appends = [
        'formatted_tax_regime',
        'formatted_cfdi_use',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    protected function formattedTaxRegime(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->tax_regime . ' - ' . config('taxregimes.regimes.' . $this->tax_regime)['name']
        );
    }

    protected function formattedCfdiUse(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->cfdi_use . ' - ' . config('taxregimes.uses.' . $this->cfdi_use)
        );
    }
}
