<?php

namespace App\Services;

use App\Models\Efevoo3dsSession;
use App\Models\EfevooToken;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EfevooPayService
{
    public const ERROR_BANK = 'bank';

    public const ERROR_GATEWAY = 'gateway';

    public const ERROR_SYSTEM = 'system';

    public const ERROR_NETWORK = 'network';

    protected array $config;

    public function __construct()
    {
        $this->config = config('efevoopay');

        if (config('app.debug')) {
            Log::debug('[Efevoo] Service boot', [
                'cliente' => $this->config['cliente'],
                'api_url' => $this->config['api_url'] ?? null,
            ]);
        }

        $this->validateConfig();
    }

    /* ==========================================================
     * NORMALIZACIÓN Y PAYLOADS (track vs track2)
     * ========================================================== */

    /**
     * Normaliza datos de tarjeta: PAN solo dígitos, exp MMYY, CVV sin espacios.
     *
     * @param  array<string, mixed>  $cardData
     * @return array<string, mixed>
     */
    public function normalizeCardDataInput(array $cardData): array
    {
        $pan = preg_replace('/\D/', '', (string) ($cardData['card_number'] ?? ''));
        $expiration = preg_replace('/\D/', '', (string) ($cardData['expiration'] ?? ''));

        return array_merge($cardData, [
            'card_number' => $pan,
            'expiration' => $expiration,
            'cvv' => isset($cardData['cvv']) ? preg_replace('/\s/', '', (string) $cardData['cvv']) : '',
        ]);
    }

    /**
     * Formato exp para 3DS (GetLink / GetStatus): MM/YY
     */
    protected function formatExpirationFor3DS(string $expirationMmyy): string
    {
        if (strlen($expirationMmyy) !== 4) {
            throw new \InvalidArgumentException('Expiración MMYY inválida');
        }

        return substr($expirationMmyy, 0, 2) . '/' . substr($expirationMmyy, 2, 2);
    }

    /**
     * Track2 para tokenización: PAN=YYMM (ISO track2 service code).
     */
    public function buildTrack2(string $panDigits, string $expirationMmyy): string
    {
        $panDigits = preg_replace('/\D/', '', $panDigits);
        $expirationMmyy = preg_replace('/\D/', '', $expirationMmyy);

        if (strlen($panDigits) < 13 || strlen($panDigits) > 19) {
            throw new \InvalidArgumentException('PAN inválido para track2');
        }
        if (strlen($expirationMmyy) !== 4) {
            throw new \InvalidArgumentException('Expiración inválida para track2');
        }

        $mm = substr($expirationMmyy, 0, 2);
        $yy = substr($expirationMmyy, 2, 2);

        return $panDigits . '=' . $yy . $mm;
    }

    /**
     * @param  array<string, mixed>  $cardData  Normalizado (PAN, expiration MMYY, cvv, amount)
     * @return array<string, mixed>
     */
    protected function build3DSPayload(array $cardData): array
    {
        $expFormatted = $this->formatExpirationFor3DS($cardData['expiration']);

        $tzMinutes = (int) ((new \DateTime)->getOffset() / 60);
        $browserTz = (string) abs($tzMinutes);

        $acceptHeader = request()->header('Accept');
        if (!$acceptHeader || str_contains($acceptHeader, 'application/json')) {
            $acceptHeader = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8';
        }

        return [
            'track' => $cardData['card_number'],
            'cvv' => $cardData['cvv'],
            'exp' => $expFormatted,
            'fiid_comercio' => $this->config['fiid_comercio'],
            'msi' => 0,
            'amount' => number_format((float) $cardData['amount'], 2, '.', ''),
            'browser' => [
                'browserAcceptHeader' => $acceptHeader,
                'browserJavaEnabled' => false,
                'browserJavaScriptEnabled' => true,
                'browserLanguage' => 'es-419',
                'browserTZ' => $browserTz,
                'browserUserAgent' => request()->header('User-Agent')
                    ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0 Safari/537.36',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $cardData  Normalizado
     * @return array<string, mixed>
     */
    protected function buildGetStatusPayload(array $cardData, string $orderId): array
    {
        return [
            'track' => $cardData['card_number'],
            'cvv' => $cardData['cvv'],
            'exp' => $this->formatExpirationFor3DS($cardData['expiration']),
            'order_id' => (int) $orderId,
        ];
    }

    /**
     * Cuerpo encriptado para getTokenize (track2 + amount; no track/cvv/exp sueltos).
     *
     * @return array{track2: string, amount: string}
     */
    protected function buildTokenizeEncryptBody(array $cardData): array
    {
        $track2 = $this->buildTrack2($cardData['card_number'], $cardData['expiration']);

        return [
            'track2' => $track2,
            'amount' => number_format((float) $cardData['amount'], 2, '.', ''),
        ];
    }

    /**
     * Cuerpo encriptado para getPayment (el campo track2 lleva el token de usuario guardado).
     *
     * @param  array{card_token: string, amount: float|int|string, reference?: string}  $data
     * @return array{track2: string, amount: string, referencia: string}
     */
    protected function buildPaymentEncryptBody(array $data): array
    {
        return [
            'track2' => $data['card_token'],
            'amount' => number_format((float) $data['amount'], 2, '.', ''),
            'referencia' => $data['reference'] ?? 'REF-' . time(),
        ];
    }

    /**
     * @param  array<string, mixed>  $cardData
     * @return array<string, string|null>
     */
    protected function logSafeCardContext(array $cardData, array $extra = []): array
    {
        $pan = preg_replace('/\D/', '', (string) ($cardData['card_number'] ?? ''));

        return array_merge([
            'card_last4' => strlen($pan) >= 4 ? substr($pan, -4) : null,
        ], $extra);
    }

    protected function shouldLogVerbose(): bool
    {
        return (bool) config('efevoopay.log_requests', true) && config('app.debug');
    }

    /**
     * Código de procesador (00 = éxito en pagos).
     */
    protected function normalizeProcessorCode($codigo): string
    {
        if ($codigo === null || $codigo === '') {
            return '';
        }
        if (is_numeric($codigo)) {
            return str_pad((string) (int) $codigo, 2, '0', STR_PAD_LEFT);
        }

        $s = (string) $codigo;

        return strlen($s) === 1 ? '0' . $s : $s;
    }

    /* ==========================================================
     * CLIENT TOKEN
     * ========================================================== */

    public function getClientToken(string $operation = 'default'): array
    {
        if ($this->shouldLogVerbose()) {
            Log::debug('[Efevoo] getClientToken', ['operation' => $operation]);
        }

        try {
            $totp = $this->generateTOTP();

            $hash = base64_encode(
                hash_hmac('sha256', $this->config['clave'], $totp, true)
            );

            $payload = [
                'payload' => [
                    'hash' => $hash,
                    'cliente' => $this->config['cliente'],
                ],
                'method' => 'getClientToken',
            ];

            $response = $this->request($payload, logRawBody: false);

            if (
                $response['success']
                && ($response['data']['codigo'] ?? null) === '100'
                && !empty($response['data']['token'])
            ) {
                return [
                    'success' => true,
                    'token' => $response['data']['token'],
                ];
            }

            return [
                'success' => false,
                'message' => 'Token inválido',
                'error_type' => self::ERROR_GATEWAY,
                'raw' => $response,
            ];
        } catch (\Throwable $e) {
            Log::error('[Efevoo] getClientToken exception', [
                'message' => $e->getMessage(),
                'operation' => $operation,
            ]);

            return [
                'success' => false,
                'message' => 'Excepción generando token',
                'error_type' => self::ERROR_SYSTEM,
            ];
        }
    }

    /* ==========================================================
     * 3DS INIT
     * ========================================================== */

    public function initiate3DS(array $cardData, int $customerId): array
    {
        $cardData = $this->normalizeCardDataInput($cardData);
        $ctx = $this->logSafeCardContext($cardData);

        try {
            Log::info('[Efevoo] initiate3DS', $ctx);

            $tokenResult = $this->getClientToken('3ds');

            if (!$tokenResult['success']) {
                Log::warning('[Efevoo] Token failed 3DS', ['error_type' => $tokenResult['error_type'] ?? null]);

                return $tokenResult;
            }

            if (strlen($cardData['expiration']) !== 4) {
                Log::warning('[Efevoo] Expiration format inválido', $ctx);

                return [
                    'success' => false,
                    'message' => 'Formato de expiración inválido',
                    'error_type' => self::ERROR_SYSTEM,
                ];
            }

            $body = $this->build3DSPayload($cardData);

            if ($this->shouldLogVerbose()) {
                Log::debug('[Efevoo] 3DS payload keys', ['keys' => array_keys($body)]);
            }

            $encrypted = $this->encrypt($body);

            $payload = [
                'payload' => [
                    'token' => $tokenResult['token'],
                    'encrypt' => $encrypted,
                ],
                'method' => 'payments3DS_GetLink',
            ];

            $response = $this->request($payload, logRawBody: false);

            if (!$response['success']) {
                return array_merge($response, [
                    'error_type' => self::ERROR_NETWORK,
                ]);
            }

            $statusCode = $response['data']['status']['code'] ?? null;

            if ($statusCode !== '0') {
                Log::warning('[Efevoo] 3DS GetLink rechazado', [
                    'status_code' => $statusCode,
                    'description' => $response['data']['status']['description'] ?? null,
                    ...$ctx,
                ]);

                return [
                    'success' => false,
                    'message' => $response['data']['status']['description'] ?? 'Error en 3DS',
                    'error_type' => self::ERROR_GATEWAY,
                    'raw' => $response,
                ];
            }

            $data = $response['data']['payload'] ?? null;

            if (!$data || empty($data['order_id'])) {
                return [
                    'success' => false,
                    'message' => 'Respuesta inválida de 3DS',
                    'error_type' => self::ERROR_GATEWAY,
                    'raw' => $response,
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
                'line' => $e->getLine(),
                ...$ctx,
            ]);

            return [
                'success' => false,
                'message' => 'Error interno iniciando 3DS',
                'error_type' => self::ERROR_SYSTEM,
            ];
        }
    }

    /* ==========================================================
     * TOKENIZE
     * ========================================================== */

    public function tokenizeCard(array $cardData, int $customerId): array
    {
        $cardData = $this->normalizeCardDataInput($cardData);
        $ctx = $this->logSafeCardContext($cardData);

        Log::info('[Efevoo] tokenizeCard', $ctx);

        $lastFour = strlen($cardData['card_number']) >= 4
            ? substr($cardData['card_number'], -4)
            : '';

        $expiration = $cardData['expiration'];
        if (strlen($expiration) !== 4) {
            return [
                'success' => false,
                'message' => 'Formato de fecha de expiración inválido',
                'error_type' => self::ERROR_SYSTEM,
            ];
        }

        $existing = EfevooToken::where('customer_id', $customerId)
            ->where('card_last_four', $lastFour)
            ->where('card_expiration', $expiration)
            ->where('is_active', true)
            ->first();

        if ($existing) {
            Log::info('[Efevoo] Tarjeta ya existente, reutilizando', [
                'customer_id' => $customerId,
                'card_last_four' => $lastFour,
                'token_id' => $existing->id,
            ]);

            $existing->update([
                'alias' => $cardData['alias'] ?? $existing->alias,
                'card_holder' => $cardData['card_holder'] ?? $existing->card_holder,
                'updated_at' => now(),
            ]);

            return [
                'success' => true,
                'token_id' => $existing->id,
                'reused' => true,
            ];
        }

        $tokenResult = $this->getClientToken('tokenize');

        if (!$tokenResult['success']) {
            return $tokenResult;
        }

        try {
            $encryptBody = $this->buildTokenizeEncryptBody($cardData);
        } catch (\InvalidArgumentException $e) {
            Log::warning('[Efevoo] tokenizeCard track2', ['message' => $e->getMessage(), ...$ctx]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => self::ERROR_SYSTEM,
            ];
        }

        $payload = [
            'payload' => [
                'token' => $tokenResult['token'],
                'encrypt' => $this->encrypt($encryptBody),
            ],
            'method' => 'getTokenize',
        ];

        $response = $this->request($payload, logRawBody: false);

        $tokenizeFailure = $this->interpretTokenizeResponse($response);

        if (!$tokenizeFailure['success']) {
            return $tokenizeFailure;
        }

        $token = EfevooToken::create([
            'customer_id' => $customerId,
            'card_token' => $response['data']['token_usuario'],
            'client_token' => $response['data']['token'] ?? null,
            'card_last_four' => $lastFour,
            'card_expiration' => $expiration,
            'card_holder' => $cardData['card_holder'] ?? '',
            'alias' => $cardData['alias'] ?? null,
            'environment' => config('efevoopay.environment', 'test'),
        ]);

        return [
            'success' => true,
            'token_id' => $token->id,
        ];
    }

    /**
     * @return array{success: bool, message?: string, error_type?: string, raw?: array, error_code?: string}
     */
    protected function interpretTokenizeResponse(array $response): array
    {
        if (!$response['success']) {
            return [
                'success' => false,
                'message' => 'Error de red al tokenizar',
                'error_type' => self::ERROR_NETWORK,
                'raw' => $response,
            ];
        }

        $data = $response['data'] ?? [];

        if (!empty($data['token_usuario'])) {
            return ['success' => true];
        }

        $codigo = $data['codigo'] ?? null;
        $normalized = $this->normalizeProcessorCode($codigo);

        if ($normalized !== '' && $normalized !== '00') {
            $message = is_string($data['descripcion'] ?? null)
                ? $data['descripcion']
                : (is_string($data['msg'] ?? null) ? $data['msg'] : 'Tokenización no aprobada');

            return [
                'success' => false,
                'message' => $message,
                'error_type' => self::ERROR_GATEWAY,
                'error_code' => $normalized,
                'raw' => $response,
            ];
        }

        $message = $data['descripcion']
            ?? $data['msg']
            ?? $data['message']
            ?? 'Tokenización no aprobada';
        $msgStr = is_string($message) ? $message : 'Tokenización no aprobada';

        return [
            'success' => false,
            'message' => $msgStr,
            'error_type' => self::ERROR_GATEWAY,
            'raw' => $response,
        ];
    }

    /* ==========================================================
     * CHARGE
     * ========================================================== */

    public function chargeCard(array $data): array
    {
        Log::info('[Efevoo] chargeCard', [
            'has_token' => !empty($data['card_token']),
        ]);

        if (empty($data['card_token'])) {
            Log::error('[Efevoo] card_token vacío en chargeCard');

            return [
                'success' => false,
                'message' => 'Token de tarjeta inválido',
                'error_type' => self::ERROR_SYSTEM,
            ];
        }

        $tokenResult = $this->getClientToken('payment');

        if (!$tokenResult['success']) {
            return $tokenResult;
        }

        $encryptBody = $this->buildPaymentEncryptBody($data);

        $payload = [
            'payload' => [
                'token' => $tokenResult['token'],
                'encrypt' => $this->encrypt($encryptBody),
            ],
            'method' => 'getPayment',
        ];

        $response = $this->request($payload, logRawBody: false);

        if (!$response['success']) {
            return [
                'success' => false,
                'message' => 'Error de red al procesar el pago',
                'error_type' => self::ERROR_NETWORK,
                'raw' => $response,
            ];
        }

        $codigo = $response['data']['codigo'] ?? null;
        $normalized = $this->normalizeProcessorCode($codigo);

        if ($normalized !== '00') {
            return [
                'success' => false,
                'message' => $response['data']['descripcion']
                    ?? $response['data']['msg']
                    ?? 'Pago no aprobado',
                'error_type' => self::ERROR_GATEWAY,
                'error_code' => $normalized !== '' ? $normalized : null,
                'raw' => $response,
            ];
        }

        return [
            'success' => true,
            'transaction_id' => $response['data']['id'] ?? null,
            'authorization_code' => $response['data']['numref'] ?? null,
            'raw' => $response,
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
                'id' => $transactionId,
            ],
            'method' => 'getRefund',
        ];

        return $this->request($payload, logRawBody: false);
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
            'method' => 'getTranSearch',
        ], logRawBody: false);
    }

    /* ==========================================================
     * HEALTH CHECK
     * ========================================================== */

    public function healthCheck(): array
    {
        $token = $this->getClientToken('health');

        return [
            'status' => $token['success'] ? 'online' : 'offline',
            'timestamp' => now()->toISOString(),
        ];
    }

    /* ==========================================================
     * REQUEST
     * ========================================================== */

    protected function request(array $payload, bool $logRawBody = true): array
    {
        $ch = curl_init($this->config['api_url']);

        $verifySsl = (bool) config('efevoopay.verify_ssl', false);
        $timeout = (int) config('efevoopay.timeout', 30);

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
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING => '',
        ]);

        $response = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if (config('efevoopay.log_requests', true)) {
            Log::info('[Efevoo] HTTP', ['status' => $http, 'curl_error' => $error ?: null]);
            if ($logRawBody && $this->shouldLogVerbose() && is_string($response)) {
                Log::debug('[Efevoo] RAW response FULL', [
                    'http_status' => $http,
                    'body' => json_decode($response, true) ?? $response
                ]);
            }
        }

        if ($error) {
            Log::error('[Efevoo] CURL error', ['error' => $error]);
        }

        return [
            'success' => $http >= 200 && $http < 300,
            'status' => $http,
            'data' => json_decode($response, true),
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
        $cardData = $this->normalizeCardDataInput($cardData);
        $ctx = $this->logSafeCardContext($cardData, [
            'session_id' => $session->id,
            'order_id' => $session->order_id,
        ]);

        Log::info('[Efevoo] complete3DS', [
            'session_id' => $session->id,
            'order_id' => $session->order_id,
            'current_status' => $session->status,
            'card_last4' => $ctx['card_last4'],
        ]);

        if (!$session->order_id) {
            return [
                'success' => false,
                'message' => 'Order ID no disponible',
                'error_type' => self::ERROR_SYSTEM,
            ];
        }

        if (in_array($session->status, ['completed', 'tokenization_failed', 'declined'], true)) {
            Log::info('[Efevoo] 3DS ya procesado previamente', [
                'session_id' => $session->id,
                'status' => $session->status,
            ]);

            return [
                'success' => $session->status === 'completed',
                'message' => '3DS ya procesado',
                'error_type' => self::ERROR_SYSTEM,
            ];
        }

        $statusResponse = $this->payments3DSGetStatus($cardData, (string) $session->order_id);

        if (!$statusResponse['success']) {
            return [
                'success' => false,
                'message' => 'Error consultando estado 3DS',
                'error_type' => self::ERROR_NETWORK,
                'raw' => $statusResponse,
            ];
        }

        $statusCode = $statusResponse['data']['status']['code'] ?? null;
        $payloadStatus = $statusResponse['data']['payload']['status'] ?? null;

        Log::info('[Efevoo] 3DS status', [
            'order_id' => $session->order_id,
            'status' => $payloadStatus,
            'card_last4' => $ctx['card_last4'],
        ]);

        if ($statusCode !== '0') {
            Log::warning('[Efevoo] GetStatus envelope inválido', [
                'status_code' => $statusCode,
                'order_id' => $session->order_id,
            ]);

            return [
                'success' => false,
                'message' => $statusResponse['data']['status']['description'] ?? 'Error validando 3DS',
                'error_type' => self::ERROR_GATEWAY,
                'raw' => $statusResponse,
            ];
        }

        if ($payloadStatus === 'pending') {
            $session->update([
                'status' => 'pending',
                'status_checked_at' => now(),
            ]);

            return [
                'success' => false,
                'message' => '3DS aún pendiente',
                'error_type' => 'pending',
            ];
        }

        if (in_array($payloadStatus, ['declined', 'rejected'], true)) {
            $declineMessage = 'La verificación fue rechazada por tu banco. Puede deberse a que cancelaste el proceso o el banco no autorizó la operación.';
            Log::warning('[Efevoo] 3DS rechazado por el banco', [
                'payload_status' => $payloadStatus,
                'order_id' => $session->order_id,
                'card_last4' => $ctx['card_last4'],
            ]);

            $session->update([
                'status' => 'declined',
                'status_checked_at' => now(),
                'error_message' => $declineMessage,
            ]);

            return [
                'success' => false,
                'message' => $declineMessage,
                'error_type' => self::ERROR_BANK,
            ];
        }

        if (!in_array($payloadStatus, ['authenticated', 'approved'], true)) {
            Log::warning('[Efevoo] Estado 3DS desconocido', [
                'payload_status' => $payloadStatus,
                'order_id' => $session->order_id,
            ]);

            return [
                'success' => false,
                'message' => 'Estado 3DS desconocido',
                'error_type' => self::ERROR_GATEWAY,
            ];
        }

        $lockKey = 'efevoo_3ds_tokenize_' . $session->id;

        try {
            return Cache::lock($lockKey, 90)->block(20, function () use ($session, $cardData, $ctx) {
                $session->refresh();

                if ($session->status === 'completed') {
                    Log::info('[Efevoo] 3DS ya completado (lock)', ['session_id' => $session->id]);

                    return [
                        'success' => true,
                        'message' => '3DS completado correctamente',
                        'error_type' => null,
                    ];
                }

                if (in_array($session->status, ['declined', 'tokenization_failed'], true)) {
                    return [
                        'success' => false,
                        'message' => $session->error_message ?? 'Proceso finalizado',
                        'error_type' => self::ERROR_BANK,
                    ];
                }

                $session->update([
                    'status' => 'authenticated',
                    'status_checked_at' => now(),
                ]);

                Log::info('[Efevoo] 3DS autenticado, tokenizando', [
                    'order_id' => $session->order_id,
                    'card_last4' => $ctx['card_last4'],
                ]);

                $tokenResult = $this->tokenizeCard($cardData, $session->customer_id);

                if (!$tokenResult['success']) {
                    Log::error('[Efevoo] Error tokenizando después de 3DS', [
                        'message' => $tokenResult['message'] ?? null,
                        'error_type' => $tokenResult['error_type'] ?? null,
                        'order_id' => $session->order_id,
                        'card_last4' => $ctx['card_last4'],
                    ]);

                    $errorMessage = $tokenResult['message'] ?? 'Error tokenizando tarjeta';
                    $session->update([
                        'status' => 'tokenization_failed',
                        'error_message' => $errorMessage,
                    ]);

                    return [
                        'success' => false,
                        'message' => $errorMessage,
                        'error_type' => $tokenResult['error_type'] ?? self::ERROR_GATEWAY,
                        'raw' => $tokenResult['raw'] ?? null,
                    ];
                }

                $session->update([
                    'status' => 'completed',
                    'efevoo_token_id' => $tokenResult['token_id'],
                    'completed_at' => now(),
                ]);

                Log::info('[Efevoo] 3DS completado correctamente', [
                    'order_id' => $session->order_id,
                    'card_last4' => $ctx['card_last4'],
                ]);

                return [
                    'success' => true,
                    'message' => '3DS completado correctamente',
                ];
            });
        } catch (LockTimeoutException $e) {
            Log::warning('[Efevoo] Timeout esperando lock de tokenización', [
                'session_id' => $session->id,
                'order_id' => $session->order_id,
            ]);

            return [
                'success' => false,
                'message' => 'El proceso sigue en curso. Espera unos segundos.',
                'error_type' => self::ERROR_SYSTEM,
            ];
        }
    }

    /* ==========================================================
     * 3DS STATUS
     * ========================================================== */

    public function payments3DSGetStatus(array $cardData, string $orderId): array
    {
        $cardData = $this->normalizeCardDataInput($cardData);

        Log::info('[Efevoo] payments3DSGetStatus', [
            'order_id' => $orderId,
            'card_last4' => $this->logSafeCardContext($cardData)['card_last4'],
        ]);

        $tokenResult = $this->getClientToken('3ds');

        if (!$tokenResult['success']) {
            return $tokenResult;
        }

        if (strlen($cardData['expiration']) !== 4) {
            return [
                'success' => false,
                'message' => 'Formato de expiración inválido',
                'error_type' => self::ERROR_SYSTEM,
            ];
        }

        $bodyToEncrypt = $this->buildGetStatusPayload($cardData, $orderId);

        if ($this->shouldLogVerbose()) {
            Log::debug('[Efevoo] GetStatus payload keys', ['keys' => array_keys($bodyToEncrypt)]);
        }

        $encrypt = $this->encrypt($bodyToEncrypt);

        $payload = [
            'payload' => [
                'token' => $tokenResult['token'],
                'encrypt' => $encrypt,
            ],
            'method' => 'payments3DS_GetStatus',
        ];

        return $this->request($payload, logRawBody: false);
    }
}
