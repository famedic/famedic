<?php

namespace App\Services\Api\V1\Auth;

use App\Models\User;

final class UnavailableOtpEnrollmentOperator implements OtpEnrollmentOperator
{
    public function operatorFor(AuthenticatedBackendClient $client, User $verifiedUser): ?User
    {
        return null;
    }
}
