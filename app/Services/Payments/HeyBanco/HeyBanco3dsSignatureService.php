<?php

namespace App\Services\Payments\HeyBanco;

/**
 * Firma HMAC-SHA256 + Base64 para API segura 3DS Colecto Banregio.
 *
 * TODO: Confirmar orden exacto de campos con Manual de Integración Banregio
 * Colecto 3D Secure V1.2. La implementación actual concatena los valores de
 * los campos listados en config('heybanco.3ds_request_sign_fields') /
 * config('heybanco.3ds_response_sign_fields') en ese orden.
 */
class HeyBanco3dsSignatureService
{
    public function signRequest(array $payload): string
    {
        $canonical = $this->canonicalRequestString($payload);
        $secret = (string) config('heybanco.3ds_secret_key');

        return base64_encode(hash_hmac('sha256', $canonical, $secret, true));
    }

    public function validateResponse(array $payload): bool
    {
        $provided = $payload['BNRG_HASH'] ?? null;

        if ($provided === null || $provided === '') {
            return ! config('heybanco.3ds_secure_api', true);
        }

        $expected = $this->signResponse($payload);

        return hash_equals($expected, (string) $provided);
    }

    public function signResponse(array $payload): string
    {
        $canonical = $this->canonicalResponseString($payload);
        $secret = (string) config('heybanco.3ds_secret_key');

        return base64_encode(hash_hmac('sha256', $canonical, $secret, true));
    }

    public function canonicalRequestString(array $payload): string
    {
        return $this->canonicalString($payload, config('heybanco.3ds_request_sign_fields', []));
    }

    public function canonicalResponseString(array $payload): string
    {
        return $this->canonicalString($payload, config('heybanco.3ds_response_sign_fields', []));
    }

    private function canonicalString(array $payload, array $fields): string
    {
        $parts = [];

        foreach ($fields as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $value = $payload[$field];

            if ($value === null || $value === '') {
                continue;
            }

            $parts[] = $this->normalizeValue((string) $value);
        }

        return implode('', $parts);
    }

    private function normalizeValue(string $value): string
    {
        return rawurldecode(trim($value));
    }
}
