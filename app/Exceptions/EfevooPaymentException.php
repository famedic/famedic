<?php

namespace App\Exceptions;

use Exception;

class EfevooPaymentException extends Exception
{
    protected $efevooErrorCode;
    protected $efevooResponse;

    public function __construct(string $message = '', string $errorCode = null, array $response = null)
    {
        parent::__construct($message);
        $this->efevooErrorCode = $errorCode;
        $this->efevooResponse = $response;
    }

    public function getEfevooErrorCode(): ?string
    {
        return $this->efevooErrorCode;
    }

    public function getEfevooResponse(): ?array
    {
        return $this->efevooResponse;
    }
}