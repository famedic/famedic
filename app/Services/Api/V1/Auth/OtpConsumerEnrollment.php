<?php

namespace App\Services\Api\V1\Auth;

use App\Models\User;
use Illuminate\Http\Request;

interface OtpConsumerEnrollment
{
    public function grant(User $verifiedUser, Request $request): ?OtpEnrollmentGrant;
}
