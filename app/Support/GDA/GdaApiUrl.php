<?php

namespace App\Support\GDA;

class GdaApiUrl
{
    public static function endpoint(string $resource): string
    {
        $base = rtrim((string) config('services.gda.url'), '/');
        $path = trim((string) config('services.gda.api_path', 'infogda-fullV3'), '/');
        $resource = ltrim($resource, '/');

        if ($path === '') {
            return "{$base}/{$resource}";
        }

        return "{$base}/{$path}/{$resource}";
    }

    /**
     * En local/staging/testing la API de órdenes GDA se simula salvo GDA_FORCE_REAL_API=true.
     */
    public static function shouldSimulateOrders(): bool
    {
        if (filter_var(config('services.gda.force_real_api'), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        return in_array(strtolower((string) config('app.env')), ['local', 'staging', 'testing'], true);
    }
}
