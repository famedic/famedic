<?php

namespace App\Services\LaboratoryStores\Gda;

class NormalizationResult
{
    public function __construct(
        public readonly mixed $value,
        public readonly array $warnings = [],
        public readonly array $errors = [],
        public readonly bool $manualReview = false,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
