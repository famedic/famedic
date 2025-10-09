<?php

namespace App\Exceptions;

use Exception;

class MurguiaConflictException extends Exception
{
    public function __construct(string $message = 'Conflict with Murguia API (duplicate user or resource)', int $code = 409, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}