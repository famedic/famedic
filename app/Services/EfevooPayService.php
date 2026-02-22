<?php

namespace App\Services;

use App\Models\EfevooToken;
use App\Models\Efevoo3dsSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EfevooPayService
{
    protected array $config;

    public function __construct()
    {
        $this->config = config('efevoopay');

        Log::info('[Efevoo] Service booting');
        Log::info('[Efevoo] DEBUG KEYS', [
            'cliente' => $this->config['cliente'],
            'clave_preview' => substr($this->config['clave'], 0, 5),
            'vector_preview' => substr($this->config['vector'], 0, 5),
            'totp_preview' => substr($this->config['totp_secret'], 0, 5),
        ]);

        $this->validateConfig();

        Log::info('[Efevoo] Config loaded', [
            'cliente' => $this->config['cliente'],
            'api_url' => $this->config['api_url'],
            'env' => config('app.env'),
        ]);
    }

    /* ==========================================================
     * CLIENT TOKEN
     * ========================================================== */

    public function getClientToken(string $operation = 'default'): array
    {
        Log::info('[Efevoo] getClientToken', ['operation' => $operation]);

        try {

            $totp = $this->generateTOTP();

            Log::info('[Efevoo] TOTP generado', ['totp' => $totp]);

            $hash = base64_encode(
                hash_hmac('sha256', $this->config['clave'], $totp, true)
            );

            $payload = [
                'payload' => [
                    'hash' => $hash,
                    'cliente' => $this->config['cliente'],
                ],
                'method' => 'getClientToken'
            ];

            $response = $this->request($payload);

            if (
                $response['success'] &&
                ($response['data']['codigo'] ?? null) === '100' &&
                !empty($response['data']['token'])
            ) {
                return [
                    'success' => true,
                    'token' => $response['data']['token'],
                ];
            }

            return [
                'success' => false,
                'message' => 'Token inválido',
                'raw' => $response
            ];

        } catch (\Throwable $e) {

            return [
                'success' => false,
                'message' => 'Excepción generando token',
            ];
        }
    }

    /* ==========================================================
     * 3DS INIT
     * ========================================================== */

    public function initiate3DS(array $cardData, int $customerId): array
    {
        Log::info('[Efevoo] initiate3DS');

        try {

            $tokenResult = $this->getClientToken('3ds');

            if (!$tokenResult['success']) {
                Log::warning('[Efevoo] Token failed 3DS', $tokenResult);
                return $tokenResult;
            }

            // Normalizar expiración
            $expiration = preg_replace('/[^0-9]/', '', $cardData['expiration']);

            if (strlen($expiration) !== 4) {
                Log::error('[Efevoo] Expiration format inválido', [
                    'received' => $cardData['expiration']
                ]);

                return [
                    'success' => false,
                    'message' => 'Formato de expiración inválido'
                ];
            }

            $expFormatted = substr($expiration, 0, 2) . '/' . substr($expiration, 2, 2);

            // Timezone correcto con signo
            $tzMinutes = (int) ((new \DateTime())->getOffset() / 60);

            // Accept header realista (no confiar en request)
            $acceptHeader = request()->header('Accept');

            if (!$acceptHeader || str_contains($acceptHeader, 'application/json')) {
                $acceptHeader = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8';
            }

            $body = [
                'track' => $cardData['card_number'],
                'cvv' => $cardData['cvv'],
                'exp' => $expFormatted,
                'fiid_comercio' => $this->config['fiid_comercio'],
                'msi' => 0,
                'amount' => number_format($cardData['amount'], 2, '.', ''),
                'browser' => [
                    'browserAcceptHeader' => $acceptHeader,
                    'browserJavaEnabled' => false,
                    'browserJavaScriptEnabled' => true,
                    'browserLanguage' => 'es-419',
                    'browserTZ' => (string) $tzMinutes, // ahora correcto
                    'browserUserAgent' => request()->header('User-Agent')
                        ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0 Safari/537.36',
                ]
            ];

            Log::info('[Efevoo] Body before encrypt 3DS', $body);

            $encrypted = $this->encrypt($body);

            $payload = [
                'payload' => [
                    'token' => $tokenResult['token'],
                    'encrypt' => $encrypted
                ],
                'method' => 'payments3DS_GetLink'
            ];

            $response = $this->request($payload);

            Log::info('[Efevoo] 3DS GetLink response', $response);

            if (!$response['success']) {
                return $response;
            }

            // Validar código de status
            $statusCode = $response['data']['status']['code'] ?? null;

            if ($statusCode !== '0') {
                Log::warning('[Efevoo] 3DS estructura inválida', [
                    'status_code' => $statusCode,
                    'description' => $response['data']['status']['description'] ?? null
                ]);

                return [
                    'success' => false,
                    'message' => $response['data']['status']['description'] ?? 'Error en 3DS',
                    'raw' => $response
                ];
            }

            $data = $response['data']['payload'] ?? null;

            if (!$data || empty($data['order_id'])) {
                return [
                    'success' => false,
                    'message' => 'Respuesta inválida de 3DS',
                    'raw' => $response
                ];
            }

            $session = Efevoo3dsSession::create([
                'customer_id' => $customerId,
                'order_id' => $data['order_id'],
                'card_last_four' => substr($cardData['card_number'], -4),
                'amount' => $cardData['amount'],
                'status' => 'redirect_required',
                'url_3dsecure' => $data['url_3dsecure'] ?? null,
                'token_3dsecure' => $data['token_3dsecure'] ?? null,
            ]);

            return [
                'success' => true,
                'session_id' => $session->id,
                'url_3dsecure' => $data['url_3dsecure'],
                'token_3dsecure' => $data['token_3dsecure'],
            ];

        } catch (\Throwable $e) {

            Log::error('[Efevoo] initiate3DS exception', [
                'message' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            return [
                'success' => false,
                'message' => 'Error interno iniciando 3DS'
            ];
        }
    }

    /* ==========================================================
     * TOKENIZE
     * ========================================================== */

    public function tokenizeCard(array $cardData, int $customerId): array
    {
        Log::info('[Efevoo] tokenizeCard');

        $tokenResult = $this->getClientToken('tokenize');

        if (!$tokenResult['success']) {
            return $tokenResult;
        }

        $track2 = $cardData['card_number'] . '=' .
            substr($cardData['expiration'], 2, 2) .
            substr($cardData['expiration'], 0, 2);

        $payload = [
            'payload' => [
                'token' => $tokenResult['token'],
                'encrypt' => $this->encrypt([
                    'track2' => $track2,
                    'amount' => number_format($cardData['amount'], 2, '.', '')
                ])
            ],
            'method' => 'getTokenize'
        ];

        $response = $this->request($payload);

        Log::info('[Efevoo] Tokenize response', $response);

        if (
            !$response['success'] ||
            empty($response['data']['token_usuario'])
        ) {
            return [
                'success' => false,
                'message' =>
                    $response['data']['descripcion']
                    ?? $response['data']['msg']
                    ?? 'Tokenización no aprobada',
                'raw' => $response
            ];
        }

        $token = EfevooToken::create([
            'customer_id' => $customerId,
            'card_token' => $response['data']['token_usuario'],
            'card_last_four' => substr($cardData['card_number'], -4),
            'card_expiration' => $cardData['expiration'],
            'card_holder' => $cardData['card_holder'],
            'alias' => $cardData['alias'] ?? null,
        ]);

        return [
            'success' => true,
            'token_id' => $token->id
        ];
    }

    /* ==========================================================
     * CHARGE
     * ========================================================== */

    public function chargeCard(array $data): array
    {
        Log::info('[Efevoo] chargeCard');

        $tokenResult = $this->getClientToken('payment');

        if (!$tokenResult['success']) {
            return $tokenResult;
        }

        $payload = [
            'payload' => [
                'token' => $tokenResult['token'],
                'encrypt' => $this->encrypt([
                    'track2' => $data['token_id'],
                    'amount' => number_format($data['amount'], 2, '.', ''),
                    'referencia' => $data['reference'] ?? 'REF-' . time()
                ])
            ],
            'method' => 'getPayment'
        ];

        $response = $this->request($payload);

        Log::info('[Efevoo] Payment response', $response);

        $codigo = $response['data']['codigo'] ?? null;

        if ($codigo !== '00') {
            return [
                'success' => false,
                'message' =>
                    $response['data']['descripcion']
                    ?? $response['data']['msg']
                    ?? 'Pago no aprobado',
                'raw' => $response
            ];
        }

        return [
            'success' => true,
            'transaction_id' => $response['data']['id'] ?? null,
            'authorization_code' => $response['data']['numref'] ?? null,
            'raw' => $response
        ];
    }

    /* ==========================================================
     * REFUND
     * ========================================================== */

    public function refundTransaction(int $transactionId): array
    {
        Log::info('[Efevoo] refundTransaction', ['transaction_id' => $transactionId]);

        $tokenResult = $this->getClientToken('refund');

        if (!$tokenResult['success']) {
            return $tokenResult;
        }

        $payload = [
            'payload' => [
                'token' => $tokenResult['token'],
                'id' => $transactionId
            ],
            'method' => 'getRefund'
        ];

        $response = $this->request($payload);

        Log::info('[Efevoo] Refund response', $response);

        return $response;
    }

    /* ==========================================================
     * SEARCH
     * ========================================================== */

    public function searchTransactions(array $filters = []): array
    {
        Log::info('[Efevoo] searchTransactions');

        $tokenResult = $this->getClientToken('search');

        if (!$tokenResult['success']) {
            return $tokenResult;
        }

        return $this->request([
            'payload' => ['token' => $tokenResult['token']] + $filters,
            'method' => 'getTranSearch'
        ]);
    }

    /* ==========================================================
     * HEALTH CHECK
     * ========================================================== */

    public function healthCheck(): array
    {
        $token = $this->getClientToken('health');

        return [
            'status' => $token['success'] ? 'online' : 'offline',
            'timestamp' => now()->toISOString()
        ];
    }

    /* ==========================================================
     * REQUEST
     * ========================================================== */

    protected function request(array $payload): array
    {
        $ch = curl_init($this->config['api_url']);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-API-USER: ' . $this->config['api_user'],
                'X-API-KEY: ' . $this->config['api_key'],
                'Origin: https://efevoopay.com',
                'Referer: https://efevoopay.com/',
                'User-Agent: Mozilla/5.0',
            ],
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING => '',
        ]);

        $response = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        Log::info('[Efevoo] HTTP status', ['status' => $http]);
        Log::info('[Efevoo] RAW response', ['response' => $response]);

        if ($error) {
            Log::error('[Efevoo] CURL error', ['error' => $error]);
        }

        return [
            'success' => $http >= 200 && $http < 300,
            'status' => $http,
            'data' => json_decode($response, true)
        ];
    }

    /* ==========================================================
     * ENCRYPT
     * ========================================================== */

    protected function encrypt(array $data): string
    {
        $encrypted = openssl_encrypt(
            json_encode($data),
            'AES-128-CBC',
            $this->config['clave'],
            OPENSSL_RAW_DATA,
            $this->config['vector']
        );

        if ($encrypted === false) {
            throw new \RuntimeException('Error encriptando datos');
        }

        return base64_encode($encrypted);
    }

    /* ==========================================================
     * TOTP
     * ========================================================== */

    protected function generateTOTP(): string
    {
        $secret = $this->config['totp_secret'];
        $timestamp = floor(time() / 30);

        $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32Lookup = array_flip(str_split($base32Chars));

        $buffer = 0;
        $bitsLeft = 0;
        $result = '';

        for ($i = 0; $i < strlen($secret); $i++) {
            $ch = $secret[$i];
            if (!isset($base32Lookup[$ch]))
                continue;

            $buffer = ($buffer << 5) | $base32Lookup[$ch];
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $result .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        $secretKey = $result;
        $timestampBytes = pack('N*', 0) . pack('N*', $timestamp);
        $hash = hash_hmac('sha1', $timestampBytes, $secretKey, true);
        $offset = ord($hash[19]) & 0xf;

        $code = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % 1000000;

        return str_pad($code, 6, '0', STR_PAD_LEFT);
    }

    protected function validateConfig(): void
    {
        foreach (['api_url', 'clave', 'vector', 'cliente', 'totp_secret'] as $key) {
            if (empty($this->config[$key])) {
                Log::error('[Efevoo] Config missing', ['key' => $key]);
                throw new \RuntimeException("Configuración faltante: {$key}");
            }
        }
    }

    public function complete3DS(Efevoo3dsSession $session, array $cardData): array
    {
        Log::info('[Efevoo] complete3DS', [
            'session_id' => $session->id,
            'order_id' => $session->order_id,
            'current_status' => $session->status
        ]);

        if (!$session->order_id) {
            return [
                'success' => false,
                'message' => 'Order ID no disponible'
            ];
        }

        // 🔒 Evita reprocesar si ya terminó
        if (in_array($session->status, ['completed', 'tokenization_failed', 'declined'])) {
            Log::info('[Efevoo] 3DS ya procesado previamente');
            return [
                'success' => $session->status === 'completed',
                'message' => '3DS ya procesado'
            ];
        }

        // ==========================================================
        // CONSULTAR ESTADO REAL EN EFEVOO
        // ==========================================================

        $statusResponse = $this->payments3DSGetStatus($cardData, $session->order_id);

        Log::info('[Efevoo] GetStatus response', $statusResponse);

        if (!$statusResponse['success']) {
            return [
                'success' => false,
                'message' => 'Error consultando estado 3DS',
                'raw' => $statusResponse
            ];
        }

        $statusCode = $statusResponse['data']['status']['code'] ?? null;
        $payloadStatus = $statusResponse['data']['payload']['status'] ?? null;

        if ($statusCode !== '0') {
            Log::warning('[Efevoo] Error consultando 3DS', [
                'status_code' => $statusCode
            ]);

            return [
                'success' => false,
                'message' => 'Error validando 3DS'
            ];
        }

        // ==========================================================
        // ESTADOS POSIBLES DEL PAYLOAD
        // ==========================================================

        if ($payloadStatus === 'pending') {

            Log::info('[Efevoo] 3DS aún pendiente');

            $session->update([
                'status' => 'pending',
                'status_checked_at' => now()
            ]);

            return [
                'success' => false,
                'message' => '3DS aún pendiente'
            ];
        }

        if (in_array($payloadStatus, ['declined', 'rejected'])) {

            Log::warning('[Efevoo] 3DS rechazado por el banco', [
                'payload_status' => $payloadStatus
            ]);

            $session->update([
                'status' => 'declined',
                'status_checked_at' => now()
            ]);

            return [
                'success' => false,
                'message' => '3DS rechazado por el banco'
            ];
        }

        if (!in_array($payloadStatus, ['authenticated', 'approved'])) {

            Log::warning('[Efevoo] Estado 3DS desconocido', [
                'payload_status' => $payloadStatus
            ]);

            return [
                'success' => false,
                'message' => 'Estado 3DS desconocido'
            ];
        }

        // ==========================================================
        // AUTENTICADO CORRECTAMENTE
        // ==========================================================

        $session->update([
            'status' => 'authenticated',
            'status_checked_at' => now()
        ]);

        Log::info('[Efevoo] 3DS autenticado, iniciando tokenización');

        $tokenResult = $this->tokenizeCard($cardData, $session->customer_id);

        if (!$tokenResult['success']) {

            Log::error('[Efevoo] Error tokenizando después de 3DS', $tokenResult);

            $session->update([
                'status' => 'tokenization_failed'
            ]);

            return [
                'success' => false,
                'message' => $tokenResult['message'] ?? 'Error tokenizando tarjeta',
                'raw' => $tokenResult
            ];
        }

        // ==========================================================
        // COMPLETADO EXITOSAMENTE
        // ==========================================================

        $session->update([
            'status' => 'completed',
            'efevoo_token_id' => $tokenResult['token_id'],
            'completed_at' => now()
        ]);

        Log::info('[Efevoo] 3DS completado correctamente');

        return [
            'success' => true,
            'message' => '3DS completado correctamente'
        ];
    }

    /* ==========================================================
     * 3DS STATUS
     * ========================================================== */
    public function payments3DSGetStatus(array $cardData, string $orderId): array
    {
        Log::info('[Efevoo] payments3DSGetStatus', [
            'order_id' => $orderId
        ]);

        $tokenResult = $this->getClientToken('3ds');

        if (!$tokenResult['success']) {
            return $tokenResult;
        }

        $bodyToEncrypt = [
            'track' => $cardData['card_number'],
            'cvv' => $cardData['cvv'],
            'exp' => substr($cardData['expiration'], 0, 2) . '/' . substr($cardData['expiration'], 2, 2),
            'order_id' => (int) $orderId
        ];

        Log::info('[Efevoo] Body before encrypt GetStatus', $bodyToEncrypt);
        $encrypt = $this->encrypt($bodyToEncrypt);

        $payload = [
            'payload' => [
                'token' => $tokenResult['token'],
                'encrypt' => $encrypt
            ],
            'method' => 'payments3DS_GetStatus'
        ];

        $response = $this->request($payload);

        Log::info('[Efevoo] 3DS Status response', $response);

        return $response;
    }
}