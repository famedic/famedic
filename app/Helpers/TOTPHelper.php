<?php

namespace App\Helpers;

use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;

class TOTPHelper
{
    /**
     * Generar código TOTP
     */
    public static function generate(string $secret, int $step = 30, int $digits = 6): string
    {
        $secretBytes = Base32::decodeUpper($secret);
        $totp = TOTP::create($secretBytes, $step, 'sha1', $digits);
        return $totp->now();
    }

    /**
     * Verificar código TOTP
     */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $secretBytes = Base32::decodeUpper($secret);
        $totp = TOTP::create($secretBytes, 30, 'sha1', 6);
        return $totp->verify($code, null, $window);
    }

    /**
     * Generar hash HMAC-SHA256
     */
    public static function generateHash(string $totpCode, string $clave): string
    {
        $hmac = hash_hmac('sha256', $clave, $totpCode, true);
        return base64_encode($hmac);
    }

    /**
     * Calcular tiempo restante para el próximo TOTP
     */
    public static function timeRemaining(): int
    {
        return 30 - (time() % 30);
    }
}