<?php

namespace App\Models;

use App\Enums\LaboratoryResultExtractionMethod;
use App\Enums\LaboratoryResultExtractionStatus;
use App\Enums\LaboratoryResultReportSource;
use App\Enums\LaboratoryResultStructuredStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LaboratoryResultReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'laboratory_purchase_id',
        'laboratory_result_version_id',
        'source',
        'extraction_method',
        'extraction_status',
        'structured_status',
        'reported_at',
        'specimen_collected_at',
        'confidence_overall',
        'observation_count',
        'validation_errors',
        'raw_extraction_payload',
        'published_at',
        'superseded_at',
        'superseded_by_report_id',
        'ai_execution_id',
        'input_hash',
        'extractor_version',
        'prompt_version',
        'published_version_slot',
    ];

    protected function casts(): array
    {
        return [
            'source' => LaboratoryResultReportSource::class,
            'extraction_method' => LaboratoryResultExtractionMethod::class,
            'extraction_status' => LaboratoryResultExtractionStatus::class,
            'structured_status' => LaboratoryResultStructuredStatus::class,
            'reported_at' => 'datetime',
            'specimen_collected_at' => 'datetime',
            'confidence_overall' => 'decimal:4',
            'observation_count' => 'integer',
            'validation_errors' => 'array',
            'raw_extraction_payload' => 'array',
            'published_at' => 'datetime',
            'superseded_at' => 'datetime',
            'prompt_version' => 'integer',
            'published_version_slot' => 'integer',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(LaboratoryPurchase::class, 'laboratory_purchase_id');
    }

    public function resultVersion(): BelongsTo
    {
        return $this->belongsTo(LaboratoryResultVersion::class, 'laboratory_result_version_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(LaboratoryResultObservation::class);
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_report_id');
    }

    public function supersedes(): HasMany
    {
        return $this->hasMany(self::class, 'superseded_by_report_id');
    }

    public function aiExecution(): BelongsTo
    {
        return $this->belongsTo(AiExecution::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('structured_status', LaboratoryResultStructuredStatus::Published);
    }

    public function scopeActivePublished(Builder $query): Builder
    {
        return $query
            ->where('structured_status', LaboratoryResultStructuredStatus::Published)
            ->whereNotNull('published_version_slot');
    }

    public function scopeShadowQa(Builder $query): Builder
    {
        return $query
            ->where('structured_status', '!=', LaboratoryResultStructuredStatus::Published)
            ->where('extraction_method', LaboratoryResultExtractionMethod::Vision)
            ->where('raw_extraction_payload->shadow_qa', true);
    }

    public function isShadowQa(): bool
    {
        $payload = $this->raw_extraction_payload ?? [];

        return ($payload['shadow_qa'] ?? false) === true
            && $this->structured_status !== LaboratoryResultStructuredStatus::Published
            && $this->published_version_slot === null;
    }
}
