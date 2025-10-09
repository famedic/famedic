<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvoiceRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $appends = [
        'formatted_tax_regime',
        'formatted_cfdi_use',
        'formatted_created_at',
    ];

    public function invoiceRequestable(): MorphTo
    {
        return $this->morphTo();
    }

    protected function formattedTaxRegime(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->tax_regime.' - '.config('taxregimes.regimes.'.$this->tax_regime)['name']
        );
    }

    protected function formattedCfdiUse(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->cfdi_use.' - '.config('taxregimes.uses.'.$this->cfdi_use)
        );
    }

    protected function formattedCreatedAt(): Attribute
    {
        return Attribute::make(
            get: fn () => localizedDate($this->created_at)?->isoFormat('D MMM Y h:mm a')
        );
    }
}
