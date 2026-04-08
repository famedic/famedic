<?php

namespace App\Services;

use App\Exceptions\PayPalPaymentException;
use App\Models\PaymentLog;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class PayPalService
{
    private const TOKEN_CACHE_PREFIX = 'paypal.access_token.';

    public function baseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    public function isSandbox(): bool
    {
        return config('services.paypal.mode', 'sandbox') !== 'live';
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int}
     */
    public function getAccessToken(): array
    {
        $cacheKey = self::TOKEN_CACHE_PREFIX . ($this->isSandbox() ? 'sandbox' : 'live');

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && !empty($cached['access_token'])) {
            return $cached;
        }

        $clientId = (string) config('services.paypal.client_id');
        $secret = (string) config('services.paypal.secret');
        if ($clientId === '' || $secret === '') {
            throw new PayPalPaymentException('PayPal no está configurado (client_id / secret).');
        }

        $response = Http::asForm()
            ->withBasicAuth($clientId, $secret)
            ->withHeaders([
                'Accept' => 'application/json',
                'Accept-Language' => 'en_US',
            ])
            ->post($this->baseUrl() . '/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        $this->log('oauth_token', ['grant_type' => 'client_credentials'], $response, $response->successful() ? 'success' : 'error');

        if (!$response->successful()) {
            $body = $response->json();
            $paypalError = is_array($body) ? ($body['error'] ?? null) : null;

            Log::error('[PayPal] getAccessToken falló', [
                'status' => $response->status(),
                'paypal_error' => $paypalError,
                'environment' => $this->isSandbox() ? 'sandbox' : 'live',
                'body' => $body,
            ]);

            if ($response->status() === 401 && $paypalError === 'invalid_client') {
                throw new PayPalPaymentException(
                    'Credenciales PayPal rechazadas (invalid_client). Usa Client ID y Secret del mismo entorno que PAYPAL_MODE: '
                    . 'con PAYPAL_MODE=sandbox deben ser las credenciales de la app Sandbox en developer.paypal.com; '
                    . 'con PAYPAL_MODE=live, las de producción. Sin comillas ni espacios extra en .env.'
                );
            }

            throw new PayPalPaymentException('No se pudo obtener token de acceso de PayPal.');
        }

        $data = $response->json();
        if (!is_array($data) || empty($data['access_token'])) {
            throw new PayPalPaymentException('Respuesta de token PayPal inválida.');
        }

        $ttl = max(60, (int) ($data['expires_in'] ?? 300) - 60);
        Cache::put($cacheKey, $data, $ttl);

        return $data;
    }

    /**
     * @return array{order_id: string, status: string|null, approve_url: string|null, raw: array}
     */
    public function createOrder(float $amount, string $currency, string $customId, string $brandLabel = 'Famedic laboratorio'): array
    {
        $token = $this->getAccessToken()['access_token'];

        $value = number_format($amount, 2, '.', '');

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => $customId,
                    'custom_id' => $customId,
                    'description' => $brandLabel,
                    'amount' => [
                        'currency_code' => $currency,
                        'value' => $value,
                    ],
                ],
            ],
            'application_context' => [
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW',
                'brand_name' => 'Famedic',
            ],
        ];

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->post($this->baseUrl() . '/v2/checkout/orders', $payload);

        $this->log('create_order', $payload, $response, $response->successful() ? 'success' : 'error');

        if (!$response->successful()) {
            Log::error('[PayPal] createOrder falló', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new PayPalPaymentException('No se pudo crear la orden en PayPal.');
        }

        $raw = $response->json();
        if (!is_array($raw) || empty($raw['id'])) {
            throw new PayPalPaymentException('Respuesta createOrder inválida.');
        }

        $approveUrl = null;
        foreach ($raw['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === 'approve') {
                $approveUrl = $link['href'] ?? null;
                break;
            }
        }

        return [
            'order_id' => $raw['id'],
            'status' => $raw['status'] ?? null,
            'approve_url' => $approveUrl,
            'raw' => $raw,
        ];
    }

    /**
     * Obtiene el estado actual de una orden (p. ej. si ya fue capturada).
     *
     * @return array<string, mixed>
     */
    public function getOrder(string $orderId): array
    {
        $token = $this->getAccessToken()['access_token'];

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->get($this->baseUrl() . '/v2/checkout/orders/' . rawurlencode($orderId));

        $this->log('get_order', ['order_id' => $orderId], $response, $response->successful() ? 'success' : 'error');

        if (!$response->successful()) {
            Log::error('[PayPal] getOrder falló', [
                'order_id' => $orderId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new PayPalPaymentException('No se pudo consultar la orden en PayPal.');
        }

        $raw = $response->json();

        return is_array($raw) ? $raw : [];
    }

    /**
     * Respuesta completa de la API de captura (orden actualizada).
     *
     * @return array<string, mixed>
     */
    public function captureOrder(string $orderId): array
    {
        $token = $this->getAccessToken()['access_token'];

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Prefer' => 'return=representation',
            ])
            ->post($this->baseUrl() . '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', (object) []);

        $this->log('capture_order', ['order_id' => $orderId], $response, $response->successful() ? 'success' : 'error');

        if (!$response->successful()) {
            Log::error('[PayPal] captureOrder falló', [
                'order_id' => $orderId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new PayPalPaymentException('No se pudo capturar el pago en PayPal.');
        }

        $raw = $response->json();
        if (!is_array($raw)) {
            throw new PayPalPaymentException('Respuesta capture inválida.');
        }

        return $raw;
    }

    /**
     * @return array<string, mixed>
     */
    public function refund(string $captureId, ?float $amount = null, string $currency = 'MXN'): array
    {
        $token = $this->getAccessToken()['access_token'];

        $payload = $amount !== null
            ? ['amount' => ['currency_code' => $currency, 'value' => number_format($amount, 2, '.', '')]]
            : [];

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->post(
                $this->baseUrl() . '/v2/payments/captures/' . rawurlencode($captureId) . '/refund',
                $payload === [] ? (object) [] : $payload
            );

        $this->log('refund', array_merge(['capture_id' => $captureId], $payload), $response, $response->successful() ? 'success' : 'error');

        if (!$response->successful()) {
            Log::error('[PayPal] refund falló', [
                'capture_id' => $captureId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new PayPalPaymentException('No se pudo procesar el reembolso en PayPal.');
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    public function log(string $action, array $request, Response $response, ?string $status = null): PaymentLog
    {
        try {
            $orderId = $request['order_id'] ?? $request['id'] ?? null;
            if ($orderId === null && isset($request['purchase_units'])) {
                $orderId = null;
            }

            return PaymentLog::create([
                'order_id' => is_string($orderId) ? $orderId : null,
                'provider' => 'paypal',
                'action' => $action,
                'request' => $request,
                'response' => $response->json(),
                'status' => $status ?? (string) $response->status(),
            ]);
        } catch (Throwable $e) {
            Log::warning('[PayPal] log persist falló', ['error' => $e->getMessage()]);

            return new PaymentLog([
                'provider' => 'paypal',
                'action' => $action,
            ]);
        }
    }

    /**
     * Verificación de firma de webhook (recomendado en producción).
     *
     * @param  array<string, mixed>  $webhookHeaders  Cabeceras relevantes de la petición
     */
    public function verifyWebhookSignature(array $body, array $webhookHeaders): bool
    {
        $webhookId = config('services.paypal.webhook_id');
        if (!$webhookId) {
            Log::warning('[PayPal] PAYPAL_WEBHOOK_ID no configurado; se omite verificación estricta.');

            return true;
        }

        $token = $this->getAccessToken()['access_token'];

        $payload = [
            'auth_algo' => $webhookHeaders['auth_algo'] ?? $webhookHeaders['PAYPAL-AUTH-ALGO'] ?? '',
            'cert_url' => $webhookHeaders['cert_url'] ?? $webhookHeaders['PAYPAL-CERT-URL'] ?? '',
            'transmission_id' => $webhookHeaders['transmission_id'] ?? $webhookHeaders['PAYPAL-TRANSMISSION-ID'] ?? '',
            'transmission_sig' => $webhookHeaders['transmission_sig'] ?? $webhookHeaders['PAYPAL-TRANSMISSION-SIG'] ?? '',
            'transmission_time' => $webhookHeaders['transmission_time'] ?? $webhookHeaders['PAYPAL-TRANSMISSION-TIME'] ?? '',
            'webhook_id' => $webhookId,
            'webhook_event' => $body,
        ];

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->post($this->baseUrl() . '/v1/notifications/verify-webhook-signature', $payload);

        $this->log('verify_webhook', $payload, $response, $response->successful() ? 'success' : 'error');

        if (!$response->successful()) {
            return false;
        }

        $json = $response->json();

        return is_array($json) && ($json['verification_status'] ?? '') === 'SUCCESS';
    }

    /**
     * Extrae datos de captura desde la respuesta de capture API o del recurso de webhook.
     *
     * @param  array<string, mixed>  $orderOrCapturePayload
     * @return array{capture_id: ?string, status: ?string, order_id: ?string}
     */
    public function extractCaptureInfo(array $orderOrCapturePayload): array
    {
        $captureId = null;
        $status = null;
        $orderId = null;

        if (isset($orderOrCapturePayload['purchase_units']) && is_array($orderOrCapturePayload['purchase_units'])) {
            $orderId = isset($orderOrCapturePayload['id']) && is_string($orderOrCapturePayload['id'])
                ? $orderOrCapturePayload['id']
                : null;
            foreach ($orderOrCapturePayload['purchase_units'] as $unit) {
                $captures = $unit['payments']['captures'] ?? [];
                if (is_array($captures) && $captures !== []) {
                    $first = $captures[0];
                    if (is_array($first)) {
                        $captureId = isset($first['id']) && is_string($first['id']) ? $first['id'] : null;
                        $status = isset($first['status']) && is_string($first['status']) ? $first['status'] : null;
                    }
                    break;
                }
            }
        } else {
            $captureId = isset($orderOrCapturePayload['id']) && is_string($orderOrCapturePayload['id'])
                ? $orderOrCapturePayload['id']
                : null;
            $status = isset($orderOrCapturePayload['status']) && is_string($orderOrCapturePayload['status'])
                ? $orderOrCapturePayload['status']
                : null;
            $related = data_get($orderOrCapturePayload, 'supplementary_data.related_ids.order_id');
            $orderId = is_string($related) ? $related : null;
        }

        return [
            'capture_id' => $captureId,
            'status' => $status,
            'order_id' => $orderId,
        ];
    }
}
