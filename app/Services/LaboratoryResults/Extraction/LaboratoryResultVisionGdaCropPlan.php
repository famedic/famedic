<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryResultVisionPiiSafetyStatus;

final class LaboratoryResultVisionGdaCropPlan
{
    /**
     * @param  list<array{text: string, top: float, left: float, width: float, height: float}>  $wordsInCropRegion
     */
    public function __construct(
        public readonly LaboratoryResultVisionPiiSafetyStatus $status,
        public readonly ?float $cropTopPt = null,
        public readonly ?float $pageWidthPt = null,
        public readonly ?float $pageHeightPt = null,
        public readonly ?string $layoutFamily = null,
        public readonly ?string $cropMethod = null,
        public readonly ?string $qualityStatus = null,
        public readonly ?string $unsafeReason = null,
        public readonly array $wordsInCropRegion = [],
    ) {}
}
