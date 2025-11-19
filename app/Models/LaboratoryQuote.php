<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\SoftDeletes;

class LaboratoryQuote extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'customer_id',
        'laboratory_brand',
        'appointment_id',
        'contact_id', 
        'address_id', 
        'items',
        'subtotal',
        'discount',
        'total',
        'status',
        'gda_response',
        'gda_acuse',
        'pdf_base64',
        'expires_at',
    ];

    protected $casts = [
        'items' => 'array',
        'gda_response' => 'array',
        'expires_at' => 'datetime',
    ];

    protected $appends = [
        'formatted_created_at',
        'formatted_total',
        'formatted_expires_at',
        'total_cents',
    ];

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

    // AGREGAR ESTAS RELACIONES:
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function laboratoryPurchase()
    {
        return $this->hasOne(LaboratoryPurchase::class);
    }

    // Accessors
    protected function formattedCreatedAt(): Attribute
    {
        return Attribute::make(
            get: fn () => localizedDate($this->created_at)?->isoFormat('D MMM Y h:mm a')
        );
    }

    protected function formattedExpiresAt(): Attribute
    {
        return Attribute::make(
            get: fn () => localizedDate($this->expires_at)?->isoFormat('D MMM Y h:mm a')
        );
    }

    protected function formattedTotal(): Attribute
    {
        return Attribute::make(
            get: fn () => formattedCentsPrice($this->total_cents),
        );
    }

    protected function totalCents(): Attribute
    {
        return Attribute::make(
            get: fn () => (int)($this->total * 100)
        );
    }

    // Scopes
    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('gda_acuse', 'LIKE', "%$search%")
                        ->orWhereHas('customer.user', function ($query) use ($search) {
                            $query->where('name', 'LIKE', "%$search%")
                                ->orWhere('email', 'LIKE', "%$search%");
                        });
                });
            });
    }
}