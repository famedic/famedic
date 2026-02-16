<?php

namespace App\Services;

use App\Models\EfevooToken;
use App\Models\EfevooTransaction;
use App\Models\Efevoo3dsSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EfevooPayService
{
    protected array $config;
    protected string $environment;

    public function __construct()
    {
        $this->config = config('efevoopay');
        $this->environment = $this->config['environment'] ?? 'test';

        $this->validateConfig();

        Log::info('EfevooPayService iniciado', [
            'environment' => $this->environment
        ]);
    }

    /* ==========================================================
     *  CLIENT TOKEN
     * ========================================================== */

    public function getClientToken(bool $force = false, string $operation = 'default'): array
    {
        $cacheKey = "efevoo_token_{$operation}";

        if (!$force && Cache::has($cacheKey)) {
            return [
                'success' => true,
                'token' => Cache::get($cacheKey),
                'type' => 'cached'
            ];
        }


        $totp = $this->generateTOTP();
        $hash = base64_encode(hash_hmac('sha256', $this->config['clave'], $totp, true));

        $payload = [
            'payload' => [
                'hash' => $hash,
                'cliente' => $this->config['cliente'],
            ],
            'method' => 'getClientToken'
        ];

        Log::info('[Efevoo] TOKEN REQUEST', [
            'url' => $this->config['api_url'],
            'payload' => $payload
        ]);
        $response = $this->request($payload);

        if (
            $response['success'] &&
            isset($response['data']['token']) &&
            $response['data']['token'] !== 'NA'
        ) {
            Cache::put($cacheKey, $response['data']['token'], now()->addMinutes(30));

            return [
                'success' => true,
                'token' => $response['data']['token'],
                'type' => 'dynamic'
            ];
        }

        Log::info('[Efevoo] TOKEN RESPONSE', $response);
        return $response;
    }

    /* ==========================================================
     *  3DS INIT
     * ========================================================== */

    public function initiate3DSProcess(array $cardData, int $customerId, array $browserInfo = []): array
    {
        Log::info('Iniciando 3DS', [
            'customer_id' => $customerId,
            'last4' => substr($cardData['card_number'], -4),
        ]);

        $tokenResult = $this->getClientToken(false, '3ds');
        if (!$tokenResult['success'])
            return $tokenResult;

        $expiration = $cardData['expiration'];

        $body = [
            'track' => $cardData['card_number'],
            'cvv' => $cardData['cvv'],
            'exp' => $expiration,
            'fiid_comercio' => $this->config['fiid_comercio'],
            'msi' => 0,
            'amount' => number_format($cardData['amount'], 2, '.', ''),
            'browser' => [
                "browserAcceptHeader" => "application/json",
                "browserJavaEnabled" => false,
                "browserJavaScriptEnabled" => true,
                "browserLanguage" => "es-419",
                "browserTZ" => "360",
                "browserUserAgent" => request()->header('User-Agent')
            ]
        ];

        Log::info('[Efevoo] 3DS RAW BODY', [
            'url' => $this->config['api_url'],
            'body_array' => $body,
            'json' => json_encode($body, JSON_UNESCAPED_UNICODE)
        ]);

        $encrypted = $this->encrypt($body);

        $session = Efevoo3dsSession::create([
            'customer_id' => $customerId,
            'card_last_four' => substr($cardData['card_number'], -4),
            'amount' => $cardData['amount'],
            'status' => 'pending',
        ]);

        Log::info('[Efevoo] 3DS ENCRYPTED', [
            'encrypt_length' => strlen($encrypted),
            'encrypt_sample' => substr($encrypted, 0, 40) . '...'
        ]);

        $payload = [
            'payload' => [
                'token' => $tokenResult['token'],
                'encrypt' => $encrypted
            ],
            'method' => 'payments3DS_GetLink'
        ];

        Log::info('[Efevoo] 3DS FINAL PAYLOAD', [
            'method' => 'payments3DS_GetLink',
            'payload' => $payload
        ]);
        $response = $this->request($payload);

        if (!$response['success']) {
            $session->update(['status' => 'failed']);
            return $response;
        }

        $data = $response['data']['payload'] ?? null;

        Log::info('[Efevoo] 3DS GetLink Response', $data);
        if ($data && !empty($data['url_3dsecure']) && !empty($data['token_3dsecure'])) {

            Log::info('[Efevoo] 3DS GetLink Response', $data);
            $session->update([
                'status' => 'redirect_required',
                'order_id' => $data['order_id'],
                'url_3dsecure' => $data['url_3dsecure'],
                'token_3dsecure' => $data['token_3dsecure'],
            ]);

            return [
                'success' => true,
                'requires_3ds' => true,
                'session_id' => $session->id,
                'url_3dsecure' => $data['url_3dsecure'],
                'token_3dsecure' => $data['token_3dsecure'],
            ];
        }

        return $this->complete3DS($session, $cardData);
    }

    /* ==========================================================
     *  COMPLETE 3DS
     * ========================================================== */

    /*public function check3DSStatus(string $sessionId, int $orderId, array $cardData): array
    {
        $session = Efevoo3dsSession::findOrFail($sessionId);

        return $this->complete3DS($session, $cardData);
    }*/


    /* ==========================================================
     *  TOKENIZE
     * ========================================================== */

    public function tokenizeCard(array $cardData, int $customerId): array
    {
        $tokenResult = $this->getClientToken(false, 'tokenize');
        if (!$tokenResult['success'])
            return $tokenResult;

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

        if ($response['success'] && isset($response['data']['token_usuario'])) {

            $token = EfevooToken::create([
                'customer_id' => $customerId,
                'card_token' => $response['data']['token_usuario'],
                'card_last_four' => substr($cardData['card_number'], -4),
                'card_expiration' => $cardData['expiration'],
                'card_holder' => $cardData['card_holder'],
                'alias' => $cardData['alias'] ?? null,
                'environment' => $this->environment,
            ]);

            return [
                'success' => true,
                'token_id' => $token->id
            ];
        }

        return $response;
    }

    /* ==========================================================
     *  CHARGE
     * ========================================================== */

    public function chargeCard(array $data): array
{
    $tokenResult = $this->getClientToken(false, 'payment');
    if (!$tokenResult['success']) {
        return $tokenResult;
    }

    $payload = [
        'payload' => [
            'token' => $tokenResult['token'],
            'encrypt' => $this->encrypt([
                'track2' => $data['token_id'],
                'amount' => number_format($data['amount'], 2, '.', ''),
                'cav' => 'PAY' . time(),
                'referencia' => $data['reference'] ?? 'REF-' . time()
            ])
        ],
        'method' => 'getPayment'
    ];

    $response = $this->request($payload);

    if (!$response['success']) {
        return $response;
    }

    $data = $response['data'];

    // 🔥 Manejo seguro si no existe codigo
    if (!isset($data['codigo'])) {
        return [
            'success' => false,
            'status' => 'failed',
            'message' => $data['mensaje'] ?? 'Error desconocido en Efevoo',
            'raw' => $data
        ];
    }

    return [
        'success' => $data['codigo'] === '00',
        'status' => $data['codigo'] === '00' ? 'completed' : 'failed',
        'transaction_id' => $data['id'] ?? null,
        'authorization_code' => $data['numref'] ?? null,
        'numtxn' => $data['numtxn'] ?? null,
        'message' => $data['descripcion'] ?? null,
        'raw' => $data,
    ];
}

    /* ==========================================================
     *  REFUND
     * ========================================================== */

    public function refundTransaction(int $transactionId): array
    {
        $tokenResult = $this->getClientToken(false, 'refund');
        if (!$tokenResult['success'])
            return $tokenResult;

        $payload = [
            'payload' => [
                'token' => $tokenResult['token'],
                'id' => $transactionId
            ],
            'method' => 'getRefund'
        ];

        return $this->request($payload);
    }

    /* ==========================================================
     *  SEARCH
     * ========================================================== */

    public function searchTransactions(array $filters = []): array
    {
        $tokenResult = $this->getClientToken(false, 'search');
        if (!$tokenResult['success'])
            return $tokenResult;

        return $this->request([
            'payload' => ['token' => $tokenResult['token']] + $filters,
            'method' => 'getTranSearch'
        ]);
    }

    /* ==========================================================
     *  HEALTH CHECK
     * ========================================================== */

    public function healthCheck(): array
    {
        $token = $this->getClientToken(true, 'health');

        return [
            'status' => $token['success'] ? 'online' : 'offline',
            'environment' => $this->environment,
            'timestamp' => now()->toISOString()
        ];
    }

    /* ==========================================================
     *  INTERNAL HELPERS
     * ========================================================== */

    protected function request(array $payload): array
    {
        Log::info('[Efevoo] REQUEST OUTGOING', [
            'url' => $this->config['api_url'],
            'payload' => $payload
        ]);

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
                'User-Agent: Mozilla/5.0'
            ],
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => '',
            CURLOPT_FOLLOWLOCATION => true
        ]);

        $response = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        curl_close($ch);

        Log::info('[Efevoo] RESPONSE RAW', [
            'http_code' => $http,
            'curl_error' => $curlError,
            'response_raw' => $response
        ]);

        $data = json_decode($response, true);

        return [
            'success' => $http >= 200 && $http < 300,
            'status' => $http,
            'data' => $data,
            'message' => $data['descripcion'] ?? null
        ];
    }


    protected function encrypt(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        $encrypted = openssl_encrypt(
            $json,
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

    protected function generateTOTP(): string
    {
        $secret = $this->config['totp_secret'];

        $timestamp = floor(time() / 30);

        $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32Lookup = array_flip(str_split($base32Chars));

        $buffer = 0;
        $bitsLeft = 0;
        $result = '';

        // Decodificar Base32
        for ($i = 0; $i < strlen($secret); $i++) {
            $ch = $secret[$i];

            if (!isset($base32Lookup[$ch])) {
                continue;
            }

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
        ) % pow(10, 6);

        return str_pad($code, 6, '0', STR_PAD_LEFT);
    }


    protected function validateConfig(): void
    {
        foreach (['api_url', 'clave', 'vector', 'cliente', 'totp_secret'] as $key) {
            if (empty($this->config[$key])) {
                throw new \RuntimeException("Configuración faltante: {$key}");
            }
        }
    }

    public function payments3DSGetStatus(int $orderId): array
    {
        Log::info('[Efevoo] payments3DSGetStatus', [
            'order_id' => $orderId
        ]);

        $tokenResult = $this->getClientToken(false, '3ds');

        if (!$tokenResult['success']) {
            return $tokenResult;
        }

        $payload = [
            'payload' => [
                'token' => $tokenResult['token'],
                'order_id' => $orderId
            ],
            'method' => 'payments3DS_GetStatus'
        ];

        $response = $this->request($payload);

        if (!$response['success']) {
            return $response;
        }

        return [
            'success' => true,
            'data' => $response['data']
        ];
    }


    public function complete3DS(Efevoo3dsSession $session, array $cardData = []): array
    {
        Log::info('[Efevoo] Completing 3DS', [
            'session_id' => $session->id,
            'order_id' => $session->order_id,
        ]);

        if (!$session->order_id) {
            return [
                'success' => false,
                'message' => 'Order ID no disponible'
            ];
        }

        $statusResponse = $this->payments3DSGetStatus((int) $session->order_id);

        if (!$statusResponse['success']) {
            $session->update([
                'status' => Efevoo3dsSession::STATUS_FAILED,
                'error_message' => $statusResponse['message'] ?? 'Error verificando 3DS'
            ]);

            return $statusResponse;
        }

        // Aquí puedes validar status_code si la API lo devuelve
        // Ejemplo:
        // if ($statusResponse['data']['status'] !== 'approved') { ... }

        $session->update([
            'status' => Efevoo3dsSession::STATUS_AUTHENTICATED,
            'status_check_response' => $statusResponse['data'],
            'status_checked_at' => now(),
        ]);

        // Ahora tokenizamos la tarjeta
        if (!empty($cardData)) {

            $tokenResult = $this->tokenizeCard($cardData, $session->customer_id);

            if ($tokenResult['success']) {
                $session->update([
                    'status' => Efevoo3dsSession::STATUS_COMPLETED,
                    'efevoo_token_id' => $tokenResult['token_id'],
                    'completed_at' => now(),
                ]);
            } else {
                $session->update([
                    'status' => Efevoo3dsSession::STATUS_TOKENIZATION_FAILED,
                    'error_message' => $tokenResult['message'] ?? 'Error tokenizando tarjeta'
                ]);
            }

            return $tokenResult;
        }

        return [
            'success' => true,
            'message' => '3DS autenticado'
        ];
    }


    private function apiCall(array $body): array
    {
        $url = config('efevoopay.api_url');

        $headers = [
            'Content-Type: application/json',
            'X-API-USER: ' . config('efevoopay.api_user'),
            'X-API-KEY: ' . config('efevoopay.api_key'),
            'Accept: application/json',
        ];

        \Log::info('[Efevoo] API Request', [
            'method' => $body['method'] ?? null,
            'url' => $url
        ]);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => app()->environment('production'),
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        curl_close($ch);

        if ($curlError) {
            \Log::error('[Efevoo] cURL Error', [
                'error' => $curlError
            ]);

            throw new \Exception('Error de conexión con EfevooPay');
        }

        if ($httpCode !== 200) {
            \Log::error('[Efevoo] HTTP Error', [
                'http_code' => $httpCode,
                'response' => $response
            ]);

            throw new \Exception("Error HTTP {$httpCode} con EfevooPay");
        }

        $decoded = json_decode($response, true);

        if (!$decoded) {
            \Log::error('[Efevoo] JSON inválido', [
                'response' => $response
            ]);

            throw new \Exception('Respuesta inválida de EfevooPay');
        }

        \Log::info('[Efevoo] API Response OK', [
            'method' => $body['method'] ?? null,
            'status_code' => $decoded['status']['code'] ?? null
        ]);

        return $decoded;
    }

}
