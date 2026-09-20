<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaboratoryResultExtractionQaMetric extends Model
{
    protected $fillable = [
        'laboratory_result_version_id',
        'text_report_id',
        'vision_report_id',
        'comparison_outcome',
        'text_observation_count',
        'vision_observation_count',
        'match_count',
        'conflict_count',
        'vision_only_count',
        'text_only_count',
        'unresolved_count',
        'match_rate',
        'conflict_rate',
        'vision_coverage',
        'vision_only_rate',
        'fallback_reasons',
        'text_extraction_status',
        'vision_extraction_status',
        'vision_confidence_avg',
        'vision_page_count',
        'ai_execution_id',
        'text_extractor_version',
        'vision_extractor_version',
        'prompt_version',
        'shadow_mode',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'text_observation_count' => 'integer',
            'vision_observation_count' => 'integer',
            'match_count' => 'integer',
            'conflict_count' => 'integer',
            'vision_only_count' => 'integer',
            'text_only_count' => 'integer',
            'unresolved_count' => 'integer',
            'match_rate' => 'decimal:4',
            'conflict_rate' => 'decimal:4',
            'vision_coverage' => 'decimal:4',
            'vision_only_rate' => 'decimal:4',
            'fallback_reasons' => 'array',
            'vision_confidence_avg' => 'decimal:4',
            'vision_page_count' => 'integer',
            'prompt_version' => 'integer',
            'shadow_mode' => 'boolean',
            'summary' => 'array',
        ];
    }

    public function resultVersion(): BelongsTo
    {
        return $this->belongsTo(LaboratoryResultVersion::class, 'laboratory_result_version_id');
    }

    public function textReport(): BelongsTo
    {
        return $this->belongsTo(LaboratoryResultReport::class, 'text_report_id');
    }

    public function visionReport(): BelongsTo
    {
        return $this->belongsTo(LaboratoryResultReport::class, 'vision_report_id');
    }

    public function aiExecution(): BelongsTo
    {
        return $this->belongsTo(AiExecution::class);
    }
}
