<?php

namespace App\Models;

use App\Enums\LaboratoryResultEventType;
use App\Enums\LaboratoryResultStatus as LaboratoryResultStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaboratoryResultEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'laboratory_result_status_id',
        'laboratory_result_version_id',
        'event_type',
        'from_status',
        'to_status',
        'actor_type',
        'actor_id',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => LaboratoryResultEventType::class,
            'from_status' => LaboratoryResultStatusEnum::class,
            'to_status' => LaboratoryResultStatusEnum::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function resultStatus(): BelongsTo
    {
        return $this->belongsTo(LaboratoryResultStatus::class, 'laboratory_result_status_id');
    }

    public function resultVersion(): BelongsTo
    {
        return $this->belongsTo(LaboratoryResultVersion::class, 'laboratory_result_version_id');
    }
}
