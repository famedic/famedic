<?php

declare(strict_types=1);

namespace App\Domain\Akubica\Benefits;

interface BenefitsAuthorizer
{
    /** Must verify current consumer/PAT/delegation and explicit operation capability.
     * Must also require approved D01-D10 policy. Called before every replay.
     */
    public function authorize(BenefitActor $actor, string $operation, int $now): void;
}
