<?php

namespace App\Services\LaboratoryPreparation;

final readonly class LaboratoryPreparationEligibilityResult
{
    public function __construct(
        public bool $eligible,
        public string $reason,
        public ?string $paymentMethod = null,
    ) {}

    public static function allow(string $paymentMethod): self
    {
        return new self(true, 'eligible', $paymentMethod);
    }

    public static function deny(string $reason, ?string $paymentMethod = null): self
    {
        return new self(false, $reason, $paymentMethod);
    }
}
