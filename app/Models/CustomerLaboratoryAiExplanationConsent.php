<?php

namespace App\Models;

use App\Enums\CustomerLaboratoryAiExplanationConsentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerLaboratoryAiExplanationConsent extends Model
{
    protected $fillable = [
        'customer_id',
        'user_id',
        'status',
        'consent_version',
        'consented_at',
        'declined_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CustomerLaboratoryAiExplanationConsentStatus::class,
            'consented_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
