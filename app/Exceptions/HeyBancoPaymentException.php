<?php

namespace App\Exceptions;

use Exception;

class HeyBancoPaymentException extends Exception
{
    public function __construct(
        string $message,
        public ?string $processorCode = null,
        public ?string $processorMessage = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
