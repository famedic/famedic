<?php

declare(strict_types=1);

namespace App\Domain\Akubica\Benefits;

/** Internal result of a trusted purchase verifier; never a public request DTO. */
final readonly class VerifiedMedicalDebit
{
    public function __construct(public string $purchaseReference, public int $userId, public string $sourceKey, public int $amountCents) {}
}
