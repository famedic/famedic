<?php

namespace App\Exceptions;

class PayPalRecoveryConfirmationPendingException extends \RuntimeException
{
    public function __construct(
        public readonly ?string $supportReference = null,
        public readonly string $errorCode = 'recovery_confirmation_pending',
        public readonly int $httpStatus = 503,
    ) {
        parent::__construct(
            'No pudimos confirmar PayPal en este momento. No vuelvas a intentarlo mientras verificamos el estado.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'message' => $this->getMessage(),
            'error' => $this->errorCode,
            'support_reference' => $this->supportReference,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
