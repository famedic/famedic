<?php

namespace App\Services\LaboratoryResults\Extraction;

final class LaboratoryResultExtractionQaBatchOptions
{
    public function __construct(
        public readonly int $limit = 20,
        public readonly ?string $source = 'gda',
        public readonly ?string $status = 'complete',
        public readonly ?string $environment = null,
        public readonly bool $forceVision = false,
        public readonly bool $verboseConflicts = false,
    ) {}
}
