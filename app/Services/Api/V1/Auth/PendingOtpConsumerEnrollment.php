<?php

namespace App\Services\Api\V1\Auth;

use App\Models\User;
use Illuminate\Http\Request;

/** Default: legacy issuance remains unbound and cannot pass registered API guards. */
final class PendingOtpConsumerEnrollment implements OtpConsumerEnrollment
{
    public function grant(User $verifiedUser, Request $request): ?OtpEnrollmentGrant
    {
        return null;
    }
}
