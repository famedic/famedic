<?php

declare(strict_types=1);

namespace App\Services\Api\V1\Benefits;

use App\Models\Transaction;

/** Server-owned payment evidence, never supplied by a consumer or inferred from a reference. */
interface MedicalPaymentAuthority
{
    public function confirmed(Transaction $payment, int $customerId, int $cashCents): bool;

    public function reversed(Transaction $payment, int $customerId): bool;
}
