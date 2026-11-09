<?php

declare(strict_types=1);

namespace App\Domain\Akubica\Benefits;

interface MedicalPurchaseVerifier
{
    /** Must verify the authoritative first-party completed purchase, user, credit
     * and exact debit amount. No implementation or issuer is activated here.
     */
    public function verifiedDebit(BenefitActor $actor, string $purchaseReference): ?VerifiedMedicalDebit;
}
