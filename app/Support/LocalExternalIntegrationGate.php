<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

class LocalExternalIntegrationGate
{
    public static function allows(string $integration): bool
    {
        if (! EfevooPayLocalRealTestMode::blocksExternalIntegrations()) {
            return true;
        }

        Log::info('[LocalRealTest] Integracion externa bloqueada', [
            'integration' => $integration,
            'gateway_mode' => EfevooPayGatewayMode::current(),
        ]);

        return false;
    }

    public static function assertAllowed(string $integration): void
    {
        if (! self::allows($integration)) {
            throw new \RuntimeException('Integracion externa bloqueada en pruebas locales: '.$integration);
        }
    }
}
