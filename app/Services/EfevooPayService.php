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
        Log::info('Iniciando tokenización de tarjeta', [
            'customer_id' => $customerId,
            'has_card_number' => isset($cardData['card_number']),
            'has_expiration' => isset($cardData['expiration']),
            'has_amount' => isset($cardData['amount']),
            'has_alias' => isset($cardData['alias']),
        ]);

        try {
            // Validación estricta - ¡CORREGIDO EL REGEX!
            $validator = validator($cardData, [
                'card_number' => 'required|string|size:16|regex:/^[0-9]+$/',
                'expiration' => 'required|string|size:4|regex:/^(0[1-9]|1[0-2])([0-9]{2})$/',
                'card_holder' => 'required|string|max:100',
                'amount' => 'required|numeric|min:0.01|max:300',
                'alias' => 'nullable|string|max:50',
            ]);

            if ($validator->fails()) {
                Log::warning('Validación fallida en tokenización', [
                    'errors' => $validator->errors()->toArray(),
                    'expiration_received' => $cardData['expiration'] ?? null,
                ]);
                
                return [
                    'success' => false,
                    'message' => 'Datos de tarjeta inválidos',
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
            $track2 = $cardData['card_number'] . '=' . $cardData['expiration'];
            $encryptData = [
                'track2' => $track2,
                'amount' => number_format($cardData['amount'], 2, '.', ''),
            ];

            Log::debug('Datos para encriptar', [
                'track2_preview' => substr($track2, 0, 10) . '...',
                'amount' => $encryptData['amount'],
            ]);

            $encrypted = $this->encryptData($encryptData);

            // 3. Crear transacción en DB
            $transaction = EfevooTransaction::create([
                'reference' => 'TOK-' . Str::random(10),
                'amount' => $cardData['amount'],
                'transaction_type' => EfevooTransaction::TYPE_TOKENIZATION,
                'status' => EfevooTransaction::STATUS_PENDING,
                'request_data' => [
                    'card_last_four' => substr($cardData['card_number'], -4),
                    'expiration' => $cardData['expiration'],
                    'card_holder' => $cardData['card_holder'],
                    'amount' => $cardData['amount'],
                    'alias' => $cardData['alias'] ?? null,
                ],
                'cav' => Str::upper(Str::random(10)),
            ]);

            // 4. Enviar solicitud a API
            $payload = [
                'payload' => [
                    'token' => $clientToken,
                    'encrypt' => $encrypted,
                ],
                'method' => 'getTokenize',
            ];

            Log::info('Enviando tokenización a EfevooPay', [
                'method' => 'getTokenize',
                'client_token_preview' => substr($clientToken, 0, 10) . '...',
            ]);

            $apiResponse = $this->makeApiRequest($payload);

            Log::info('Respuesta de tokenización recibida', [
                'success' => $apiResponse['success'],
                'code' => $apiResponse['code'] ?? null,
                'has_data' => isset($apiResponse['data']),
            ]);

            // 5. Procesar respuesta
            return $this->processTokenizationResult(
                $apiResponse, 
                $transaction, 
                $cardData, 
                $customerId, 
                $clientToken
            );

        } catch (\Exception $e) {
            Log::error('Error en tokenizeCard', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'customer_id' => $customerId,
                'card_data' => $cardData,
            ]);

            return [
                'success' => false,
                'message' => 'Error al tokenizar la tarjeta: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Tokenización RÁPIDA (alternativa) con validación más flexible
     */
    public function fastTokenize(array $cardData, int $customerId): array
    {
        Log::info('Iniciando fastTokenize', [
            'customer_id' => $customerId,
            'card_data_keys' => array_keys($cardData),
            'expiration' => $cardData['expiration'] ?? null,
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

            // Validar formato de fecha manualmente (MMYY)
            $expiration = $cardData['expiration'];
            if (!preg_match('/^(0[1-9]|1[0-2])([0-9]{2})$/', $expiration)) {
                Log::warning('Formato de expiración inválido', ['expiration' => $expiration]);
                return [
                    'success' => false,
                    'message' => 'Formato de fecha inválido. Debe ser MMYY (ej: 0528 para Mayo 2028)',
                    'errors' => ['expiration' => 'Formato MMYY inválido'],
                ];
            }

            // Usar el método principal
            return $this->tokenizeCard($cardData, $customerId);

        } catch (\Exception $e) {
            Log::error('Error en fastTokenize', [
                'error' => $e->getMessage(),
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
        EfevooTransaction $transaction,
        array $cardData,
        int $customerId,
        string $clientToken
    ): array {
        $code = $apiResponse['code'] ?? '';
        $isSuccess = in_array($code, ['00', '100']);
        
        Log::info('Procesando resultado de tokenización', [
            'code' => $code,
            'is_success' => $isSuccess,
            'has_token' => isset($apiResponse['data']['token_usuario']) || isset($apiResponse['data']['token']),
        ]);

        if ($isSuccess) {
            // Extraer token de la respuesta
            $cardToken = $apiResponse['data']['token_usuario'] ?? 
                        $apiResponse['data']['token'] ?? 
                        $apiResponse['data']['card_token'] ?? null;

            if (!$cardToken) {
                Log::error('Tokenización exitosa pero sin token', [
                    'data_keys' => array_keys($apiResponse['data']),
                ]);

                $transaction->update([
                    'status' => EfevooTransaction::STATUS_ERROR,
                    'response_message' => 'Tokenización exitosa pero no se recibió token',
                    'response_data' => $apiResponse['data'],
                    'processed_at' => now(),
                ]);

                return [
                    'success' => false,
                    'message' => 'Tokenización exitosa pero no se recibió token',
                ];
            }

            // Crear token en base de datos
            $efevooToken = $this->createEfevooToken(
                $cardToken,
                $cardData,
                $customerId,
                $clientToken,
                $apiResponse['data']
            );

            // Actualizar transacción
            $transaction->update([
                'efevoo_token_id' => $efevooToken->id,
                'status' => EfevooTransaction::STATUS_APPROVED,
                'response_code' => $code,
                'response_message' => $apiResponse['data']['descripcion'] ?? 'Aprobado',
                'response_data' => $apiResponse['data'],
                'processed_at' => now(),
            ]);

            Log::info('Tokenización completada exitosamente', [
                'token_id' => $efevooToken->id,
                'customer_id' => $customerId,
                'alias' => $efevooToken->alias,
            ]);

            return [
                'success' => true,
                'message' => 'Tarjeta tokenizada exitosamente',
                'token_id' => $efevooToken->id,
                'efevoo_token' => $efevooToken,
                'card_token' => $cardToken,
                'transaction_id' => $transaction->id,
                'code' => $code,
                'data' => $apiResponse['data'],
            ];
        } else {
            // Tokenización fallida
            $transaction->update([
                'status' => EfevooTransaction::STATUS_DECLINED,
                'response_code' => $code,
                'response_message' => $apiResponse['message'] ?? $apiResponse['data']['descripcion'] ?? 'Declinado',
                'response_data' => $apiResponse['data'] ?? [],
                'processed_at' => now(),
            ]);

            $errorMessage = $apiResponse['message'] ?? $apiResponse['data']['descripcion'] ?? 'Error en tokenización';
            
            // Mensajes específicos según código
            if ($code === '05') {
                $errorMessage = 'Tarjeta rechazada por el banco (No honrar)';
            } elseif ($code === '30') {
                $errorMessage = 'Error de formato en los datos';
            } elseif ($code === '51') {
                $errorMessage = 'Fondos insuficientes';
            } elseif ($code === '54') {
                $errorMessage = 'Tarjeta vencida';
            }

            return [
                'success' => false,
                'message' => $errorMessage,
                'code' => $code,
                'data' => $apiResponse['data'] ?? [],
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
        
        Log::info('Creando EfevooToken', [
            'customer_id' => $customerId,
            'alias' => $alias,
            'card_last_four' => substr($cardData['card_number'] ?? '', -4),
            'card_brand' => $this->detectCardBrand($cardData['card_number'] ?? ''),
            'expiration' => $cardData['expiration'] ?? '',
        ]);

        return EfevooToken::create([
            'alias' => $alias,
            'client_token' => $clientToken,
            'card_token' => $cardToken,
            'card_last_four' => substr($cardData['card_number'] ?? '', -4),
            'card_brand' => $this->detectCardBrand($cardData['card_number'] ?? ''),
            'card_expiration' => $cardData['expiration'] ?? '',
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

        // Usar token fijo si está configurado
        if (!empty($this->config['fixed_token'])) {
            $this->clientToken = $this->config['fixed_token'];
            return [
                'success' => true,
                'token' => $this->clientToken,
                'cached' => false,
                'fixed' => true,
            ];
        }

        // Verificar caché
        if (!$forceRefresh && Cache::has($cacheKey)) {
            $cachedToken = Cache::get($cacheKey);
            $this->clientToken = $cachedToken;
            
            Log::debug('Token de cliente obtenido de caché', [
                'token_preview' => substr($this->clientToken, 0, 10) . '...',
            ]);
            
            return [
                'success' => true,
                'token' => $this->clientToken,
                'cached' => true,
            ];
        }

        // Generar nuevo token
        $totp = $this->generateTOTP();
        $hash = $this->generateHash($totp);

        Log::debug('Generando nuevo token de cliente', [
            'totp' => $totp,
            'hash_preview' => substr($hash, 0, 10) . '...',
            'cliente' => $this->config['cliente'],
        ]);

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
            
            // Cache por 11 meses
            Cache::put($cacheKey, $this->clientToken, now()->addMonths(11));
            
            Log::info('Nuevo token de cliente generado', [
                'token_preview' => substr($this->clientToken, 0, 10) . '...',
                'duracion' => $response['data']['duracion'] ?? '1 año',
            ]);
            
            return [
                'success' => true,
                'token' => $this->clientToken,
                'duracion' => $response['data']['duracion'] ?? '1 año',
                'cached' => false,
            ];
        }

        Log::error('Error obteniendo token de cliente', [
            'success' => $response['success'] ?? false,
            'code' => $response['code'] ?? null,
            'message' => $response['message'] ?? null,
        ]);

        return [
            'success' => false,
            'message' => $response['message'] ?? 'Error al obtener token de cliente',
            'code' => $response['code'] ?? null,
        ];
    }

    /**
     * Encriptar datos con AES-128-CBC
     */
    protected function encryptData(array $data): string
    {
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
        
        Log::info('Enviando solicitud a EfevooPay API', [
            'method' => $method,
            'url' => $this->config['api_url'],
        ]);

        $headers = [
            'Content-Type: application/json',
            'X-API-USER: ' . $this->config['api_user'],
            'X-API-KEY: ' . $this->config['api_key'],
        ];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->config['api_url'],
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
        curl_close($ch);

        // Manejar errores de conexión
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

        // Decodificar respuesta JSON
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Error decodificando JSON de EfevooPay', [
                'method' => $method,
                'json_error' => json_last_error_msg(),
                'response_preview' => substr($response, 0, 200),
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

        // Determinar éxito basado en código HTTP y código de respuesta
        $success = $httpCode >= 200 && $httpCode < 300;
        $code = $data['codigo'] ?? ($data['response_code'] ?? null);
        
        // Códigos específicos de éxito
        if (in_array($code, ['00', '100'])) {
            $success = true;
        }

        Log::debug('Respuesta de EfevooPay API', [
            'method' => $method,
            'http_code' => $httpCode,
            'code' => $code,
            'success' => $success,
            'has_descripcion' => isset($data['descripcion']),
            'has_token' => isset($data['token']) || isset($data['token_usuario']),
        ]);

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
            if (!isset($base32Lookup[$ch])) continue;
            
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
    protected function detectCardBrand(string $cardNumber): string
    {
        $cardNumber = preg_replace('/\D/', '', $cardNumber);
        
        if (preg_match('/^4/', $cardNumber)) return 'Visa';
        if (preg_match('/^5[1-5]/', $cardNumber)) return 'MasterCard';
        if (preg_match('/^3[47]/', $cardNumber)) return 'American Express';
        if (preg_match('/^3(?:0[0-5]|[68])/', $cardNumber)) return 'Diners Club';
        if (preg_match('/^6(?:011|5)/', $cardNumber)) return 'Discover';
        if (preg_match('/^(?:2131|1800|35)/', $cardNumber)) return 'JCB';
        
        return 'Unknown';
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
}