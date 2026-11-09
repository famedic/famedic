<?php

namespace App\Services\Api\V1\Auth;

use App\Models\ApiConsumerDelegation;
use App\Models\User;

final readonly class OtpEnrollmentGrant
{
    public function __construct(public User $operator, public ApiConsumerDelegation $delegation, public array $capabilities, public int $expiresAt) {}
}
