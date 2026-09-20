<?php

namespace App\Models;

use App\Enums\LaboratoryResultAbnormalSource;
use App\Enums\LaboratoryResultExtractionMethod;
use App\Enums\LaboratoryResultObservationValueType;
use App\Enums\LaboratoryResultReferenceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LaboratoryResultObservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'laboratory_result_report_id',
        'laboratory_analyte_id',
        'analyte_code',
        'analyte_name_raw',
        'analyte_name_display',
        'numeric_value',
        'text_value',
        'value_type',
        'unit',
        'unit_raw',
        'reference_low',
        'reference_high',
        'reference_text',
        'reference_status',
        'abnormal_flag',
        'abnormal_source',
        'laboratory_purchase_item_id',
        'panel_name_raw',
        'reported_at',
        'extraction_method',
        'confidence',
        'source_page',
        'source_bbox',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'numeric_value' => 'decimal:6',
            'value_type' => LaboratoryResultObservationValueType::class,
            'reference_low' => 'decimal:6',
            'reference_high' => 'decimal:6',
            'reference_status' => LaboratoryResultReferenceStatus::class,
            'abnormal_flag' => 'boolean',
            'abnormal_source' => LaboratoryResultAbnormalSource::class,
            'reported_at' => 'datetime',
            'extraction_method' => LaboratoryResultExtractionMethod::class,
            'confidence' => 'decimal:4',
            'source_page' => 'integer',
            'source_bbox' => 'array',
            'metadata' => 'array',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(LaboratoryResultReport::class, 'laboratory_result_report_id');
    }

    public function analyte(): BelongsTo
    {
        return $this->belongsTo(LaboratoryAnalyte::class, 'laboratory_analyte_id');
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(LaboratoryPurchaseItem::class, 'laboratory_purchase_item_id');
    }

    public function aiExplanations(): HasMany
    {
        return $this->hasMany(LaboratoryResultAiExplanation::class, 'laboratory_result_observation_id');
    }

    public function scopeShadowQa(Builder $query): Builder
    {
        return $query->whereHas('report', fn (Builder $reportQuery) => $reportQuery->shadowQa());
    }
}
