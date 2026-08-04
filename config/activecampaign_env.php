<?php

/**
 * Lee variables de entorno ActiveCampaign (cupones/saldo) ignorando cadenas vacías o solo espacios.
 * Si el valor está ausente o en blanco, devuelve $default (p. ej. nombre de tag §17).
 */
if (! function_exists('active_campaign_env')) {
    function active_campaign_env(string $key, ?string $default = null): ?string
    {
        $value = env($key);

        if ($value === null) {
            return $default;
        }

        if (! is_string($value)) {
            return (string) $value;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? $default : $trimmed;
    }
}
