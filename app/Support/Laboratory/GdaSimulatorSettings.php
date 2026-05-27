<?php

namespace App\Support\Laboratory;

/**
 * Contexto opcional durante simulaciones admin de webhooks GDA.
 * No se registra en producción salvo durante la petición del simulador.
 */
class GdaSimulatorSettings
{
    public function __construct(
        public bool $sendEmail = true,
        public bool $bypassGate = false,
    ) {}
}
