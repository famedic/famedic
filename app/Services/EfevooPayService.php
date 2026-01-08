<?php

namespace App\Services;

use App\Services\TOTPService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\ConnectionException;

class EfevooPayService
{
    private TOTPService $totpService;
    private string $apiKey;
    private string $apiSecret;
    private string $totpSecret;
    private string $apiUrl;
    private string $checkoutUrl;
    private string $wssUrl;

    public function __construct(TOTPService $totpService)
    {
        $this->totpService = $totpService;
        $this->apiKey = config('efevoopay.api_key');
        $this->apiSecret = config('efevoopay.api_secret');
        $this->totpSecret = config('efevoopay.totp_secret');
        $this->apiUrl = config('efevoopay.urls.api');
        $this->checkoutUrl = config('efevoopay.urls.checkout');
        $this->wssUrl = config('efevoopay.urls.wss');
    }

    /**
     * Crear una orden en EfevooPay
     */
    public function createOrder(array $orderData): array
    {
        $totp = $this->totpService->generate($this->totpSecret);
        $token = $this->generateToken($this->apiKey . $totp);

        // Preparar items según formato requerido
        $conceptItems = [];
        foreach ($orderData['items'] as $item) {
            $conceptItems[] = [
                'item' => $item['name'] ?? 'Producto',
                'cant' => $item['quantity'] ?? 1,
                'price' => $item['price'] ?? 0,
                'item_price' => $item['price'] ?? 0,
            ];
        }

        $payload = [
            'group' => config('efevoopay.defaults.group'),
            'method' => 'get_token',
            'token' => $token,
            'api_key' => $this->apiKey,
            'data' => [
                'web_site' => config('efevoopay.defaults.website'),
                'order_details' => $orderData['order_details'] ?? new \stdClass(),
                'tx_info' => [
                    'cart' => [
                        'description' => $orderData['description'] ?? 'Compra de productos de laboratorio',
                        'concept' => $conceptItems,
                        'discount' => $orderData['discount'] ?? 0,
                        'subtotal' => $orderData['subtotal'],
                        'total' => $orderData['total'],
                    ],
                ],
            ],
        ];

        Log::info('Enviando solicitud a EfevooPay createOrder', [
            'payload' => $payload,
        ]);

        $response = Http::timeout(config('efevoopay.timeout'))
            ->retry(config('efevoopay.retry.attempts'), config('efevoopay.retry.delay'))
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->post($this->apiUrl, $payload);

        if ($response->failed()) {
            Log::error('Error en API EfevooPay', [
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            throw new \Exception('Error de comunicación con EfevooPay. Código: ' . $response->status());
        }

        $data = $response->json();

        if (($data['status']['code'] ?? '') !== '0') {
            Log::error('Error en respuesta EfevooPay', [
                'response' => $data,
                'payload' => $payload,
            ]);

            throw new \Exception('EfevooPay: ' . ($data['status']['description'] ?? 'Error desconocido al crear orden'));
        }

        $token = $data['payload']['token'] ?? null;

        if (!$token) {
            throw new \Exception('No se recibió token de orden de EfevooPay');
        }

        return [
            'token' => $token,
            'checkout_url' => $this->generateCheckoutUrl($token),
            'mode' => $data['payload']['mode'] ?? null,
            'raw_response' => $data,
        ];
    }

    /**
     * Verificar estado de un pago
     */
    public function checkStatus(string $saleToken): array
    {
        $cacheKey = "efevoopay_status_{$saleToken}";

        // Cache por 5 segundos para evitar consultas excesivas
        return Cache::remember($cacheKey, 5, function () use ($saleToken) {
            $totp = $this->totpService->generate($this->totpSecret);
            $token = $this->generateToken($this->apiKey . $totp);

            $payload = [
                'group' => config('efevoopay.defaults.group'),
                'method' => 'token_status',
                'token' => $token,
                'api_key' => $this->apiKey,
                'data' => [
                    'web_site' => config('efevoopay.defaults.website'),
                    'sale_token' => $saleToken,
                ],
            ];

            Log::debug('Consultando estado de pago EfevooPay', [
                'sale_token' => $saleToken,
            ]);

            $response = Http::timeout(config('efevoopay.timeout'))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($this->apiUrl, $payload);

            if ($response->failed()) {
                Log::warning('Error al consultar estado EfevooPay', [
                    'status' => $response->status(),
                    'sale_token' => $saleToken,
                ]);

                return [
                    'status' => 'error',
                    'message' => 'Error de comunicación',
                    'payment_status' => null,
                ];
            }

            $data = $response->json();

            return [
                'status' => $data['status']['code'] === '0' ? 'success' : 'error',
                'message' => $data['status']['description'] ?? '',
                'payment_status' => $data['payload']['status'] ?? null,
                'raw_data' => $data,
            ];
        });
    }

    /**
     * Generar token de autorización HMAC-SHA256
     */
    private function generateToken(string $message): string
    {
        return hash_hmac('sha256', $message, $this->apiSecret);
    }

    /**
     * Generar URL de checkout
     */
    private function generateCheckoutUrl(string $orderToken): string
    {
        return $this->checkoutUrl . '?' . http_build_query([
            'ApiKey' => $this->apiKey,
            'Token' => $orderToken,
        ]);
    }

    /**
     * Procesar notificación WebSocket/Webhook
     */
    public function processNotification(array $notification): array
    {
        // Validar estructura básica
        if (!isset($notification['status']['code']) || $notification['status']['code'] !== '0') {
            throw new \Exception('Notificación inválida o con error');
        }

        $payload = $notification['payload'] ?? [];

        return [
            'event_type' => $payload['message'] ?? 'unknown',
            'order_token' => $payload['token'] ?? null,
            'payment_status' => $payload['status'] ?? null,
            'amount' => $payload['amount'] ?? null,
            'currency' => $payload['coin'] ?? 'mxn',
            'date' => $payload['date'] ?? null,
            'type' => $payload['type'] ?? null,
            'order_details' => $payload['order_details'] ?? [],
        ];
    }

    /**
     * Probar conexión con la API de EfevooPay
     */
    public function testConnection(): array
    {
        $this->logInfo('🔍 Probando conexión con EfevooPay...');

        try {
            // Paso 1: Generar TOTP
            $totp = $this->totpService->generate($this->totpSecret);
            $this->logInfo("✅ TOTP generado: {$totp}");

            // Paso 2: Generar token
            $token = $this->generateToken($this->apiKey . $totp);
            $this->logInfo("✅ Token generado: " . substr($token, 0, 20) . '...');

            // Paso 3: Preparar payload mínimo
            $payload = [
                'group' => config('efevoopay.defaults.group'),
                'method' => 'get_token',
                'token' => $token,
                'api_key' => $this->apiKey,
                'data' => [
                    'web_site' => config('efevoopay.defaults.website'),
                    'order_details' => ['test' => true],
                    'tx_info' => [
                        'cart' => [
                            'description' => 'Prueba de conexión',
                            'concept' => [
                                [
                                    'item' => 'Producto prueba',
                                    'cant' => 1,
                                    'price' => 1,
                                    'item_price' => 1,
                                ]
                            ],
                            'discount' => 0,
                            'subtotal' => 1,
                            'total' => 1,
                        ],
                    ],
                ],
            ];

            $this->logInfo("📤 Enviando solicitud a: {$this->apiUrl}");

            // Paso 4: Enviar solicitud
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'EfevooPay-Test/1.0',
                ])
                ->post($this->apiUrl, $payload);

            $this->logInfo("📥 Response HTTP Code: " . $response->status());

            if ($response->failed()) {
                $this->logError("❌ HTTP Error: " . $response->status());
                $this->logError("📄 Response Body: " . $response->body());
                return [
                    'success' => false,
                    'error' => 'HTTP ' . $response->status(),
                    'body' => $response->body(),
                ];
            }

            $data = $response->json();

            $this->logInfo("📊 Response JSON recibido");
            $this->logInfo("🔢 Status Code: " . ($data['status']['code'] ?? 'NO CODE'));
            $this->logInfo("📝 Description: " . ($data['status']['description'] ?? 'NO DESCRIPTION'));

            // DEBUG: Mostrar estructura completa
            $this->logInfo("📦 Estructura completa del payload:");
            $this->logInfo(json_encode($data['payload'], JSON_PRETTY_PRINT));

            if (($data['status']['code'] ?? '') === '0') {
                // IMPORTANTE: El token está en $data['payload']['token'] no en $data['payload'][0]['token']
                $token = $data['payload']['token'] ?? null;

                $this->logInfo("🎉 ¡Conexión exitosa!");
                $this->logInfo("🪙 Token recibido: " . ($token ?? 'NO TOKEN'));
                $this->logInfo("🔧 Mode: " . ($data['payload']['mode'] ?? 'NO MODE'));

                // Generar URL de checkout para prueba
                $checkoutUrl = $this->generateCheckoutUrl($token ?? '');
                $this->logInfo("🔗 Checkout URL: " . $checkoutUrl);

                return [
                    'success' => true,
                    'token' => $token,
                    'mode' => $data['payload']['mode'] ?? null,
                    'checkout_url' => $checkoutUrl,
                    'response' => $data,
                ];
            } else {
                $this->logError("⚠️  API Error: " . ($data['status']['description'] ?? 'Error desconocido'));

                return [
                    'success' => false,
                    'error' => $data['status']['description'] ?? 'Error desconocido',
                    'response' => $data,
                ];
            }

        } catch (ConnectionException $e) {
            $this->logError("🔌 Connection Exception: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Connection timeout: ' . $e->getMessage(),
            ];
        } catch (\Exception $e) {
            $this->logError("💥 Exception: " . $e->getMessage());
            $this->logError("📋 Trace: " . $e->getTraceAsString());

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ];
        }
    }

    /**
     * Métodos auxiliares para logging en consola
     */
    private function logInfo(string $message): void
    {
        if (app()->runningInConsole()) {
            echo $message . PHP_EOL;
        }
        Log::info($message);
    }

    private function logError(string $message): void
    {
        if (app()->runningInConsole()) {
            echo $message . PHP_EOL;
        }
        Log::error($message);
    }

    /**
     * Validar configuración básica
     */
    public function validateConfiguration(): array
    {
        $errors = [];

        if (empty($this->apiKey)) {
            $errors[] = 'EFEVOOPAY_API_KEY no configurada';
        }

        if (empty($this->apiSecret)) {
            $errors[] = 'EFEVOOPAY_API_SECRET no configurada';
        }

        if (empty($this->totpSecret)) {
            $errors[] = 'EFEVOOPAY_TOTP_SECRET no configurada';
        }

        if (empty($this->apiUrl)) {
            $errors[] = 'EFEVOOPAY_API_URL no configurada';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'config' => [
                'api_key_exists' => !empty($this->apiKey),
                'api_secret_exists' => !empty($this->apiSecret),
                'totp_secret_exists' => !empty($this->totpSecret),
                'api_url' => $this->apiUrl,
                'checkout_url' => $this->checkoutUrl,
                'wss_url' => $this->wssUrl,
            ],
        ];
    }
}