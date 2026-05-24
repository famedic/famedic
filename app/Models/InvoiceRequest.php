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
            get: function () {
                if (! $this->tax_regime) {
                    return null;
                }

                $regime = config('taxregimes.regimes.'.$this->tax_regime);

                return is_array($regime)
                    ? $this->tax_regime.' - '.($regime['name'] ?? $this->tax_regime)
                    : (string) $this->tax_regime;
            }
        );
    }

    protected function formattedCfdiUse(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->cfdi_use) {
                    return null;
                }

                $use = config('taxregimes.uses.'.$this->cfdi_use);

                return $use
                    ? $this->cfdi_use.' - '.$use
                    : (string) $this->cfdi_use;
            }
        );
    }

    protected function formattedCreatedAt(): Attribute
    {
        return Attribute::make(
            get: fn () => localizedDate($this->created_at)?->isoFormat('D MMM Y h:mm a')
        );
    }
}
