<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabOrderEventReceipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'lab_order_event_state_id',
        'event_type',
        'study_external_id',
        'provider_event_id',
        'payload_hash',
    ];

    public function state(): BelongsTo
    {
        return $this->belongsTo(LabOrderEventState::class, 'lab_order_event_state_id');
    }
}
