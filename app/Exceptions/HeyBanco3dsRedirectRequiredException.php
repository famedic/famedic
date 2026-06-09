<?php

namespace App\Exceptions;

use App\Models\Payment3dsSession;
use Exception;

class HeyBanco3dsRedirectRequiredException extends Exception
{
    public function __construct(
        public Payment3dsSession $session,
        public string $redirectUrl,
    ) {
        parent::__construct('Se requiere autenticación 3D Secure.');
    }
}
