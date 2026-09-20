<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Models\AiExecution;

final class LaboratoryResultVisionExtractionResult
{
    /**
     * @param  list<LaboratoryResultObservationCandidate>  $candidates
     * @param  list<int>  $pagesSent
     */
    public function __construct(
        public readonly bool $success,
        public readonly array $candidates = [],
        public readonly array $pagesSent = [],
        public readonly ?AiExecution $aiExecution = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly int $durationMs = 0,
        public readonly ?int $promptVersion = null,
        public readonly ?string $extractorVersion = null,
        public readonly ?string $inputHash = null,
        public readonly bool $skippedIdempotent = false,
    ) {}
}
