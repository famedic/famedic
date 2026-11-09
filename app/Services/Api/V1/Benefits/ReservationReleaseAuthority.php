<?php

namespace App\Services\Api\V1\Benefits;

use App\Models\Transaction;

interface ReservationReleaseAuthority
{
    public function definitivelyUnpaid(Transaction $payment, int $customerId): bool;
}
