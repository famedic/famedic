<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaboratoryPurchasePreparationSummary extends Model
{
    use HasFactory;

    public const STATUS_GENERATED = 'generated';

    public const STATUS_STALE = 'stale';

    protected $fillable = [
        'laboratory_purchase_id',
        'ai_execution_id',
        'source_hash',
        'status',
        'summary_text',
        'summary_json',
        'generated_at',
        'invalidated_at',
    ];

    protected function casts(): array
    {
        return [
            'summary_json' => 'array',
            'generated_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    public function laboratoryPurchase(): BelongsTo
    {
        return $this->belongsTo(LaboratoryPurchase::class);
    }

    public function aiExecution(): BelongsTo
    {
        return $this->belongsTo(AiExecution::class);
    }
}
