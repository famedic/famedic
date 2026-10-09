<?php

namespace App\Models;

use App\Services\LaboratoryPreparation\LaboratoryPreparationDecision;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaboratoryPurchasePreparationSummary extends Model
{
    use HasFactory;

    public const STATUS_GENERATED = 'generated';

    public const STATUS_STALE = 'stale';

    public const DECISION_AUTO_CONSOLIDATED = LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED;

    public const DECISION_FALLBACK_ORIGINAL = LaboratoryPreparationDecision::STATUS_FALLBACK_ORIGINAL;

    public const FALLBACK_CLINICAL_CONFLICT = LaboratoryPreparationDecision::FALLBACK_CLINICAL_CONFLICT;

    public const FALLBACK_FUNCTIONAL_AMBIGUITY = LaboratoryPreparationDecision::FALLBACK_FUNCTIONAL_AMBIGUITY;

    public const FALLBACK_SOURCE_QUALITY = LaboratoryPreparationDecision::FALLBACK_SOURCE_QUALITY;

    public const FALLBACK_TECHNICAL_AI_FAILURE = LaboratoryPreparationDecision::FALLBACK_TECHNICAL_AI_FAILURE;

    protected $fillable = [
        'laboratory_purchase_id',
        'ai_execution_id',
        'source_hash',
        'status',
        'decision_status',
        'rules_version',
        'rules_applied',
        'fallback_reason',
        'fallback_category',
        'needs_provider_review',
        'summary_text',
        'summary_json',
        'generated_at',
        'invalidated_at',
        'notified_at',
        'notification_email_queued_at',
    ];

    protected function casts(): array
    {
        return [
            'rules_applied' => 'array',
            'needs_provider_review' => 'boolean',
            'summary_json' => 'array',
            'generated_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'notified_at' => 'datetime',
            'notification_email_queued_at' => 'datetime',
        ];
    }

    public function laboratoryPurchase(): BelongsTo
    {
        return $this->belongsTo(LaboratoryPurchase::class);
    }

    public function aiExecution(): BelongsTo
    {
        return $this->belongsTo(AiExecution::class);
    }

    public function hasFunctionalDecision(): bool
    {
        return $this->decision_status !== null;
    }

    public function isAutoConsolidated(): bool
    {
        return $this->decision_status === self::DECISION_AUTO_CONSOLIDATED;
    }

    public function isFallbackOriginal(): bool
    {
        return $this->decision_status === self::DECISION_FALLBACK_ORIGINAL;
    }

    public function applyPreparationDecision(LaboratoryPreparationDecision $decision): void
    {
        $this->forceFill([
            'decision_status' => $decision->status,
            'rules_version' => $decision->rulesVersion,
            'rules_applied' => $decision->rulesApplied,
            'fallback_reason' => $decision->fallbackReason,
            'fallback_category' => $decision->fallbackCategory,
            'needs_provider_review' => $decision->needsProviderReview,
        ]);
    }
}
