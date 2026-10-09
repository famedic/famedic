<?php

namespace App\Services\LaboratoryPreparation;

use App\Models\AiExecution;

final class LaboratoryPreparationRedactionResult
{
    /**
     * @param  array<string, mixed>|null  $content
     */
    private function __construct(
        public readonly LaboratoryPreparationDecision $decision,
        public readonly ?array $content,
        public readonly ?string $technicalFailureReason,
        public readonly ?AiExecution $aiExecution,
    ) {}

    /**
     * @param  array<string, mixed>  $content
     */
    public static function aiRedacted(
        LaboratoryPreparationDecision $decision,
        array $content,
        AiExecution $aiExecution,
    ): self {
        return new self(
            decision: $decision,
            content: $content,
            technicalFailureReason: null,
            aiExecution: $aiExecution,
        );
    }

    public static function originalFallback(
        LaboratoryPreparationDecision $decision,
        ?string $technicalFailureReason = null,
        ?AiExecution $aiExecution = null,
    ): self {
        return new self(
            decision: $decision,
            content: null,
            technicalFailureReason: $technicalFailureReason,
            aiExecution: $aiExecution,
        );
    }

    public function usedOpenAiText(): bool
    {
        return $this->content !== null && $this->technicalFailureReason === null;
    }
}
