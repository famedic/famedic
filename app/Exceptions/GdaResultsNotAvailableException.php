<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * GDA respondió correctamente pero indica que el PDF de resultados
 * aún no está disponible para la orden consultada.
 *
 * Este caso es temporal/retryable: GDA ya notificó resultados,
 * pero su endpoint de consulta aún no los tiene publicados.
 */
class GdaResultsNotAvailableException extends RuntimeException
{
    public function __construct(
        public readonly string $orderId,
        public readonly ?string $gdaMessage = null,
        ?string $message = null,
    ) {
        parent::__construct(
            $message ?? "GDA respondió que los resultados aún no están disponibles para la orden {$orderId}."
        );
    }
}
