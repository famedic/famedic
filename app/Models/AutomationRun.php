<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AutomationRun extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_RETRYING = 'retrying';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DEAD_LETTER = 'dead_letter';

    protected $fillable = [
        'automation_uuid',
        'driver',
        'driver_class',
        'handler',
        'entity_type',
        'entity_id',
        'channel',
        'attempt',
        'started_at',
        'finished_at',
        'duration_ms',
        'status',
        'retryable',
        'error',
        'payload',
        'response',
        'next_retry_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'response' => 'array',
            'retryable' => 'boolean',
            'attempt' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'next_retry_at' => 'datetime',
        ];
    }

    public function isTerminalSuccess(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_DEAD_LETTER,
        ], true);
    }

    public function retryHistory(): HasMany
    {
        return $this->hasMany(AutomationRetryHistory::class);
    }

    public function deadLetter(): HasOne
    {
        return $this->hasOne(AutomationDeadLetter::class);
    }
}
