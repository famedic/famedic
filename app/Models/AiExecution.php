<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiExecution extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'domain',
        'feature',
        'subject_type',
        'subject_id',
        'prompt_id',
        'prompt_version',
        'model',
        'status',
        'input_hash',
        'request_payload_redacted',
        'response_payload',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'estimated_cost_usd',
        'duration_ms',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'prompt_version' => 'integer',
            'request_payload_redacted' => 'array',
            'response_payload' => 'array',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
            'estimated_cost_usd' => 'decimal:6',
            'duration_ms' => 'integer',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(AiPrompt::class, 'prompt_id');
    }

    public function laboratoryPurchasePreparationSummary(): HasOne
    {
        return $this->hasOne(LaboratoryPurchasePreparationSummary::class);
    }
}
