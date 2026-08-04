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
        'formatted_completed_at',
        'formatted_updated_at',
        'has_invoice_xml',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    public function invoiceable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    /**
     * Prepara el modelo para props de paciente: oculta paths de Storage
     * y expone solo URLs de rutas autorizadas.
     */
    public function presentForPatient(): static
    {
        $hasXml = filled($this->attributes['invoice_xml'] ?? null);

        $this->makeHidden(['invoice', 'invoice_xml']);
        $this->setAttribute('invoice_url', route('invoice', ['invoice' => $this->id]));
        $this->setAttribute(
            'invoice_xml_url',
            $hasXml ? route('invoice.xml', ['invoice' => $this->id]) : null
        );

        return $this;
    }

    public function isDocumentComplete(): bool
    {
        return filled($this->attributes['invoice'] ?? null)
            && filled($this->attributes['invoice_xml'] ?? null);
    }

    protected function hasInvoiceXml(): Attribute
    {
        return Attribute::make(
            get: fn () => filled($this->attributes['invoice_xml'] ?? null)
        );
    }

    protected function formattedCreatedAt(): Attribute
    {
        return Attribute::make(
            get: fn () => localizedDate($this->created_at)?->isoFormat('D MMM Y h:mm a')
        );
    }

    protected function formattedCompletedAt(): Attribute
    {
        return Attribute::make(
            get: fn () => localizedDate($this->completed_at)?->isoFormat('D MMM Y h:mm a')
        );
    }

    protected function formattedUpdatedAt(): Attribute
    {
        return Attribute::make(
            get: fn () => localizedDate($this->updated_at)?->isoFormat('D MMM Y h:mm a')
        );
    }
}
