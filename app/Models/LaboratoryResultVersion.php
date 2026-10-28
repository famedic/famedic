<?php

namespace App\Models;

use App\Enums\LaboratoryResultPdfClassification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LaboratoryResultVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'laboratory_result_status_id',
        'laboratory_notification_id',
        'storage_path',
        'sha256',
        'source',
        'classification',
        'classification_reason',
        'matched_rule',
        'classifier',
        'classified_at',
        'pdf_available_at',
    ];

    protected function casts(): array
    {
        return [
            'classification' => LaboratoryResultPdfClassification::class,
            'classified_at' => 'datetime',
            'pdf_available_at' => 'datetime',
        ];
    }

    public function resultStatus(): BelongsTo
    {
        return $this->belongsTo(LaboratoryResultStatus::class, 'laboratory_result_status_id');
    }

    public function laboratoryNotification(): BelongsTo
    {
        return $this->belongsTo(LaboratoryNotification::class);
    }

    public function resultReports(): HasMany
    {
        return $this->hasMany(LaboratoryResultReport::class);
    }
}
