<?php

namespace App\Actions\Laboratories;

use App\Models\LaboratoryResultVersion;

final class ReclassifyLaboratoryResultVersionResult
{
    public function __construct(
        public readonly LaboratoryResultVersion $version,
        public readonly bool $changed,
        public readonly bool $extractionDispatched,
        public readonly bool $extractionSuppressed,
    ) {}
}
