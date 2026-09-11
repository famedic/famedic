<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LaboratoryStoreImportRun extends Model
{
    public const STATUS_RUNNING = 'RUNNING';

    public const STATUS_APPLYING = 'APPLYING';

    public const STATUS_BLOCKED = 'BLOCKED';

    public const STATUS_ROLLED_BACK = 'ROLLED_BACK';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_FAILED = 'FAILED';

    protected $guarded = [];

    protected $casts = [
        'dry_run' => 'boolean',
        'totals' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(LaboratoryStoreImportRow::class, 'run_id');
    }
}
