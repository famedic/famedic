<?php

declare(strict_types=1);

namespace App\Domain\Akubica\Benefits;

final class DisabledBenefitsAuthorizer implements BenefitsAuthorizer
{
    public function authorize(BenefitActor $actor, string $operation, int $now): void
    {
        throw new BenefitFailure('BENEFITS_PENDING_APPROVAL');
    }
}
