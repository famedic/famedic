<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaboratoryBillingReportRun extends Model
{
    public const TYPE_SCHEDULED = 'scheduled';
    public const TYPE_MANUAL = 'manual';
    public const TYPE_TEST = 'test';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'intended_for_at' => 'datetime',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'backlog_as_of' => 'datetime',
            'recipients' => 'array',
            'filters' => 'array',
            'metrics' => 'array',
            'file_size' => 'integer',
            'link_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'sent_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(LaboratoryBillingReportSchedule::class, 'schedule_id');
    }

    public function alreadySent(): bool
    {
        return $this->status === self::STATUS_SENT && $this->sent_at !== null;
    }
}
