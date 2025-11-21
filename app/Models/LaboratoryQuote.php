<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class LaboratoryQuote extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'customer_id',
        'laboratory_brand',
        'gda_order_id',
        'patient_name',
        'patient_paternal_lastname',
        'patient_maternal_lastname',
        'patient_phone',
        'patient_birth_date',
        'patient_gender',
        'appointment_id',
        'laboratory_purchase_id',
        'purchase_id',
        'contact_id',
        'address_id',
        'items',
        'subtotal',
        'discount',
        'total',
        'status',
        'gda_response',
        'gda_acuse',
        'gda_code_http',
        'gda_mensaje',
        'gda_description',
        'has_gda_warning',
        'gda_warning_message',
        'pdf_base64',
        'expires_at',
    ];

    protected $casts = [
        'items' => 'array',
        'gda_response' => 'array',
        'expires_at' => 'datetime',
        'patient_birth_date' => 'date',
    ];

    protected $appends = [
        'formatted_created_at',
        'formatted_total',
        'formatted_expires_at',
        'total_cents',
        'subtotal_cents',
        'discount_cents',
        'patient_full_name',
        'formatted_patient_birth_date',
        'formatted_patient_gender',
    ];

    // Relaciones
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(LaboratoryAppointment::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function laboratoryPurchase(): BelongsTo
    {
        return $this->belongsTo(LaboratoryPurchase::class);
    }

    // NUEVA RELACIÓN: Items de la cotización
    public function quoteItems(): HasMany
    {
        return $this->hasMany(LaboratoryQuoteItem::class);
    }

    // Accessors
    protected function formattedCreatedAt(): Attribute
    {
        return Attribute::make(
            get: fn() => localizedDate($this->created_at)?->isoFormat('D MMM Y h:mm a')
        );
    }

    protected function formattedExpiresAt(): Attribute
    {
        return Attribute::make(
            get: fn() => localizedDate($this->expires_at)?->isoFormat('D MMM Y h:mm a')
        );
    }

    protected function formattedTotal(): Attribute
    {
        return Attribute::make(
            get: fn() => formattedCentsPrice($this->total_cents),
        );
    }

    protected function totalCents(): Attribute
    {
        return Attribute::make(
            get: fn() => (int) ($this->total * 100)
        );
    }

    protected function subtotalCents(): Attribute
    {
        return Attribute::make(
            get: fn() => (int) ($this->subtotal * 100)
        );
    }

    protected function discountCents(): Attribute
    {
        return Attribute::make(
            get: fn() => (int) ($this->discount * 100)
        );
    }

    protected function patientFullName(): Attribute
    {
        return Attribute::make(
            get: function () {
                $name = $this->patient_name ?? '';
                $paternal = $this->patient_paternal_lastname ?? '';
                $maternal = $this->patient_maternal_lastname ?? '';
                
                return trim("$name $paternal $maternal");
            }
        );
    }

    protected function formattedPatientBirthDate(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->patient_birth_date?->isoFormat('D [de] MMM [de] YYYY')
        );
    }

    protected function formattedPatientGender(): Attribute
    {
        return Attribute::make(
            get: function () {
                return match($this->patient_gender) {
                    '1' => 'Masculino',
                    '2' => 'Femenino',
                    default => 'No especificado'
                };
            }
        );
    }

    // Scopes
    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('gda_acuse', 'LIKE', "%$search%")
                        ->orWhere('gda_order_id', 'LIKE', "%$search%")
                        ->orWhere('patient_name', 'LIKE', "%$search%")
                        ->orWhere('patient_paternal_lastname', 'LIKE', "%$search%")
                        ->orWhereHas('customer.user', function ($query) use ($search) {
                            $query->where('name', 'LIKE', "%$search%")
                                ->orWhere('email', 'LIKE', "%$search%");
                        });
                });
            })
            ->when($filters['laboratory_brand'] ?? null, function ($query, $brand) {
                $query->where('laboratory_brand', $brand);
            })
            ->when($filters['status'] ?? null, function ($query, $status) {
                $query->where('status', $status);
            });
    }

    // Métodos de negocio
    public function markAsConvertedToPurchase(LaboratoryPurchase $purchase): void
    {
        $this->update([
            'laboratory_purchase_id' => $purchase->id,
            'purchase_id' => $purchase->gda_order_id,
            'status' => 'converted_to_purchase'
        ]);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function canBeConvertedToPurchase(): bool
    {
        return $this->status === 'pending_branch_payment' && !$this->isExpired();
    }
}