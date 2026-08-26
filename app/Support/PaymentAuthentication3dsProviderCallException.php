<?php

namespace App\Support;

use RuntimeException;
use Throwable;

class PaymentAuthentication3dsProviderCallException extends RuntimeException
{
    public function __construct(
        private string $failureStage,
        private string $exceptionCategory,
        private bool $requestDispatched = false,
        private bool $responseReceived = false,
        private ?int $httpStatus = null,
        private ?int $durationMs = null,
        ?Throwable $previous = null
    ) {
        parent::__construct('3DS provider call failed', 0, $previous);
    }

    public function failureStage(): string
    {
        return $this->failureStage;
    }

    public function exceptionCategory(): string
    {
        return $this->exceptionCategory;
    }

    public function requestDispatched(): bool
    {
        return $this->requestDispatched;
    }

    public function responseReceived(): bool
    {
        return $this->responseReceived;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function durationMs(): ?int
    {
        return $this->durationMs;
    }
}
