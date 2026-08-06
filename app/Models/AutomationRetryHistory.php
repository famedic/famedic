<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationRetryHistory extends Model
{
    protected $table = 'automation_retry_history';

    protected $fillable = [
        'automation_uuid',
        'automation_run_id',
        'attempt',
        'delay_seconds',
        'reason',
        'http_status',
        'error',
        'scheduled_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'delay_seconds' => 'integer',
            'http_status' => 'integer',
            'scheduled_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }
}
