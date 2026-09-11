<?php

namespace App\Services\Laboratory;

readonly class CheckoutStepResolution
{
    public function __construct(
        public string $step,
        public ?string $message = null,
        public bool $updateDraft = false,
    ) {}

    public function shouldRedirect(?string $requestedStep, ?string $draftStep): bool
    {
        $effective = $requestedStep ?? $draftStep;

        if ($effective === null) {
            return false;
        }

        return $effective !== $this->step;
    }
}
