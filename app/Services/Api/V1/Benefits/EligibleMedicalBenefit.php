<?php

declare(strict_types=1);

namespace App\Services\Api\V1\Benefits;

/** Internal projection only. No model, administrative label, code or JSON ID. */
final readonly class EligibleMedicalBenefit
{
    public function __construct(private int $sourceId, private string $kind, private int $amountCents) {}

    public function sourceIdForInternalBinding(): int
    {
        return $this->sourceId;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function maxApplicableCents(): int
    {
        return $this->amountCents;
    }
}
