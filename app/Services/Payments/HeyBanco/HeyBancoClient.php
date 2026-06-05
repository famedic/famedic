<?php

namespace App\Services\Payments\HeyBanco;

use App\Models\PaymentTransaction;
use Illuminate\Http\Client\Response as LaravelResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;

class HeyBancoClient
{
    /**
     * Crea un token de tarjeta (CREACION_TOKEN).
     *
     * @param  array{card_number: string, exp_month: string, exp_year: string, cvv: string}  $cardData
     */
    public function createToken(array $cardData, ?string $reference = null): HeyBancoResponse
    {
        $folio = $this->generateFolio();

        $payload = [
            'BNRG_CMD_TRANS' => 'CREACION_TOKEN',
            'BNRG_ID_AFILIACION' => config('heybanco.token_affiliation'),
            'BNRG_ID_MEDIO' => config('heybanco.token_media_id'),
            'BNRG_NUMERO_TARJETA' => preg_replace('/\D/', '', $cardData['card_number']),
            'BNRG_FECHA_EXP' => $this->formatExpiration($cardData['exp_month'], $cardData['exp_year']),
            'BNRG_CODIGO_SEGURIDAD' => $cardData['cvv'],
            'BNRG_FOLIO' => $folio,
            'BNRG_HORA_LOCAL' => $this->localTime(),
            'BNRG_FECHA_LOCAL' => $this->localDate(),
            'BNRG_MODO_ENTRADA' => 'MANUAL',
            'BNRG_IDIOMA_SALIDA' => 'ES',
        ];

        if ($reference !== null) {
            $payload['BNRG_REF_CLIENTE1'] = $reference;
        }

        return $this->post($payload, 'token_creation');
    }

    /**
     * Cobra con token previamente guardado (VENTA).
     */
    public function chargeToken(
        string $token,
        float|string $amount,
        string $reference,
        array $metadata = []
    ): HeyBancoResponse {
        $folio = $this->generateFolio();

        $payload = [
            'BNRG_CMD_TRANS' => 'VENTA',
            'BNRG_ID_AFILIACION' => config('heybanco.token_affiliation'),
            'BNRG_ID_MEDIO' => config('heybanco.token_media_id'),
            'BNRG_FOLIO' => $folio,
            'BNRG_HORA_LOCAL' => $this->localTime(),
            'BNRG_FECHA_LOCAL' => $this->localDate(),
            'BNRG_MODO_ENTRADA' => 'MANUAL',
            'BNRG_MODO_TRANS' => config('heybanco.mode'),
            'BNRG_MONTO_TRANS' => $this->formatAmount($amount),
            'BNRG_TOKEN' => $token,
            'BNRG_REF_CLIENTE1' => $reference,
            'BNRG_IDIOMA_SALIDA' => 'ES',
        ];

        if (! empty($metadata['ref_cliente2'])) {
            $payload['BNRG_REF_CLIENTE2'] = (string) $metadata['ref_cliente2'];
        }

        return $this->post($payload, 'token_charge');
    }

    /**
     * Verifica una transacción por referencia Banregio.
     */
    public function verifyByReference(string $reference, ?string $mediaId = null): HeyBancoResponse
    {
        $payload = [
            'BNRG_CMD_TRANS' => 'VERIFICACION',
            'BNRG_ID_MEDIO' => $mediaId ?? config('heybanco.token_media_id'),
            'BNRG_HORA_LOCAL' => $this->localTime(),
            'BNRG_FECHA_LOCAL' => $this->localDate(),
            'BNRG_REF_TRANS_PREVIA' => $reference,
            'BNRG_IDIOMA_SALIDA' => 'ES',
        ];

        return $this->post($payload, 'verification');
    }

    /**
     * Verifica una transacción por folio.
     */
    public function verifyByFolio(string $folio, ?string $mediaId = null): HeyBancoResponse
    {
        $payload = [
            'BNRG_CMD_TRANS' => 'VERIFICACION',
            'BNRG_ID_MEDIO' => $mediaId ?? config('heybanco.token_media_id'),
            'BNRG_HORA_LOCAL' => $this->localTime(),
            'BNRG_FECHA_LOCAL' => $this->localDate(),
            'BNRG_FOLIO' => $folio,
            'BNRG_IDIOMA_SALIDA' => 'ES',
        ];

        return $this->post($payload, 'verification');
    }

    /**
     * Cancelación por referencia (preparado para flujos futuros).
     */
    public function cancelByReference(string $reference, ?string $mediaId = null): HeyBancoResponse
    {
        $payload = [
            'BNRG_CMD_TRANS' => 'CANCELACION',
            'BNRG_ID_MEDIO' => $mediaId ?? config('heybanco.token_media_id'),
            'BNRG_HORA_LOCAL' => $this->localTime(),
            'BNRG_FECHA_LOCAL' => $this->localDate(),
            'BNRG_REF_TRANS_PREVIA' => $reference,
            'BNRG_IDIOMA_SALIDA' => 'ES',
        ];

        return $this->post($payload, 'cancellation');
    }

