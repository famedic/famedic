<?php

namespace App\Exceptions;

use RuntimeException;

class MedicalServiceMembershipActiveException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('El cliente ya tiene una membresía médica activa.');
    }
}
