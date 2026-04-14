<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class TaxProfile extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'razon_social',
        'rfc',
        'zipcode',
        'codigo_postal_original',
        'tax_regime',
        'regimen_fiscal_original',
        'cfdi_use',
        'fiscal_certificate',
        'tipo_persona',
        'fecha_emision_constancia',
        'fecha_inscripcion',
        'estatus_sat',
        'domicilio_fiscal',
        'actividades_economicas',
        'tipo_persona_confianza',
        'tipo_persona_detectado_por',
        'hash_constancia',
        'verificado_automaticamente',
        'fecha_verificacion',
    ];

    protected $casts = [
        'tipo_persona_confianza' => 'integer',
        'verificado_automaticamente' => 'boolean',
        'fecha_verificacion' => 'datetime',
        'fecha_inscripcion' => 'date',
    ];

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
            get: function () {
                if (! $this->tax_regime) {
                    return '—';
                }
                $label = config('taxregimes.regimes.'.$this->tax_regime)['name'] ?? '';

                return $label ? $this->tax_regime.' - '.$label : (string) $this->tax_regime;
            }
        );
    }

    protected function formattedCfdiUse(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->cfdi_use) {
                    return '—';
                }
                $label = config('taxregimes.uses.'.$this->cfdi_use);

                return $label ? $this->cfdi_use.' - '.$label : (string) $this->cfdi_use;
            }
        );
    }

    // Método para obtener el tipo de persona formateado
    public function getTipoPersonaFormattedAttribute()
    {
        return match ($this->tipo_persona) {
            'fisica' => 'Persona Física',
            'moral' => 'Persona Moral',
            default => 'Desconocido',
        };
    }

    // Método para verificar si el perfil fue verificado automáticamente
    public function getFueVerificadoAutomaticamenteAttribute()
    {
        return $this->verificado_automaticamente && $this->tipo_persona_confianza >= 80;
    }

    // Método para obtener la ruta del certificado
    public function getCertificateUrlAttribute()
    {
        if (! $this->fiscal_certificate) {
            return null;
        }

        return Storage::disk('private')->url($this->fiscal_certificate);
    }
}
