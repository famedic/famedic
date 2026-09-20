<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryResultReferenceStatus;

final class LaboratoryResultReferenceEvaluation
{
    public const METHOD = 'deterministic_reference_v1';

    public function __construct(
        public readonly LaboratoryResultReferenceStatus $status,
        public readonly bool $evaluated,
        public readonly string $reason,
        public readonly string $referenceKind,
        public readonly ?float $referenceLowUsed,
        public readonly ?float $referenceHighUsed,
    ) {}

    public function method(): string
    {
        return self::METHOD;
    }

    /**
     * @return array<string, mixed>
     */
    public function toMetadataPayload(): array
    {
        return [
            'method' => self::METHOD,
            'evaluated' => $this->evaluated,
            'reason' => $this->reason,
            'reference_kind' => $this->referenceKind,
            'status' => $this->status->value,
            'reference_low_used' => $this->referenceLowUsed,
            'reference_high_used' => $this->referenceHighUsed,
        ];
    }
}
