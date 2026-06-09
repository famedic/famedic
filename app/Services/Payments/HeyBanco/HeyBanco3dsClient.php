<?php

namespace App\Services\Payments\HeyBanco;

use App\Data\Payments\HeyBanco\HeyBanco3dsStartResult;
use App\Models\Payment3dsSession;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HeyBanco3dsClient
{
    public function __construct(
        private HeyBanco3dsSignatureService $signatureService,
        private HeyBancoClient $heyBancoClient,
    ) {}

    public function startTokenCharge(Payment3dsSession $session, PaymentMethod $paymentMethod): HeyBanco3dsStartResult
    {
        $payload = [
            'BNRG_ID_MEDIO' => config('heybanco.3ds_media_id'),
            'BNRG_ID_AFILIACION' => config('heybanco.3ds_affiliation'),
            'BNRG_MONTO_TRANS' => $this->formatAmount($session->amount),
            'BNRG_TOKEN' => $paymentMethod->provider_token,
            'BNRG_URL_RESPUESTA' => $session->response_url,
            'BNRG_FOLIO' => $session->folio,
            'BNRG_REF_CLIENTE1' => $session->reference,
            'BNRG_MODO_TRANS' => $session->mode ?? config('heybanco.mode'),
            'BNRG_HORA_LOCAL' => $this->heyBancoClient->localTime(),
            'BNRG_FECHA_LOCAL' => $this->heyBancoClient->localDate(),
        ];

        if (config('heybanco.3ds_secure_api', true)) {
            $payload['BNRG_MODO_API_SEC'] = 'true';
            $payload['BNRG_HASH'] = $this->signatureService->signRequest($payload);
        }

        $sanitized = $this->sanitizeRequest($payload);

        Log::info('[HeyBanco3DS] Start token charge', [
            'session_id' => $session->id,
            'folio' => $session->folio,
            'request' => $sanitized,
        ]);

        try {
            $response = Http::asForm()
                ->timeout((int) config('heybanco.3ds_timeout', config('heybanco.timeout', 30)))
                ->post((string) config('heybanco.3ds_url'), $payload);

            $body = $response->body();
            $headers = $response->headers();
            $normalized = $this->normalizeResponse($headers, $body);

            $redirectUrl = $this->extractRedirectUrl($normalized, $body);

            if ($redirectUrl) {
                return new HeyBanco3dsStartResult(
                    success: true,
                    redirectUrl: $redirectUrl,
                    rawHeaders: $headers,
                    rawBody: $body,
                    sanitizedRequest: $sanitized,
                );
            }

            $codigoProc = $normalized['BNRG_CODIGO_PROC'] ?? null;
            $texto = $normalized['BNRG_TEXTO'] ?? null;

            return new HeyBanco3dsStartResult(
                success: false,
                rawHeaders: $headers,
                rawBody: $body,
                errorMessage: $texto ?? 'No se recibió URL de redirección 3DS.',
                codigoProc: $codigoProc,
                texto: $texto,
                sanitizedRequest: $sanitized,
            );
        } catch (\Throwable $e) {
            Log::error('[HeyBanco3DS] Start failed', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return HeyBanco3dsStartResult::failure($e->getMessage());
        }
    }

    private function formatAmount(float|string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizeRequest(array $payload): array
    {
        $sanitized = $payload;

        if (isset($sanitized['BNRG_TOKEN'])) {
            $token = (string) $sanitized['BNRG_TOKEN'];
            $sanitized['BNRG_TOKEN'] = strlen($token) > 8
                ? substr($token, 0, 4) . '...' . substr($token, -4)
                : '****';
        }

        return $sanitized;
    }

    /**
     * @param  array<string, array<int, string>|string>  $headers
     * @return array<string, string>
     */
    private function normalizeResponse(array $headers, string $body): array
    {
        $normalized = [];

        foreach ($headers as $key => $values) {
            $name = strtoupper(str_replace('_', '-', (string) $key));
            $value = is_array($values) ? ($values[0] ?? '') : (string) $values;
            $normalized[str_replace('-', '_', $name)] = rawurldecode($value);
        }

        if (preg_match_all('/name=["\']?([^"\'>\s]+)["\']?\s+value=["\']?([^"\'>\s]+)["\']?/i', $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $normalized[strtoupper($match[1])] = rawurldecode($match[2]);
            }
        }

        return $normalized;
    }

    private function extractRedirectUrl(array $normalized, string $body): ?string
    {
        foreach (['BNRG_URL_REDIRECCION', 'BNRG_REDIRECT_URL', 'URL_REDIRECCION'] as $key) {
            if (! empty($normalized[$key])) {
                return (string) $normalized[$key];
            }
        }

        if (preg_match('/https?:\/\/[^\s"\'<>]+/i', $body, $match)) {
            return $match[0];
        }

        return null;
    }
}
