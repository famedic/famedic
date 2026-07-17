<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $appends = [
        'formatted_created_at',
        'has_invoice_xml',
    ];

    public function invoiceable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    protected function hasInvoiceXml(): Attribute
    {
        return Attribute::make(
            get: fn () => filled($this->invoice_xml)
        );
    }

    protected function formattedCreatedAt(): Attribute
    {
        return Attribute::make(
            get: fn () => localizedDate($this->created_at)?->isoFormat('D MMM Y h:mm a')
        );
    }
}
