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
        $mediaContext = $this->resolveTokenMediaContext($paymentMethod);

        $payload = [
            'BNRG_ID_MEDIO' => $mediaContext['media_id'],
            'BNRG_ID_AFILIACION' => $mediaContext['affiliation_id'],
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
            $codigoRechazo = $normalized['BNRG_CODIGO_RECHAZO'] ?? null;

            return new HeyBanco3dsStartResult(
                success: false,
                rawHeaders: $headers,
                rawBody: $body,
                errorMessage: $this->userFacingStartErrorMessage($codigoProc, $texto),
                codigoProc: $codigoProc,
                texto: $texto,
                codigoRechazo: $codigoRechazo,
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
    /**
     * @return array{media_id: string, affiliation_id: string, media_source: string, affiliation_source: string}
     */
    private function resolveTokenMediaContext(PaymentMethod $paymentMethod): array
    {
        $configMediaId = (string) config('heybanco.3ds_media_id');
        $configAffiliationId = (string) config('heybanco.3ds_affiliation');

        $mediaId = $paymentMethod->media_id ?: $configMediaId;
        $affiliationId = $paymentMethod->affiliation_id ?: $configAffiliationId;

        $mediaSource = $paymentMethod->media_id ? 'payment_method' : 'config';
        $affiliationSource = $paymentMethod->affiliation_id ? 'payment_method' : 'config';

        Log::info('[HeyBanco3DS] token media context', [
            'payment_method_id' => $paymentMethod->id,
            'token_media_id' => $paymentMethod->media_id,
            'token_affiliation_id' => $paymentMethod->affiliation_id,
            'config_3ds_media_id' => $configMediaId,
            'config_3ds_affiliation' => $configAffiliationId,
            'media_source' => $mediaSource,
            'affiliation_source' => $affiliationSource,
            'resolved_media_id' => $mediaId,
            'resolved_affiliation_id' => $affiliationId,
        ]);

        if ($paymentMethod->media_id && $paymentMethod->media_id !== $configMediaId) {
            Log::warning('[HeyBanco3DS] token_media_differs_from_3ds_config_using_payment_method_media', [
                'payment_method_id' => $paymentMethod->id,
                'token_media_id' => $paymentMethod->media_id,
                'config_3ds_media_id' => $configMediaId,
            ]);
        }

        if (! $paymentMethod->media_id) {
            Log::warning('[HeyBanco3DS] payment_method_media_missing_using_3ds_config_fallback', [
                'payment_method_id' => $paymentMethod->id,
                'config_3ds_media_id' => $configMediaId,
            ]);
        }

        if (! $paymentMethod->affiliation_id) {
            Log::warning('[HeyBanco3DS] payment_method_affiliation_missing_using_3ds_config_fallback', [
                'payment_method_id' => $paymentMethod->id,
                'config_3ds_affiliation' => $configAffiliationId,
            ]);
        }

        return [
            'media_id' => $mediaId,
            'affiliation_id' => $affiliationId,
            'media_source' => $mediaSource,
            'affiliation_source' => $affiliationSource,
        ];
    }

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

        if (preg_match_all('/[?&](BNRG_[A-Z0-9_]+)=([^&"\'\s<>]+)/', $body, $queryMatches, PREG_SET_ORDER)) {
            foreach ($queryMatches as $match) {
                $normalized[strtoupper($match[1])] = rawurldecode($match[2]);
            }
        }

        return $normalized;
    }

    private function extractRedirectUrl(array $normalized, string $body): ?string
    {
        $codigoProc = strtoupper((string) ($normalized['BNRG_CODIGO_PROC'] ?? ''));

        if (in_array($codigoProc, ['R', 'D', 'T', 'X'], true)) {
            return null;
        }

        foreach (['BNRG_URL_REDIRECCION', 'BNRG_REDIRECT_URL', 'URL_REDIRECCION'] as $key) {
            if (! empty($normalized[$key]) && $this->isChallengeRedirectUrl((string) $normalized[$key])) {
                return (string) $normalized[$key];
            }
        }

        if (preg_match_all('/https?:\/\/[^\s"\'<>]+/i', $body, $urlMatches)) {
            foreach ($urlMatches[0] as $url) {
                if ($this->isChallengeRedirectUrl($url)) {
                    return $url;
                }
            }
        }

        return null;
    }

    private function isChallengeRedirectUrl(string $url): bool
    {
        $lower = strtolower($url);

        if (str_contains($lower, 'muestraresultado')) {
            return false;
        }

        if (preg_match('/bnrg_codigo_proc=(r|d|t|x)/i', $lower)) {
            return false;
        }

        return true;
    }

    private function userFacingStartErrorMessage(?string $codigoProc, ?string $texto): string
    {
        $proc = strtoupper((string) $codigoProc);
        $text = (string) ($texto ?? '');

        if ($proc === 'R' && (
            str_contains(strtolower($text), 'id medio')
            || str_contains(strtolower($text), 'token proporcionado')
        )) {
            return 'No pudimos iniciar la autenticación bancaria de esta tarjeta. Por favor elimina y vuelve a agregar tu tarjeta Banregio.';
        }

        if ($text !== '') {
            return 'El banco rechazó el inicio de autenticación 3DS: ' . $text;
        }

        return 'No se recibió URL de redirección 3DS.';
    }
}
