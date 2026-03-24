<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabOrderEventState extends Model
{
    use HasFactory;

    protected $fillable = [
        'gda_order_id',
        'laboratory_purchase_id',
        'total_studies',
        'sample_received_count',
        'results_received_count',
        'sample_email_sent_at',
        'results_email_sent_at',
        'sample_tag_sent_at',
        'results_tag_sent_at',
        'first_event_at',
        'last_event_at',
    ];

    protected $casts = [
        'sample_email_sent_at' => 'datetime',
        'results_email_sent_at' => 'datetime',
        'sample_tag_sent_at' => 'datetime',
        'results_tag_sent_at' => 'datetime',
        'first_event_at' => 'datetime',
        'last_event_at' => 'datetime',
    ];

    public function laboratoryPurchase(): BelongsTo
    {
        return $this->belongsTo(LaboratoryPurchase::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(LabOrderEventReceipt::class);
    }
}
