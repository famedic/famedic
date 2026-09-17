<?php

namespace App\Services\LaboratoryResults;

final class LaboratoryPurchaseResultCompletion
{
    /**
     * @param  array<string, mixed>  $counts
     */
    public function __construct(
        public readonly bool $isComplete,
        public readonly string $reason,
        public readonly int $totalRequired,
        public readonly int $complete,
        public readonly int $pending,
        public readonly int $manualReview,
        public readonly int $error,
        public readonly int $missing,
        public readonly bool $legacyFallback,
        public readonly array $counts = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'semantic_ready' => $this->isComplete,
            'reason' => $this->reason,
            'total_required' => $this->totalRequired,
            'complete' => $this->complete,
            'pending' => $this->pending,
            'manual_review' => $this->manualReview,
            'error' => $this->error,
            'missing' => $this->missing,
            'legacy_fallback' => $this->legacyFallback,
        ];
    }
}
