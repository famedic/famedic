<?php

namespace App\Services\Api\V1\Auth;

use App\Models\User;

/** Server enrollment authority, never a client header, role or token name. */
interface OtpEnrollmentOperator
{
    public function operatorFor(AuthenticatedBackendClient $client, User $verifiedUser): ?User;
}
