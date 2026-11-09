<?php

declare(strict_types=1);

namespace App\Services\Api\V1\Benefits;

use App\Models\Transaction;

final class UnavailableMedicalPaymentAuthority implements MedicalPaymentAuthority
{
    public function confirmed(Transaction $payment, int $customerId, int $cashCents): bool
    {
        return false;
    }

    public function reversed(Transaction $payment, int $customerId): bool
    {
        return false;
    }
}
