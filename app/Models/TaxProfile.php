<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'is_default' => 'boolean',
        'tipo_persona_confianza' => 'integer',
        'verificado_automaticamente' => 'boolean',
        'fecha_verificacion' => 'datetime',
        'fecha_inscripcion' => 'date',
    ];

    protected $appends = [
        'formatted_tax_regime',
        'formatted_cfdi_use',
        'formatted_activity_label',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoiceRequests(): HasMany
    {
        return $this->hasMany(InvoiceRequest::class);
    }

    /**
     * Un perfil se considera utilizado si existe cualquier solicitud vinculada,
     * incluida soft-deleted.
     */
    public function isUsed(): bool
    {
        return $this->invoiceRequests()->withTrashed()->exists();
    }

    /**
     * Serialización segura para props de paciente: solo campos del wizard/listado.
     * No expone paths de Storage ni metadatos internos de la constancia.
     */
    public function presentForPatient(): static
    {
        $visible = [
            'id',
            'name',
            'rfc',
            'zipcode',
            'tax_regime',
            'cfdi_use',
            'tipo_persona',
            'is_default',
            'formatted_tax_regime',
            'formatted_cfdi_use',
            'formatted_activity_label',
        ];

        // Solo cuando el esquema tiene invoice_requests (suites aisladas pueden omitirla).
        if (\Illuminate\Support\Facades\Schema::hasTable('invoice_requests')) {
            // Preferir withExists (incl. trashed) cuando el loader lo aportó; si no, isUsed().
            $isUsed = array_key_exists('used_invoice_requests_exist', $this->attributes)
                ? (bool) $this->attributes['used_invoice_requests_exist']
                : $this->isUsed();

            $this->setAttribute('is_used', $isUsed);
            $visible[] = 'is_used';
        }

        $this->setVisible($visible);

        return $this;
    }

    /**
     * Colección de perfiles activos del customer lista para props Inertia de paciente.
     * Usa withExists (con soft-deleted) para evitar N+1 de isUsed() sin cambiar su semántica pública.
     */
    public static function presentCollectionForPatient(Customer $customer): \Illuminate\Support\Collection
    {
        $query = $customer->taxProfiles();

        if (\Illuminate\Support\Facades\Schema::hasTable('invoice_requests')) {
            $query->withExists([
                'invoiceRequests as used_invoice_requests_exist' => function ($relationQuery) {
                    $relationQuery->withTrashed();
                },
            ]);
        }

        return $query->get()
            ->map->presentForPatient()
            ->values();
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

    protected function formattedActivityLabel(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->created_at) {
                    return null;
                }

                $wasUpdated = $this->updated_at
                    && $this->updated_at->gt($this->created_at->copy()->addMinute());

                $date = localizedDate($wasUpdated ? $this->updated_at : $this->created_at);
                if (! $date) {
                    return null;
                }

                $prefix = $wasUpdated ? 'Actualizado' : 'Creado';

                return $prefix.' el '.$date->locale('es')->isoFormat('D MMM Y');
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
