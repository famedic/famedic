<?php

namespace App\Support;

use RuntimeException;

class PaymentAuthenticationRecoveryStartException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $evaluation
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $error,
        public readonly array $evaluation = []
    ) {
        parent::__construct($message, $status);
    }

    /**
     * @param  array<string, mixed>  $evaluation
     */
    public static function blocked(string $message, string $error, array $evaluation = []): self
    {
        $status = match ($error) {
            'active_attempt_exists' => 409,
            'recovery_limit_reached' => 429,
            'cooldown_active' => 429,
            default => 409,
        };

        return new self($message, $status, $error, $evaluation);
    }
}
