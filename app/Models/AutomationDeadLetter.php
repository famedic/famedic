<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationDeadLetter extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_REQUEUED = 'requeued';

    public const STATUS_DISCARDED = 'discarded';

    protected $fillable = [
        'automation_uuid',
        'automation_run_id',
        'driver',
        'handler',
        'entity_type',
        'entity_id',
        'payload',
        'error',
        'stack',
        'attempts',
        'last_execution_at',
        'status',
        'requeued_at',
        'discarded_at',
        'discarded_by',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'last_execution_at' => 'datetime',
            'requeued_at' => 'datetime',
            'discarded_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
