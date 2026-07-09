<?php

namespace App\Exceptions;

use DomainException;

/**
 * Ningún candidato del payload cumple el formato consultable de GDA
 * para el endpoint infogda-fullV3/consult: ([A-Z0-9]{3})L([0-9]{6}).
 */
class GdaConsultIdNotResolvableException extends DomainException
{
    public function __construct(
        public readonly ?string $orderId = null,
        public readonly mixed $payloadId = null,
        public readonly mixed $requisitionValue = null,
        ?string $message = null,
    ) {
        parent::__construct(
            $message ?? 'No se encontró un ID consultable válido para GDA en el payload de la notificación.'
        );
    }
}
