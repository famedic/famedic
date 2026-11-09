<?php

namespace App\Services\Api\V1\Benefits;

use App\Models\Transaction;

final class UnavailableReservationReleaseAuthority implements ReservationReleaseAuthority
{
    public function definitivelyUnpaid(Transaction $payment, int $customerId): bool
    {
        return false;
    }
}
