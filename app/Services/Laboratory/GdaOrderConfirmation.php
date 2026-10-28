<?php

namespace App\Services\Laboratory;

final class GdaOrderConfirmation
{
    public static function valid(array $response): bool
    {
        $folio = $response['id'] ?? null;
        $consecutive = $response['infogda_consecutivo'] ?? null;
        if ((! is_string($folio) && ! is_int($folio)) || (! is_string($consecutive) && ! is_int($consecutive))) {
            return false;
        }
        $folio = trim((string) $folio);
        $consecutive = trim((string) $consecutive);
        return $folio !== '' && ! (ctype_digit($folio) && (int) $folio === 0)
            && ctype_digit($consecutive) && (int) $consecutive > 0;
    }
}
