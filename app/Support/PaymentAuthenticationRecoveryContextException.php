<?php

namespace App\Support;

use RuntimeException;

class PaymentAuthenticationRecoveryContextException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $error
    ) {
        parent::__construct($message, $status);
    }

    public static function notFound(): self
    {
        return new self('No se encontro el contexto de recuperacion.', 404, 'recovery_context_not_found');
    }

    public static function expired(): self
    {
        return new self('El contexto de recuperacion expiro. Vuelve a iniciar desde el checkout.', 409, 'recovery_context_expired');
    }

    public static function invalidStatus(): self
    {
        return new self('El contexto de recuperacion no permite esta operacion.', 409, 'recovery_context_invalid_status');
    }

    public static function invalidOrigin(string $message = 'El origen de alta de tarjeta no es valido.'): self
    {
        return new self($message, 422, 'recovery_context_invalid_origin');
    }

    public static function invalidReturnUrl(): self
    {
        return new self('La ruta de retorno no esta permitida.', 422, 'recovery_context_invalid_return');
    }
}
