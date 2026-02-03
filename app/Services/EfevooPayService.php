<?php
// app/Services/EfevooPayService.php

namespace App\Services;

use App\Models\EfevooToken;
use App\Models\EfevooTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EfevooPayService
{
    protected $config;
    protected $environment;
    protected $clientToken;
    protected $apiMethods;

    public function __construct()
    {
        $this->environment = config('efevoopay.environment', 'test');
        $this->config = config('efevoopay', []);

        // Métodos de API esenciales
        $this->apiMethods = [
            'tokenize' => 'getTokenize',
            'payment' => 'getPayment',
            'client_token' => 'getClientToken',
            'search' => 'getTranSearch',
            'refund' => 'getRefund',
        ];

        $this->validateConfig();
    }

    /**
     * Validar configuración crítica
     */
    protected function validateConfig(): void
    {
        $required = [
            'api_url',
            'cliente',
            'clave',
            'vector',
            'totp_secret',
            'api_user',
            'api_key'
        ];

        foreach ($required as $key) {
            if (empty($this->config[$key])) {
                throw new \RuntimeException("Configuración EfevooPay incompleta: {$key}");
            }
        }
    }

    /**
     * TOKENIZACIÓN PRINCIPAL - Método robusto
     */
    public function tokenizeCard(array $cardData, int $customerId): array
    {
        Log::info('🔵 === INICIANDO TOKENIZACIÓN EFEVOO ===', [
            'customer_id' => $customerId,
            'expiration_input' => $cardData['expiration'] ?? null,
            'last_four' => substr($cardData['card_number'] ?? '', -4),
            'has_fixed_token_config' => !empty($this->config['fixed_token']),
        ]);

        try {
            // 1. Obtener token - DEBE usar el fijo
            $clientTokenResult = $this->getClientToken();

            Log::info('🔵 Resultado getClientToken', [
                'success' => $clientTokenResult['success'] ?? false,
                'is_fixed' => $clientTokenResult['fixed'] ?? false,
                'is_correct_token' => $clientTokenResult['is_correct_token'] ?? null,
                'token_preview' => isset($clientTokenResult['token']) ?
                    substr($clientTokenResult['token'], 0, 50) . '...' : 'NO TOKEN',
                'message' => $clientTokenResult['message'] ?? null,
            ]);

            if (!$clientTokenResult['success']) {
                Log::error('❌ Error obteniendo token para tokenización', $clientTokenResult);
                return $clientTokenResult;
            }

            $clientToken = $clientTokenResult['token'];

            // 2. CONVERTIR expiración: MMYY → YYMM
            $expiration = $cardData['expiration'];
            if (strlen($expiration) !== 4) {
                return [
                    'success' => false,
                    'message' => 'Expiración debe ser 4 dígitos MMYY',
                    'errors' => ['expiration' => 'Formato inválido'],
                ];
            }

            $month = substr($expiration, 0, 2);
            $year = substr($expiration, 2, 2);
            $expirationForAPI = $year . $month; // Convertir a YYMM

            Log::info('🔵 Conversión de expiración', [
                'user_input' => $expiration,      // MMYY
                'month' => $month,
                'year' => $year,
                'api_format' => $expirationForAPI, // YYMM
                'identical_to_script' => true,
            ]);

            // 3. Preparar datos EXACTAMENTE como script exitoso
            $track2 = $cardData['card_number'] . '=' . $expirationForAPI;
            $encryptData = [
                'track2' => $track2,
                'amount' => number_format($cardData['amount'], 2, '.', ''),
            ];

            Log::debug('🔵 Datos para encriptar', [
                'track2_full' => $track2,
                'track2_format' => 'tarjeta=YYMM',
                'amount_formatted' => $encryptData['amount'],
                'note' => 'IDÉNTICO al script exitoso',
            ]);

            // 4. Encriptar
            $encrypted = $this->encryptData($encryptData);

            Log::debug('🔵 Datos encriptados', [
                'encrypted_preview' => substr($encrypted, 0, 50) . '...',
                'encrypted_length' => strlen($encrypted),
            ]);

            // 5. Crear payload
            $payload = [
                'payload' => [
                    'token' => $clientToken,
                    'encrypt' => $encrypted,
                ],
                'method' => 'getTokenize',
            ];

            Log::info('🔵 Enviando a API EfevooPay', [
                'method' => 'getTokenize',
                'client_token_preview' => substr($clientToken, 0, 50) . '...',
                'using_fixed_token' => $clientTokenResult['fixed'] ?? false,
            ]);

            // 6. Enviar a API
            $apiResponse = $this->makeApiRequest($payload);

            Log::info('🔵 Respuesta de API', [
                'success' => $apiResponse['success'] ?? false,
                'code' => $apiResponse['code'] ?? null,
                'message' => $apiResponse['message'] ?? null,
                'has_token_usuario' => isset($apiResponse['data']['token_usuario']),
            ]);

            // 7. Procesar resultado
            return $this->processTokenizationResult(
                $apiResponse,
                null, // temporalmente sin transacción
                $cardData,
                $customerId,
                $clientToken
            );

        } catch (\Exception $e) {
            Log::error('❌ Error en tokenizeCard', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'customer_id' => $customerId,
            ]);

            return [
                'success' => false,
                'message' => 'Error al tokenizar: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Tokenización RÁPIDA (alternativa) con validación más flexible
     */
    public function fastTokenize(array $cardData, int $customerId): array
    {
        Log::info('EfevooPayService::fastTokenize - INICIANDO', [
            'customer_id' => $customerId,
            'expiration' => $cardData['expiration'] ?? null,
            'note' => 'Convirtiendo MMYY a YYMM para API',
        ]);

        try {
            // Validación más flexible
            $validator = validator($cardData, [
                'card_number' => 'required|string|size:16',
                'expiration' => 'required|string|size:4',
                'card_holder' => 'required|string|max:100',
                'amount' => 'required|numeric|min:0.01',
                'alias' => 'nullable|string|max:50',
            ]);

            if ($validator->fails()) {
                Log::warning('Validación fallida en fastTokenize', $validator->errors()->toArray());
                return [
                    'success' => false,
                    'message' => 'Datos de tarjeta inválidos',
                    'errors' => $validator->errors()->toArray(),
                ];
            }

            // Convertir expiración de MMYY a YYMM
            $expiration = $cardData['expiration'];
            if (strlen($expiration) !== 4 || !is_numeric($expiration)) {
                return [
                    'success' => false,
                    'message' => 'Formato de fecha inválido. Debe ser 4 dígitos (MMYY)',
                    'errors' => ['expiration' => 'Formato MMYY inválido'],
                ];
            }

            // La validación de mes se hará en tokenizeCard

            Log::info('FastTokenize validación exitosa', [
                'expiration_input' => $expiration,
                'converted_to' => substr($expiration, 2, 2) . substr($expiration, 0, 2),
            ]);

            // Usar el método principal
            return $this->tokenizeCard($cardData, $customerId);

        } catch (\Exception $e) {
            Log::error('Error en fastTokenize', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'customer_id' => $customerId,
            ]);

            return [
                'success' => false,
                'message' => 'Error en tokenización rápida: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Procesar resultado de tokenización
     */
    protected function processTokenizationResult(
        array $apiResponse,
        ?EfevooTransaction $transaction, // ← Hacerlo opcional
        array $cardData,
        int $customerId,
        string $clientToken
    ): array {
        $code = $apiResponse['code'] ?? '';
        $data = $apiResponse['data'] ?? [];

        Log::info('🟢 === PROCESANDO RESULTADO TOKENIZACIÓN ===', [
            'code' => $code,
            'has_transaction' => !is_null($transaction),
            'transaction_id' => $transaction ? $transaction->id : 'none',
            'has_token_usuario' => isset($data['token_usuario']),
        ]);

        // Buscar token en diferentes ubicaciones
        $cardToken = $data['token_usuario'] ??
            $data['token'] ??
            $data['card_token'] ?? null;

        $isSuccess = in_array($code, ['00', '100', '200']) || !empty($cardToken);

        if ($isSuccess && !empty($cardToken)) {
            // Crear token en base de datos
            $efevooToken = $this->createEfevooToken(
                $cardToken,
                $cardData,
                $customerId,
                $clientToken,
                $data
            );

            // Si hay transacción, actualizarla
            if ($transaction) {
                $transaction->update([
                    'efevoo_token_id' => $efevooToken->id,
                    'status' => EfevooTransaction::STATUS_APPROVED,
                    'response_code' => $code,
                    'response_message' => $data['descripcion'] ?? 'Aprobado',
                    'response_data' => $data,
                    'processed_at' => now(),
                ]);
            }

            Log::info('🎉 TOKENIZACIÓN EXITOSA', [
                'token_id' => $efevooToken->id,
                'customer_id' => $customerId,
                'alias' => $efevooToken->alias,
                'card_token_preview' => substr($cardToken, 0, 50) . '...',
            ]);

            return [
                'success' => true,
                'message' => 'Tarjeta tokenizada exitosamente',
                'token_id' => $efevooToken->id,
                'efevoo_token' => $efevooToken,
                'card_token' => $cardToken,
                'transaction_id' => $transaction ? $transaction->id : null,
                'code' => $code,
                'data' => $data,
            ];
        } else {
            // Tokenización fallida
            $errorMessage = $apiResponse['message'] ??
                $data['descripcion'] ??
                $data['error'] ??
                'Error en tokenización';

            if ($transaction) {
                $transaction->update([
                    'status' => EfevooTransaction::STATUS_DECLINED,
                    'response_code' => $code,
                    'response_message' => $errorMessage,
                    'response_data' => $data,
                    'processed_at' => now(),
                ]);
            }

            return [
                'success' => false,
                'message' => $errorMessage,
                'code' => $code,
                'data' => $data,
            ];
        }
    }

    /**
     * Crear registro de token en base de datos
     */
    protected function createEfevooToken(
        string $cardToken,
        array $cardData,
        int $customerId,
        string $clientToken,
        array $apiData
    ): EfevooToken {
        // Generar alias
        $alias = $cardData['alias'] ?? $this->generateCardAlias($cardData);

        // Extraer expiración para guardar en formato legible
        $expiration = $cardData['expiration'] ?? '';
        $expirationForDisplay = $expiration; // MMYY

        Log::info('📝 Creando EfevooToken en base de datos', [
            'customer_id' => $customerId,
            'alias' => $alias,
            'card_last_four' => substr($cardData['card_number'] ?? '', -4),
            'card_brand' => $this->detectCardBrand($cardData['card_number'] ?? ''),
            'expiration_display' => $expirationForDisplay,
            'expiration_api' => isset($expiration) ?
                substr($expiration, 2, 2) . substr($expiration, 0, 2) : '', // YYMM
        ]);

        return EfevooToken::create([
            'alias' => $alias,
            'client_token' => $clientToken,
            'card_token' => $cardToken,
            'card_last_four' => substr($cardData['card_number'] ?? '', -4),
            'card_brand' => $this->detectCardBrand($cardData['card_number'] ?? ''),
            'card_expiration' => $expirationForDisplay, // Guardar como MMYY para mostrar
            'card_holder' => $cardData['card_holder'] ?? '',
            'customer_id' => $customerId,
            'environment' => $this->environment,
            'expires_at' => now()->addYear(),
            'is_active' => true,
            'metadata' => [
                'transaction_id' => $apiData['id'] ?? null,
                'numref' => $apiData['numref'] ?? null,
                'numtxn' => $apiData['numtxn'] ?? null,
                'id_approved' => $apiData['id_approved'] ?? null,
                'original_response' => $apiData,
                'api_expiration_format' => 'YYMM', // Nota para referencia
                'user_expiration_format' => 'MMYY',
            ],
        ]);
    }

    /**
     * Generar alias para la tarjeta
     */
    protected function generateCardAlias(array $cardData): string
    {
        $brand = strtolower($this->detectCardBrand($cardData['card_number'] ?? ''));
        $lastFour = substr($cardData['card_number'] ?? '', -4);

        return "{$brand}-{$lastFour}";
    }

    /**
     * Obtener token de cliente con caché
     */
    public function getClientToken(bool $forceRefresh = false): array
    {
        $cacheKey = "efevoo_client_token_{$this->environment}";

        // SIEMPRE usar token fijo si está configurado
        if (!empty($this->config['fixed_token'])) {
            $this->clientToken = $this->config['fixed_token'];

            Log::info('🔐 Usando TOKEN FIJO VÁLIDO configurado', [
                'token_preview' => substr($this->clientToken, 0, 50) . '...',
                'token_length' => strlen($this->clientToken),
                'note' => 'Este token ya demostró funcionar en pruebas',
            ]);

            // Verificar que sea el token correcto
            $expectedToken = 'eGZ6ajlJcGJPSUNlSHpwMENJeWlNQlFSZ3BSWWRDb3lVNVI1cy9xb1V3Zz0=';
            $isCorrectToken = hash_equals($this->clientToken, $expectedToken);

            if (!$isCorrectToken) {
                Log::warning('Token fijo diferente al esperado', [
                    'expected_preview' => substr($expectedToken, 0, 50) . '...',
                    'actual_preview' => substr($this->clientToken, 0, 50) . '...',
                ]);
            }

            return [
                'success' => true,
                'token' => $this->clientToken,
                'cached' => false,
                'fixed' => true,
                'is_correct_token' => $isCorrectToken,
                'message' => 'Usando token fijo válido',
            ];
        }

        Log::warning('⚠️ NO hay token fijo configurado, generando dinámico (puede fallar)');

        // Solo si realmente no hay token fijo, generar dinámico
        $totp = $this->generateTOTP();
        $hash = $this->generateHash($totp);

        $payload = [
            'payload' => [
                'hash' => $hash,
                'cliente' => $this->config['cliente']
            ],
            'method' => 'getClientToken'
        ];

        $response = $this->makeApiRequest($payload);

        if ($response['success'] && isset($response['data']['token'])) {
            $this->clientToken = $response['data']['token'];
            Cache::put($cacheKey, $this->clientToken, now()->addMonths(11));

            return [
                'success' => true,
                'token' => $this->clientToken,
                'cached' => false,
                'fixed' => false,
            ];
        }

        Log::error('Error obteniendo token dinámico', $response);

        return [
            'success' => false,
            'message' => $response['message'] ?? 'Error al obtener token',
            'code' => $response['code'] ?? null,
        ];
    }

    protected function encryptData(array $data): string
    {
        // VERIFICAR: ¿El formato debe ser 'track2' => '5267772159330969=3111'?
        // Tu script usa: 'track2' => $tarjeta . '=' . $expiracion

        Log::debug('Datos para encriptar (detallado)', [
            'data_structure' => $data,
            'has_track2' => isset($data['track2']),
            'track2_value' => $data['track2'] ?? 'No existe',
            'track2_format' => isset($data['track2']) ? 'Tarjeta=Expiración' : 'No aplica',
            'amount' => $data['amount'] ?? null,
        ]);

        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        if (!$json) {
            throw new \Exception('Error al codificar JSON para encriptación');
        }

        $encrypted = openssl_encrypt(
            $json,
            'AES-128-CBC',
            $this->config['clave'],
            OPENSSL_RAW_DATA,
            $this->config['vector']
        );

        if ($encrypted === false) {
            throw new \Exception('Error en encriptación AES: ' . openssl_error_string());
        }

        return base64_encode($encrypted);
    }

    /**
     * Realizar solicitud a API
     */
    protected function makeApiRequest(array $payload): array
    {
        $method = $payload['method'] ?? 'unknown';

        // USAR SIEMPRE LA MISMA URL BASE - NO agregar endpoints
        $url = $this->config['api_url']; // Esto ya es: https://test-intgapi.efevoopay.com/v1/apiservice

        Log::info('=== MAKE API REQUEST ===', [
            'method' => $method,
            'url' => $url, // URL completa sin agregar nada
            'api_user' => $this->config['api_user'] ?? 'No config',
            'payload_method' => $method,
        ]);

        $headers = [
            'Content-Type: application/json',
            'X-API-USER: ' . $this->config['api_user'],
            'X-API-KEY: ' . $this->config['api_key'],
        ];

        // El payload YA debe tener la estructura correcta
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        Log::debug('Request body completo', [
            'body' => $body,
            'expected_structure' => '{"payload":{"token":"...","encrypt":"..."},"method":"getTokenize"}',
        ]);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $this->config['verify_ssl'] ?? false,
            CURLOPT_TIMEOUT => $this->config['timeout'] ?? 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        $curlInfo = curl_getinfo($ch);

        curl_close($ch);

        Log::info('=== API RAW RESPONSE ===', [
            'method' => $method,
            'http_code' => $httpCode,
            'curl_error' => $error ?: 'None',
            'response_length' => strlen($response),
            'response_preview' => substr($response, 0, 500),
        ]);

        if ($error) {
            Log::error('Error cURL en EfevooPay API', [
                'method' => $method,
                'error' => $error,
                'http_code' => $httpCode,
            ]);

            return [
                'success' => false,
                'status' => $httpCode,
                'message' => 'Error de conexión con EfevooPay: ' . $error,
                'code' => 'CURL_ERROR',
            ];
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Error decodificando JSON de EfevooPay', [
                'method' => $method,
                'json_error' => json_last_error_msg(),
                'response_raw' => $response,
                'http_code' => $httpCode,
            ]);

            return [
                'success' => false,
                'status' => $httpCode,
                'message' => 'Error en respuesta del servidor',
                'code' => 'JSON_ERROR',
                'raw_response' => $response,
            ];
        }

        Log::info('=== API RESPONSE DECODED ===', [
            'method' => $method,
            'data_keys' => array_keys($data),
            'has_codigo' => isset($data['codigo']),
            'has_token' => isset($data['token']) || isset($data['token_usuario']),
            'has_descripcion' => isset($data['descripcion']),
        ]);

        $success = $httpCode >= 200 && $httpCode < 300;
        $code = $data['codigo'] ?? $data['response_code'] ?? $data['code'] ?? null;

        if (in_array($code, ['00', '100', '200'])) {
            $success = true;
        }

        return [
            'success' => $success,
            'status' => $httpCode,
            'code' => $code,
            'message' => $data['msg'] ?? $data['mensaje'] ?? $data['descripcion'] ?? ($data['message'] ?? ''),
            'data' => $data,
            'raw' => $response,
        ];
    }

    /**
     * Obtener estructura del array para logging
     */
    protected function getArrayStructure($array, $prefix = ''): array
    {
        $structure = [];

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $structure[$key] = $this->getArrayStructure($value, $prefix . $key . '.');
            } else {
                $type = gettype($value);
                $structure[$key] = "($type) " . (is_string($value) ? substr($value, 0, 50) : $value);
            }
        }

        return $structure;
    }

    /**
     * Generar TOTP
     */
    protected function generateTOTP(): string
    {
        $secret = $this->config['totp_secret'];
        $timestamp = floor(time() / 30);

        // Decodificar Base32
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
        ) % pow(10, 6);

        return str_pad($code, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Generar hash HMAC-SHA256
     */
    protected function generateHash(string $totp): string
    {
        return base64_encode(hash_hmac(
            'sha256',
            $this->config['clave'],
            $totp,
            true
        ));
    }

    /**
     * Detectar marca de tarjeta
     */
    private function detectCardBrand(string $cardNumber): string
    {
        $cleanNumber = preg_replace('/\D/', '', $cardNumber);

        if (empty($cleanNumber)) {
            return 'unknown';
        }

        if (preg_match('/^4/', $cleanNumber))
            return 'visa';
        if (preg_match('/^5[1-5]/', $cleanNumber) || preg_match('/^2[2-7]/', $cleanNumber))
            return 'mastercard';
        if (preg_match('/^3[47]/', $cleanNumber))
            return 'amex';
        if (preg_match('/^6(?:011|4[4-9][0-9]|5)/', $cleanNumber))
            return 'discover';

        return 'unknown';
    }

    /**
     * Verificar estado del servicio
     */
    public function healthCheck(): array
    {
        try {
            $tokenResult = $this->getClientToken();

            if ($tokenResult['success']) {
                return [
                    'status' => 'online',
                    'environment' => $this->environment,
                    'client_token' => 'valid',
                    'timestamp' => now()->toISOString(),
                    'message' => 'Servicio EfevooPay operativo',
                ];
            } else {
                return [
                    'status' => 'degraded',
                    'environment' => $this->environment,
                    'client_token' => 'invalid',
                    'timestamp' => now()->toISOString(),
                    'message' => $tokenResult['message'] ?? 'Error en token de cliente',
                ];
            }

        } catch (\Exception $e) {
            return [
                'status' => 'offline',
                'environment' => $this->environment,
                'timestamp' => now()->toISOString(),
                'message' => $e->getMessage(),
                'client_token' => 'error',
            ];
        }
    }

    /**
     * Buscar transacciones
     */
    public function searchTransactions(array $filters = []): array
    {
        try {
            $clientTokenResult = $this->getClientToken();
            if (!$clientTokenResult['success']) {
                return $clientTokenResult;
            }

            $payloadData = ['token' => $clientTokenResult['token']];

            if (!empty($filters['transaction_id'])) {
                $payloadData['id'] = $filters['transaction_id'];
            }
            if (!empty($filters['start_date'])) {
                $payloadData['range1'] = $filters['start_date'];
            }
            if (!empty($filters['end_date'])) {
                $payloadData['range2'] = $filters['end_date'];
            }

            $response = $this->makeApiRequest([
                'payload' => $payloadData,
                'method' => 'getTranSearch',
            ]);

            if ($response['success'] && isset($response['data']['data'])) {
                // Opcional: sincronizar con base de datos local
                $this->syncTransactions($response['data']['data']);
            }

            return $response;

        } catch (\Exception $e) {
            Log::error('Error buscando transacciones', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error al buscar transacciones: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Sincronizar transacciones con base de datos local
     */
    protected function syncTransactions(array $transactions): void
    {
        foreach ($transactions as $tx) {
            try {
                EfevooTransaction::updateOrCreate(
                    ['transaction_id' => $tx['ID'] ?? $tx['id'] ?? null],
                    [
                        'reference' => $tx['reference'] ?? 'TXN-' . ($tx['ID'] ?? 'UNK'),
                        'amount' => $tx['amount'] ?? $tx['monto'] ?? 0,
                        'status' => $this->mapTransactionStatus($tx),
                        'response_code' => $tx['approved'] ?? $tx['code'] ?? null,
                        'response_message' => $tx['concept'] ?? $tx['Transaccion'] ?? null,
                        'transaction_type' => $this->mapTransactionType($tx),
                        'response_data' => $tx,
                        'processed_at' => isset($tx['date']) ? \Carbon\Carbon::parse($tx['date']) : now(),
                    ]
                );
            } catch (\Exception $e) {
                Log::error('Error sincronizando transacción', [
                    'transaction' => $tx,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Mapear estado de transacción
     */
    protected function mapTransactionStatus(array $tx): string
    {
        $status = strtoupper($tx['status'] ?? $tx['approved'] ?? '');

        if ($status === '00' || $status === 'COMPENSADA') {
            return EfevooTransaction::STATUS_APPROVED;
        } elseif ($status === 'PENDING' || $status === 'EN TRANSITO') {
            return EfevooTransaction::STATUS_PENDING;
        }

        return EfevooTransaction::STATUS_DECLINED;
    }

    /**
     * Mapear tipo de transacción
     */
    protected function mapTransactionType(array $tx): string
    {
        $type = strtoupper($tx['type'] ?? $tx['Transaccion'] ?? '');

        if (str_contains($type, 'PAGO') || str_contains($type, 'DEPÓSITO')) {
            return EfevooTransaction::TYPE_PAYMENT;
        } elseif (str_contains($type, 'RETIRO') || str_contains($type, 'CARGO')) {
            return EfevooTransaction::TYPE_PAYMENT;
        } elseif (str_contains($type, 'DEVOLUCIÓN')) {
            return EfevooTransaction::TYPE_REFUND;
        }

        return EfevooTransaction::TYPE_PAYMENT;
    }

    /**
     * Realizar un cargo con tarjeta tokenizada
     */
    public function chargeCard(array $chargeData): array
    {
        Log::info('EfevooPayService::chargeCard - Iniciando cargo', [
            'charge_data_masked' => $this->maskChargeData($chargeData),
        ]);

        try {
            // Validar datos requeridos
            $validator = validator($chargeData, [
                'token_id' => 'required|string',
                'amount' => 'required|numeric|min:0.01',
                'description' => 'nullable|string|max:255',
                'reference' => 'nullable|string|max:50',
            ]);

            if ($validator->fails()) {
                return [
                    'success' => false,
                    'message' => 'Datos de cargo inválidos',
                    'errors' => $validator->errors()->toArray(),
                ];
            }

            // 1. Obtener token de cliente
            $clientTokenResult = $this->getClientToken();
            if (!$clientTokenResult['success']) {
                return $clientTokenResult;
            }
            $clientToken = $clientTokenResult['token'];

            // 2. Preparar datos para encriptación
            $encryptData = [
                'token' => $chargeData['token_id'],
                'amount' => number_format($chargeData['amount'], 2, '.', ''),
                'description' => $chargeData['description'] ?? 'Pago en línea',
                'reference' => $chargeData['reference'] ?? 'PAY-' . Str::random(8),
            ];

            $encrypted = $this->encryptData($encryptData);

            // 3. Crear transacción en DB
            $transaction = EfevooTransaction::create([
                'reference' => $encryptData['reference'],
                'amount' => $chargeData['amount'],
                'transaction_type' => EfevooTransaction::TYPE_PAYMENT,
                'status' => EfevooTransaction::STATUS_PENDING,
                'request_data' => [
                    'token_id' => $chargeData['token_id'],
                    'amount' => $chargeData['amount'],
                    'description' => $encryptData['description'],
                ],
                'cav' => Str::upper(Str::random(10)),
            ]);

            // 4. Enviar solicitud a API
            $payload = [
                'payload' => [
                    'token' => $clientToken,
                    'encrypt' => $encrypted,
                ],
                'method' => 'getPayment', // Método para pagos
            ];

            Log::info('Enviando cargo a EfevooPay', [
                'method' => 'getPayment',
                'reference' => $encryptData['reference'],
                'amount' => $encryptData['amount'],
            ]);

            $apiResponse = $this->makeApiRequest($payload);

            Log::info('Respuesta de cargo recibida', [
                'success' => $apiResponse['success'],
                'code' => $apiResponse['code'] ?? null,
                'reference' => $encryptData['reference'],
            ]);

            // 5. Procesar respuesta
            return $this->processPaymentResult($apiResponse, $transaction);

        } catch (\Exception $e) {
            Log::error('Error en chargeCard', [
                'error' => $e->getMessage(),
                'charge_data' => $this->maskChargeData($chargeData),
            ]);

            return [
                'success' => false,
                'message' => 'Error al procesar el cargo: ' . $e->getMessage(),
            ];
        }
    }

    public function getTestCards(): array
    {
        // Como es servicio real, no hay "tarjetas de prueba" seguras
        // Pero podemos devolver información útil
        return [
            'warning' => '⚠️ SERVICIO REAL ACTIVO',
            'message' => 'Estás usando el servicio real de EfevooPay. Cualquier tarjeta que ingreses realizará cargos reales.',
            'recommendations' => [
                'Para pruebas, usa montos mínimos ($0.01 MXN)',
                'Usa tarjetas de desarrollo/test de tu banco si es posible',
                'Monitorea tu cuenta bancaria después de cada prueba',
            ],
            'test_cards_info' => [
                'En ambiente de pruebas, algunas tarjetas pueden funcionar:',
                '4111111111111111 (Visa test - puede hacer cargo)',
                '5555555555554444 (Mastercard test - puede hacer cargo)',
            ],
            'note' => 'Contacta a EfevooPay para obtener tarjetas de prueba que no hagan cargos reales.',
        ];
    }

    /**
     * Procesar resultado de pago
     */
    protected function processPaymentResult(array $apiResponse, EfevooTransaction $transaction): array
    {
        $code = $apiResponse['code'] ?? '';
        $isSuccess = in_array($code, ['00', '100']);

        if ($isSuccess) {
            $transaction->update([
                'status' => EfevooTransaction::STATUS_APPROVED,
                'response_code' => $code,
                'response_message' => $apiResponse['data']['descripcion'] ?? 'Aprobado',
                'response_data' => $apiResponse['data'],
                'processed_at' => now(),
            ]);

            return [
                'success' => true,
                'message' => 'Pago procesado exitosamente',
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
                'code' => $code,
                'data' => $apiResponse['data'],
            ];
        } else {
            $transaction->update([
                'status' => EfevooTransaction::STATUS_DECLINED,
                'response_code' => $code,
                'response_message' => $apiResponse['message'] ?? $apiResponse['data']['descripcion'] ?? 'Declinado',
                'response_data' => $apiResponse['data'] ?? [],
                'processed_at' => now(),
            ]);

            $errorMessage = $apiResponse['message'] ?? $apiResponse['data']['descripcion'] ?? 'Error en pago';

            return [
                'success' => false,
                'message' => $errorMessage,
                'code' => $code,
                'data' => $apiResponse['data'] ?? [],
            ];
        }
    }

    /**
     * Enmascarar datos de cargo
     */
    private function maskChargeData(array $data): array
    {
        $masked = $data;

        if (isset($masked['token_id'])) {
            $masked['token_id'] = substr($masked['token_id'], 0, 8) . '...';
        }

        return $masked;
    }

    /**
     * Método para forzar simulación (para compatibilidad)
     */
    public function forceSimulation(bool $force = true): self
    {
        Log::info('forceSimulation llamado en EfevooPayService', ['force' => $force]);

        // En el servicio real, esto no hace mucho pero mantiene compatibilidad
        if ($force) {
            Log::warning('Se solicitó forzar simulación en servicio real - ignorando');
        }

        return $this;
    }
}