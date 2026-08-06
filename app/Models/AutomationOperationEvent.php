<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationOperationEvent extends Model
{
    public const RESULT_SUCCESS = 'success';

    public const RESULT_FAILED = 'failed';

    public const RESULT_SKIPPED = 'skipped';

    public const RESULT_PARTIAL = 'partial';

    protected $fillable = [
        'automation',
        'driver',
        'channel',
        'operation',
        'result',
        'duration_ms',
        'retryable',
        'customer_id',
        'subject_type',
        'subject_id',
        'reference',
        'meta',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'retryable' => 'boolean',
            'duration_ms' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