    /**
     * Normaliza headers HTTP de respuesta Colecto (prefijo BNRG_).
     *
     * @param  ResponseInterface|LaravelResponse|array<string, mixed>  $headers
     * @return array<string, string>
     */
    public function normalizeHeaders(ResponseInterface|LaravelResponse|array $headers): array
    {
        $flat = $this->flattenHeaders($headers);
        $normalized = [];

        foreach ($flat as $key => $value) {
            $upperKey = strtoupper($key);

            if (! str_starts_with($upperKey, 'BNRG_')) {
                continue;
            }

            $normalized[$upperKey] = $this->decodeHeaderValue((string) $value);
        }

        return $normalized;
    }

    /**
     * Genera folio único de máximo 12 caracteres para el medio configurado.
     */
    public function generateFolio(): string
    {
        $maxAttempts = 10;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $folio = 'FM' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));

            $exists = PaymentTransaction::query()
                ->where('folio', $folio)
                ->where('provider', config('heybanco.provider_key'))
                ->exists();

            if (! $exists) {
                return $folio;
            }
        }

        return 'FM' . strtoupper(substr(uniqid('', true), -10));
    }

    /** Fecha local DDMMAAAA (zona Monterrey). */
    public function localDate(): string
    {
        return now('America/Monterrey')->format('dmY');
    }

    /** Hora local HHMMSS (zona Monterrey). */
    public function localTime(): string
    {
        return now('America/Monterrey')->format('His');
    }

    private function post(array $payload, string $flow): HeyBancoResponse
    {
        $url = rtrim((string) config('heybanco.adq_url'), '/') . '/';
        $sanitizedRequest = $this->sanitizeRequest($payload);

        Log::info('[HeyBanco] Request', [
            'flow' => $flow,
            'cmd' => $payload['BNRG_CMD_TRANS'] ?? null,
            'folio' => $payload['BNRG_FOLIO'] ?? null,
            'reference' => $payload['BNRG_REF_CLIENTE1'] ?? null,
            'request' => $sanitizedRequest,
        ]);

        $response = Http::asForm()
            ->timeout((int) config('heybanco.timeout', 30))
            ->post($url, $payload);

        $normalized = $this->normalizeHeaders($response->headers());

        if (! isset($normalized['BNRG_FOLIO']) && isset($payload['BNRG_FOLIO'])) {
            $normalized['BNRG_FOLIO'] = (string) $payload['BNRG_FOLIO'];
        }

        Log::info('[HeyBanco] Response', [
            'flow' => $flow,
            'status' => $response->status(),
            'codigo_proc' => $normalized['BNRG_CODIGO_PROC'] ?? null,
            'referencia' => $normalized['BNRG_REFERENCIA'] ?? null,
            'folio' => $normalized['BNRG_FOLIO'] ?? ($payload['BNRG_FOLIO'] ?? null),
        ]);

        return new HeyBancoResponse(
            rawHeaders: $this->flattenHeaders($response->headers()),
            normalizedHeaders: $normalized,
            rawRequest: $sanitizedRequest,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizeRequest(array $payload): array
    {
        $sanitized = $payload;

        if (isset($sanitized['BNRG_NUMERO_TARJETA'])) {
            $sanitized['BNRG_NUMERO_TARJETA'] = $this->maskPan((string) $sanitized['BNRG_NUMERO_TARJETA']);
        }

        if (isset($sanitized['BNRG_CODIGO_SEGURIDAD'])) {
            $sanitized['BNRG_CODIGO_SEGURIDAD'] = '***';
        }

        if (isset($sanitized['BNRG_TOKEN'])) {
            $sanitized['BNRG_TOKEN'] = $this->maskToken((string) $sanitized['BNRG_TOKEN']);
        }

        return $sanitized;
    }

    private function maskPan(string $pan): string
    {
        $digits = preg_replace('/\D/', '', $pan) ?? '';

        if (strlen($digits) < 4) {
            return '****';
        }

        return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }

    private function maskToken(string $token): string
    {
        if (strlen($token) <= 8) {
            return '****';
        }

        return substr($token, 0, 4) . '...' . substr($token, -4);
    }

    private function formatExpiration(string $month, string $year): string
    {
        $mm = str_pad(preg_replace('/\D/', '', $month) ?? '', 2, '0', STR_PAD_LEFT);
        $yy = substr(preg_replace('/\D/', '', $year) ?? '', -2);

        return $mm . $yy;
    }

    private function formatAmount(float|string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function decodeHeaderValue(string $value): string
    {
        return rawurldecode($value);
    }

    /**
     * @param  ResponseInterface|LaravelResponse|array<string, mixed>  $headers
     * @return array<string, string>
     */
    private function flattenHeaders(ResponseInterface|LaravelResponse|array $headers): array
    {
        if ($headers instanceof LaravelResponse) {
            $headers = $headers->headers();
        }

        if ($headers instanceof ResponseInterface) {
            $flat = [];
            foreach ($headers->getHeaders() as $name => $values) {
                $flat[$name] = implode(', ', $values);
            }

            return $flat;
        }

        $flat = [];

        foreach ($headers as $key => $value) {
            if (is_array($value)) {
                $flat[$key] = implode(', ', $value);
            } else {
                $flat[$key] = (string) $value;
            }
        }

        return $flat;
    }
}
