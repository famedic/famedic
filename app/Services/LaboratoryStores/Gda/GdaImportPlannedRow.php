<?php

namespace App\Services\LaboratoryStores\Gda;

class GdaImportPlannedRow
{
    public function __construct(
        public readonly GdaStoreRow|GdaSpecialServiceRow $row,
        public readonly ?string $brand,
        public readonly ?string $sourceName,
        public readonly string $normalizedName,
        public readonly ?int $matchedStoreId,
        public readonly string $classification,
        public readonly int $confidence,
        public readonly string $action,
        public readonly string $resolutionSource,
        public readonly ?string $resolutionDecision,
        public readonly ?int $manualResolutionId,
        public readonly string $autoClassification,
        public readonly string $autoAction,
        public readonly ?int $autoMatchedStoreId,
        public readonly string $validationStatus,
        public readonly array $invalidFields,
        public readonly array $warnings,
        public readonly array $evidence,
        public readonly array $diff,
        public readonly array $errors,
        public readonly array $rawPayload,
        public readonly array $plannedPayload,
    ) {}
}
