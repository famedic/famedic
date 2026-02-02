<?php
// app/Services/EfevooPayService.php

namespace App\Services;

use App\Models\EfevooToken;
use App\Models\EfevooTransaction;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EfevooPayService
{
    protected $client;
    protected $config;
    protected $environment;
    protected $clientToken;

    public function __construct()
    {
        $this->environment = config('efevoopay.environment', 'test');

        // Usar configuración única
        $this->config = config('efevoopay', []);

        // Validar configuración crítica
        $requiredConfig = ['api_url', 'cliente', 'clave', 'vector', 'totp_secret'];
        foreach ($requiredConfig as $key) {
            if (empty($this->config[$key])) {
                throw new \RuntimeException("Configuración de EfevooPay incompleta: {$key} no está configurado");
            }
        }

        $this->client = new Client([
            'timeout' => $this->config['timeout'] ?? 30,
            'verify' => $this->config['verify_ssl'] ?? false,
            'headers' => [
                'User-Agent' => 'Famedic-Laravel/1.0',
            ],
        ]);
    }

    /**
     * Genera código TOTP
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
     * Genera hash HMAC-SHA256
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
     * Encripta datos con AES-128-CBC
     */
    protected function encryptData(array $data): string
    {
        $plaintext = json_encode($data, JSON_UNESCAPED_UNICODE);

        return base64_encode(openssl_encrypt(
            $plaintext,
            'AES-128-CBC',
            $this->config['clave'],
            OPENSSL_RAW_DATA,
            $this->config['vector']
        ));
    }

    /**
     * Tokeniza una tarjeta
     */
    public function tokenizeCard(array $cardData, int $userId): array
    {
        // Validar datos según el formato que funciona
        $validator = validator($cardData, [
            'card_number' => 'required|string|size:16',
            'expiration' => 'required|string|size:4', // MMYY
            'card_holder' => 'required|string|max:100',
            'amount' => 'required|numeric|min:1.50|max:300', // Mínimo $1.50 según pruebas
        ]);

        if ($validator->fails()) {
            return [
                'success' => false,
                'message' => 'Datos de tarjeta inválidos',
                'errors' => $validator->errors()->toArray(),
            ];
        }

        // Obtener token de cliente
        $tokenResult = $this->getClientToken();
        if (!$tokenResult['success']) {
            return $tokenResult;
        }

        // Preparar datos para encriptar - FORMATO CORRECTO: tarjeta=expiracion
        $track2 = $cardData['card_number'] . '=' . $cardData['expiration'];

        Log::info('Tokenizando tarjeta - Datos preparados', [
            'last_four' => substr($cardData['card_number'], -4),
            'expiration' => $cardData['expiration'],
            'track2_format' => substr($track2, 0, 10) . '...' . substr($track2, -4),
            'amount' => $cardData['amount'],
        ]);

        $encryptData = [
            'track2' => $track2,
            'amount' => number_format($cardData['amount'], 2, '.', ''),
        ];

        $encrypted = $this->encryptData($encryptData);

        $payload = [
            'payload' => [
                'token' => $this->clientToken,
                'encrypt' => $encrypted,
            ],
            'method' => 'getTokenize',
        ];

        // Crear transacción en base de datos
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
            ],
            'cav' => Str::upper(Str::random(10)),
        ]);

        try {
            Log::info('Enviando solicitud de tokenización a EfevooPay', [
                'url' => $this->config['api_url'],
                'method' => 'getTokenize',
                'payload_keys' => array_keys($payload['payload']),
            ]);

            $result = $this->makeRequestDirect($payload);

            Log::info('Respuesta de tokenización recibida', [
                'success' => $result['success'],
                'codigo' => $result['codigo'],
                'message' => $result['message'],
                'has_token' => isset($result['data']['token']),
            ]);

            // Actualizar transacción
            $updateData = [
                'response_code' => $result['codigo'] ?? null,
                'response_message' => $result['message'] ?? null,
                'response_data' => $result['data'] ?? [],
                'processed_at' => now(),
            ];

            if ($result['success'] && ($result['codigo'] === '00' || $result['codigo'] === '100')) {
                // El token puede venir en diferentes campos
                $cardToken = $result['data']['token_usuario'] ??
                    $result['data']['token'] ??
                    $result['data']['card_token'] ?? null;

                if (!$cardToken) {
                    Log::error('Tokenización exitosa pero sin token en respuesta', [
                        'data_keys' => array_keys($result['data']),
                        'response' => $result['data'],
                    ]);

                    $transaction->update([
                        'status' => EfevooTransaction::STATUS_ERROR,
                        'response_message' => 'Tokenización exitosa pero no se recibió token',
                        'processed_at' => now(),
                    ]);

                    return [
                        'success' => false,
                        'message' => 'Tokenización exitosa pero no se recibió token',
                        'data' => $result['data'],
                    ];
                }

                $this->clientToken = $cardToken; // Actualizar token de cliente también

                // Guardar token de tarjeta
                $efevooToken = EfevooToken::create([
                    'alias' => strtolower($this->detectCardBrand($cardData['card_number'])) . '-' . substr($cardData['card_number'], -4),
                    'client_token' => $this->clientToken,
                    'card_token' => $cardToken, // ← ¡CORREGIDO!
                    'card_last_four' => substr($cardData['card_number'], -4),
                    'card_brand' => $this->detectCardBrand($cardData['card_number']),
                    'card_expiration' => $cardData['expiration'],
                    'card_holder' => $cardData['card_holder'],
                    'customer_id' => $userId,
                    'environment' => $this->environment,
                    'expires_at' => now()->addYear(),
                    'is_active' => true,
                    'metadata' => [
                        'transaction_id' => $result['data']['id'] ?? null,
                        'numref' => $result['data']['numref'] ?? null,
                        'numtxn' => $result['data']['numtxn'] ?? null,
                        'response_data' => $result['data'],
                    ],
                ]);

                $transaction->update([
                    'efevoo_token_id' => $efevooToken->id,
                    'status' => EfevooTransaction::STATUS_APPROVED,
                    'response_code' => $result['codigo'],
                    'response_message' => $result['message'] ?? $result['data']['descripcion'] ?? 'Aprobado',
                    'response_data' => $result['data'],
                    'processed_at' => now(),
                ]);

                return [
                    'success' => true,
                    'message' => 'Tarjeta tokenizada exitosamente',
                    'token_id' => $efevooToken->id,
                    'efevoo_token_id' => $efevooToken->id,
                    'efevoo_token' => $efevooToken,
                    'card_token' => $cardToken,
                    'transaction' => $transaction,
                    'transaction_id' => $transaction->id,
                    'codigo' => $result['codigo'],
                    'data' => $result['data'],
                ];
            } else {
                $updateData['status'] = EfevooTransaction::STATUS_DECLINED;
                $transaction->update($updateData);

                return $result;
            }

        } catch (\Exception $e) {
            Log::error('Error al tokenizar tarjeta', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $transaction->update([
                'status' => EfevooTransaction::STATUS_ERROR,
                'response_message' => $e->getMessage(),
                'processed_at' => now(),
            ]);

            return [
                'success' => false,
                'message' => 'Error al tokenizar tarjeta',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Procesa un pago
     */
    public function processPayment(array $paymentData, ?int $tokenId = null): array
    {
        // Validar datos
        $validator = validator($paymentData, [
            'amount' => 'required|numeric|min:0.01',
            'cav' => 'required|string|min:8|max:20',
            'cvv' => 'required_if:use_token,false|string|size:3',
            'msi' => 'integer|in:0,3,6,9,12,18',
            'contrato' => 'nullable|string|min:5|max:16',
            'fiid_comercio' => 'nullable|string',
            'referencia' => 'required|string|max:50',
            'description' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return [
                'success' => false,
                'message' => 'Datos de pago inválidos',
                'errors' => $validator->errors()->toArray(),
            ];
        }

        // Obtener token de cliente
        $tokenResult = $this->getClientToken();
        if (!$tokenResult['success']) {
            return $tokenResult;
        }

        // Si se usa token de tarjeta
        $track2 = '';
        $cvv = '';

        if ($tokenId) {
            $token = EfevooToken::active()->find($tokenId);
            if (!$token) {
                return [
                    'success' => false,
                    'message' => 'Token de tarjeta no válido o expirado',
                ];
            }

            // IMPORTANTE: Cuando se usa token, el track2 es solo el token
            $track2 = $token->card_token;
            $cvv = ''; // Cuando se usa token, CVV va vacío

            Log::info('Procesando pago con token', [
                'token_id' => $tokenId,
                'card_token_preview' => substr($track2, 0, 20) . '...',
                'last_four' => $token->card_last_four,
            ]);
        } else {
            // Validar datos de tarjeta directa
            $cardValidator = validator($paymentData, [
                'card_number' => 'required|string|size:16',
                'expiration' => 'required|string|size:4',
                'card_holder' => 'required|string|max:100',
            ]);

            if ($cardValidator->fails()) {
                return [
                    'success' => false,
                    'message' => 'Datos de tarjeta inválidos',
                    'errors' => $cardValidator->errors()->toArray(),
                ];
            }

            // Para tarjeta directa: tarjeta=expiracion
            $track2 = $paymentData['card_number'] . '=' . $paymentData['expiration'];
            $cvv = $paymentData['cvv'];
        }

        // Preparar datos para encriptar
        $encryptData = [
            'track2' => $track2,
            'amount' => number_format($paymentData['amount'], 2, '.', ''),
            'cvv' => $cvv,
            'cav' => $paymentData['cav'],
            'msi' => $paymentData['msi'] ?? 0,
            'contrato' => $paymentData['contrato'] ?? '',
            'fiid_comercio' => $paymentData['fiid_comercio'] ?? '',
            'referencia' => $paymentData['referencia'],
        ];

        Log::info('Datos para encriptar en pago', [
            'track2_preview' => substr($track2, 0, 20) . '...',
            'amount' => $encryptData['amount'],
            'has_cvv' => !empty($cvv),
            'cav' => $paymentData['cav'],
        ]);

        $encrypted = $this->encryptData($encryptData);

        $payload = [
            'payload' => [
                'token' => $this->clientToken,
                'encrypt' => $encrypted,
            ],
            'method' => 'getPayment',
        ];

        // Crear transacción
        $transaction = EfevooTransaction::create([
            'efevoo_token_id' => $tokenId,
            'reference' => $paymentData['referencia'],
            'amount' => $paymentData['amount'],
            'transaction_type' => EfevooTransaction::TYPE_PAYMENT,
            'status' => EfevooTransaction::STATUS_PENDING,
            'request_data' => [
                'cav' => $paymentData['cav'],
                'msi' => $paymentData['msi'] ?? 0,
                'description' => $paymentData['description'],
                'use_token' => (bool) $tokenId,
            ],
            'cav' => $paymentData['cav'],
            'msi' => $paymentData['msi'] ?? 0,
            'fiid_comercio' => $paymentData['fiid_comercio'] ?? null,
        ]);

        try {
            Log::info('Enviando solicitud de pago a EfevooPay', [
                'url' => $this->config['api_url'],
                'method' => 'getPayment',
                'reference' => $paymentData['referencia'],
            ]);

            $result = $this->makeRequest($payload);

            Log::info('Respuesta de pago recibida', [
                'success' => $result['success'],
                'codigo' => $result['codigo'],
                'message' => $result['message'],
                'transaction_id' => $result['data']['id'] ?? null,
            ]);

            // Actualizar transacción
            $updateData = [
                'transaction_id' => $result['data']['id'] ?? null,
                'response_code' => $result['codigo'] ?? null,
                'response_message' => $result['message'] ?? $result['descripcion'] ?? null,
                'response_data' => $result['data'] ?? [],
                'processed_at' => now(),
            ];

            if ($result['success']) {
                $updateData['status'] = EfevooTransaction::STATUS_APPROVED;
            } else {
                $updateData['status'] = in_array($result['codigo'] ?? '', ['00', '100']) ?
                    EfevooTransaction::STATUS_APPROVED :
                    EfevooTransaction::STATUS_DECLINED;
            }

            $transaction->update($updateData);

            return array_merge($result, [
                'transaction_id' => $transaction->id,
                'reference' => $paymentData['referencia'],
                'efevoo_transaction_id' => $result['data']['id'] ?? null,
            ]);

        } catch (\Exception $e) {
            Log::error('Error al procesar pago', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $transaction->update([
                'status' => EfevooTransaction::STATUS_ERROR,
                'response_message' => $e->getMessage(),
                'processed_at' => now(),
            ]);

            return [
                'success' => false,
                'message' => 'Error al procesar pago',
                'error' => $e->getMessage(),
                'transaction_id' => $transaction->id,
            ];
        }
    }

    /**
     * Realiza un reembolso
     */
    public function refundTransaction(int $transactionId): array
    {
        $transaction = EfevooTransaction::find($transactionId);

        if (!$transaction) {
            return [
                'success' => false,
                'message' => 'Transacción no encontrada',
            ];
        }

        if (!$transaction->canBeRefunded()) {
            return [
                'success' => false,
                'message' => 'Esta transacción no puede ser reembolsada',
            ];
        }

        // Obtener token de cliente
        $tokenResult = $this->getClientToken();
        if (!$tokenResult['success']) {
            return $tokenResult;
        }

        $payload = [
            'payload' => [
                'token' => $this->clientToken,
                'id' => $transaction->transaction_id,
            ],
            'method' => 'getRefund',
        ];

        // Crear transacción de reembolso
        $refundTransaction = EfevooTransaction::create([
            'efevoo_token_id' => $transaction->efevoo_token_id,
            'reference' => 'REF-' . $transaction->reference,
            'amount' => $transaction->amount,
            'transaction_type' => EfevooTransaction::TYPE_REFUND,
            'status' => EfevooTransaction::STATUS_PENDING,
            'metadata' => [
                'original_transaction_id' => $transaction->id,
                'original_reference' => $transaction->reference,
            ],
        ]);

        try {
            $result = $this->makeRequest($payload);

            $updateData = [
                'transaction_id' => $result['data']['id'] ?? null,
                'response_code' => $result['codigo'] ?? null,
                'response_message' => $result['message'] ?? null,
                'response_data' => $result['data'] ?? [],
                'processed_at' => now(),
            ];

            if ($result['success']) {
                $updateData['status'] = EfevooTransaction::STATUS_REFUNDED;
                // Marcar transacción original como reembolsada
                $transaction->update(['status' => EfevooTransaction::STATUS_REFUNDED]);
            } else {
                $updateData['status'] = EfevooTransaction::STATUS_DECLINED;
            }

            $refundTransaction->update($updateData);

            return array_merge($result, [
                'refund_id' => $refundTransaction->id,
                'original_transaction_id' => $transaction->id,
            ]);

        } catch (\Exception $e) {
            $refundTransaction->update([
                'status' => EfevooTransaction::STATUS_ERROR,
                'response_message' => $e->getMessage(),
                'processed_at' => now(),
            ]);

            return [
                'success' => false,
                'message' => 'Error al procesar reembolso',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Busca transacciones
     */
    public function searchTransactions(array $filters = []): array
    {
        // Obtener token de cliente
        $tokenResult = $this->getClientToken();
        if (!$tokenResult['success']) {
            return $tokenResult;
        }

        $payloadData = [
            'token' => $this->clientToken,
        ];

        if (!empty($filters['transaction_id'])) {
            $payloadData['id'] = $filters['transaction_id'];
        }

        if (!empty($filters['start_date'])) {
            $payloadData['range1'] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $payloadData['range2'] = $filters['end_date'];
        }

        $payload = [
            'payload' => $payloadData,
            'method' => 'getTranSearch',
        ];

        $result = $this->makeRequest($payload);

        if ($result['success'] && isset($result['data']['data'])) {
            // Sincronizar con base de datos local
            $this->syncTransactions($result['data']['data']);
        }

        return $result;
    }

    /**
     * Realiza una solicitud a la API
     */
    protected function makeRequest(array $payload): array
    {
        $headers = [
            'Content-Type: application/json',
            'X-API-USER: ' . $this->config['api_user'],
            'X-API-KEY: ' . $this->config['api_key'],
        ];

        $apiUrl = $this->config['api_url'];
        $method = $payload['method'] ?? 'unknown';

        // Convertir payload a JSON string exactamente como tu script
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        Log::info('EfevooPay Request', [
            'url' => $apiUrl,
            'method' => $method,
            'headers' => $headers,
            'body_preview' => substr($body, 0, 200),
        ]);

        try {
            // Usar curl directamente como tu script
            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => $apiUrl,
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

            if ($error) {
                Log::error('EfevooPay cURL Error', [
                    'error' => $error,
                    'method' => $method,
                ]);

                return [
                    'success' => false,
                    'status' => 0,
                    'error' => $error,
                    'message' => 'Error de conexión con EfevooPay',
                    'codigo' => 'CURL_ERROR',
                ];
            }

            Log::info('EfevooPay Response', [
                'status' => $httpCode,
                'response_preview' => substr($response, 0, 200),
            ]);

            $data = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('JSON decode error', [
                    'error' => json_last_error_msg(),
                    'response' => $response,
                    'method' => $method,
                ]);

                return [
                    'success' => false,
                    'status' => $httpCode,
                    'message' => 'Error en la respuesta del servidor',
                    'data' => ['raw' => $response],
                    'codigo' => 'JSON_ERROR',
                ];
            }

            $success = $httpCode >= 200 && $httpCode < 300;
            $code = $data['codigo'] ?? ($data['response_code'] ?? null);

            // Considerar éxito si código es 00 o 100
            if (in_array($code, ['00', '100'])) {
                $success = true;
            }

            return [
                'success' => $success,
                'status' => $httpCode,
                'codigo' => $code,
                'message' => $data['msg'] ?? $data['mensaje'] ?? $data['descripcion'] ?? ($data['message'] ?? ''),
                'data' => $data,
                'raw' => $response,
            ];

        } catch (\Exception $e) {
            Log::error('EfevooPay Exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'status' => 0,
                'error' => $e->getMessage(),
                'message' => 'Error inesperado',
                'codigo' => 'EXCEPTION',
            ];
        }
    }

    /**
     * Detecta la marca de la tarjeta
     */
    protected function detectCardBrand(string $cardNumber): string
    {
        $firstTwo = substr($cardNumber, 0, 2);
        $firstFour = substr($cardNumber, 0, 4);

        if (preg_match('/^4/', $cardNumber)) {
            return 'Visa';
        } elseif (preg_match('/^5[1-5]/', $cardNumber)) {
            return 'MasterCard';
        } elseif (preg_match('/^3[47]/', $cardNumber)) {
            return 'American Express';
        } elseif (preg_match('/^3(?:0[0-5]|[68])/', $cardNumber)) {
            return 'Diners Club';
        } elseif (preg_match('/^6(?:011|5)/', $cardNumber)) {
            return 'Discover';
        } elseif (preg_match('/^(?:2131|1800|35)/', $cardNumber)) {
            return 'JCB';
        }

        return 'Unknown';
    }

    /**
     * Sincroniza transacciones con base de datos local
     */
    protected function syncTransactions(array $transactions): void
    {
        foreach ($transactions as $tx) {
            EfevooTransaction::updateOrCreate(
                ['transaction_id' => $tx['ID'] ?? $tx['id']],
                [
                    'reference' => $tx['reference'] ?? 'TXN-' . ($tx['ID'] ?? 'UNK'),
                    'amount' => $tx['amount'] ?? $tx['monto'] ?? 0,
                    'status' => $this->mapTransactionStatus($tx),
                    'response_code' => $tx['approved'] ?? $tx['code'] ?? null,
                    'response_message' => $tx['concept'] ?? $tx['Transaccion'] ?? null,
                    'transaction_type' => $this->mapTransactionType($tx),
                    'response_data' => $tx,
                    'processed_at' => isset($tx['date']) ?
                        \Carbon\Carbon::parse($tx['date']) : now(),
                ]
            );
        }
    }

    /**
     * Mapea el estado de la transacción
     */
    protected function mapTransactionStatus(array $tx): string
    {
        $status = strtoupper($tx['status'] ?? $tx['approved'] ?? '');

        if ($status === '00' || $status === 'COMPENSADA') {
            return EfevooTransaction::STATUS_APPROVED;
        } elseif ($status === 'PENDING' || $status === 'EN TRANSITO') {
            return EfevooTransaction::STATUS_PENDING;
        } else {
            return EfevooTransaction::STATUS_DECLINED;
        }
    }

    /**
     * Mapea el tipo de transacción
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
     * Log de solicitudes
     */
    protected function logRequest(array $request, int $statusCode, ?array $response): void
    {
        $logData = [
            'timestamp' => now()->toISOString(),
            'method' => $request['method'] ?? 'unknown',
            'environment' => $this->environment,
            'request' => $this->sanitizePayload($request),
            'response_status' => $statusCode,
            'response' => $response,
        ];

        Log::channel($this->config['log_channel'])->info('EfevooPay API Request', $logData);
    }

    /**
     * Sanitiza datos sensibles en logs
     */
    protected function sanitizePayload(array $payload): array
    {
        $sanitized = $payload;

        if (isset($sanitized['payload']['token'])) {
            $sanitized['payload']['token'] = substr($sanitized['payload']['token'], 0, 10) . '...';
        }

        if (isset($sanitized['payload']['hash'])) {
            $sanitized['payload']['hash'] = substr($sanitized['payload']['hash'], 0, 10) . '...';
        }

        if (isset($sanitized['payload']['encrypt'])) {
            $sanitized['payload']['encrypt'] = substr($sanitized['payload']['encrypt'], 0, 20) . '...';
        }

        return $sanitized;
    }

    /**
     * Verifica el estado de la API
     */
    public function healthCheck(): array
    {
        try {
            $result = $this->getClientToken();

            return [
                'status' => $result['success'] ? 'online' : 'offline',
                'environment' => $this->environment,
                'timestamp' => now()->toISOString(),
                'message' => $result['message'] ?? 'Health check completed',
                'client_token' => $result['success'] ? 'valid' : 'invalid',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'environment' => $this->environment,
                'timestamp' => now()->toISOString(),
                'message' => $e->getMessage(),
                'client_token' => 'error',
            ];
        }
    }

    // Añade un método para usar token fijo si está disponible
    public function getClientToken(bool $forceRefresh = false): array
    {
        // Si hay un token fijo configurado, usarlo
        if (!empty($this->config['fixed_token'])) {
            $this->clientToken = $this->config['fixed_token'];
            return [
                'success' => true,
                'token' => $this->clientToken,
                'cached' => false,
                'fixed' => true,
            ];
        }

        $cacheKey = "efevoo_client_token_{$this->environment}";

        if (!$forceRefresh && Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            $this->clientToken = $cached['token'];
            return [
                'success' => true,
                'token' => $this->clientToken,
                'cached' => true,
            ];
        }

        $totp = $this->generateTOTP();
        $hash = $this->generateHash($totp);

        // Log para debug
        \Log::debug('Generando client token', [
            'cliente' => $this->config['cliente'],
            'totp' => $totp,
            'hash_preview' => substr($hash, 0, 20) . '...',
        ]);

        $payload = [
            'payload' => [
                'hash' => $hash,
                'cliente' => $this->config['cliente']
            ],
            'method' => 'getClientToken'
        ];

        $result = $this->makeRequest($payload);

        if ($result['success'] && isset($result['data']['token'])) {
            $this->clientToken = $result['data']['token'];

            // Cache por 11 meses (1 mes menos que la vigencia del token)
            Cache::put($cacheKey, [
                'token' => $this->clientToken,
                'expires_at' => now()->addMonths(11)
            ], now()->addMonths(11));

            return [
                'success' => true,
                'token' => $this->clientToken,
                'duracion' => $result['data']['duracion'] ?? '1 año',
                'cached' => false,
            ];
        }

        // Log detallado del error
        \Log::error('Error obteniendo client token', [
            'codigo' => $result['codigo'] ?? null,
            'message' => $result['message'] ?? null,
            'data' => $result['data'] ?? null,
            'environment' => $this->environment,
        ]);

        return $result;
    }

    protected function makeRequestDirect(array $payload): array
    {
        $headers = [
            'Content-Type: application/json',
            'X-API-USER: ' . $this->config['api_user'],
            'X-API-KEY: ' . $this->config['api_key'],
        ];

        $apiUrl = $this->config['api_url'];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        Log::info('EfevooPay Direct Request', [
            'url' => $apiUrl,
            'method' => $payload['method'] ?? 'unknown',
            'body_preview' => substr($body, 0, 200),
        ]);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
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

        if ($error) {
            Log::error('EfevooPay cURL Error', [
                'error' => $error,
                'url' => $apiUrl,
            ]);

            return [
                'success' => false,
                'status' => 0,
                'error' => $error,
                'message' => 'Error de conexión con EfevooPay',
                'codigo' => 'CURL_ERROR',
            ];
        }

        Log::info('EfevooPay Direct Response', [
            'status' => $httpCode,
            'response_preview' => substr($response, 0, 200),
        ]);

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('EfevooPay JSON decode error', [
                'error' => json_last_error_msg(),
                'response' => $response,
            ]);

            return [
                'success' => false,
                'status' => $httpCode,
                'message' => 'Error en la respuesta del servidor',
                'data' => ['raw' => $response],
                'codigo' => 'JSON_ERROR',
            ];
        }

        $success = $httpCode >= 200 && $httpCode < 300;
        $code = $data['codigo'] ?? ($data['response_code'] ?? null);

        // Considerar éxito si código es 00 o 100
        if (in_array($code, ['00', '100'])) {
            $success = true;
        }

        // También éxito si la respuesta tiene éxito aunque no tenga código
        if ($success && $code === null && !isset($data['error'])) {
            $code = 'SUCCESS';
        }

        return [
            'success' => $success,
            'status' => $httpCode,
            'codigo' => $code,
            'message' => $data['msg'] ?? $data['mensaje'] ?? $data['descripcion'] ?? ($data['message'] ?? ''),
            'data' => $data,
            'raw' => $response,
        ];
    }

    public function tokenizeCardExact(array $cardData, int $userId): array
    {
        // CLONACIÓN EXACTA de tu script funcional
        $config = $this->config;

        // 1. Generar TOTP y Hash EXACTAMENTE como tu script
        $totp = $this->generateTOTP();
        $hash = $this->generateHash($totp);

        // 2. Obtener token de cliente EXACTO
        $clientTokenResult = $this->getClientTokenExact($config, $totp, $hash);

        if (!$clientTokenResult['success']) {
            return $clientTokenResult;
        }

        $tokenCliente = $clientTokenResult['token'];

        // 3. Preparar tokenización EXACTA
        $track2 = $cardData['card_number'] . '=' . $cardData['expiration'];
        $datos = [
            'track2' => $track2,
            'amount' => number_format($cardData['amount'], 2, '.', ''),
        ];

        $encrypted = $this->encryptDataExact($datos, $config['clave'], $config['vector']);

        // 4. Crear transacción en DB primero
        $transaction = EfevooTransaction::create([
            'reference' => 'TOK-' . Str::random(10),
            'amount' => $cardData['amount'],
            'transaction_type' => EfevooTransaction::TYPE_TOKENIZATION,
            'status' => EfevooTransaction::STATUS_PENDING,
            'request_data' => [
                'card_last_four' => substr($cardData['card_number'], -4),
                'expiration' => $cardData['expiration'],
                'card_holder' => $cardData['card_holder'],
            ],
            'cav' => Str::upper(Str::random(10)),
        ]);

        try {
            // 5. Hacer request EXACTO como tu script
            $result = $this->makeRequestExact(
                $config['api_url'],
                $this->buildHeadersExact(),
                [
                    'payload' => [
                        'token' => $tokenCliente,
                        'encrypt' => $encrypted
                    ],
                    'method' => 'getTokenize'
                ]
            );

            if ($result['code'] != 200) {
                throw new \Exception("HTTP Error: " . $result['code']);
            }

            $responseToken = json_decode($result['body'], true);

            // 6. Verificar respuesta EXACTA
            $codigo = $responseToken['codigo'] ?? '';

            if ($codigo === '00' && isset($responseToken['token_usuario'])) {
                // ¡ÉXITO! Token recibido correctamente

                // Guardar token en base de datos
                $efevooToken = EfevooToken::create([
                    'alias' => strtolower($this->detectCardBrand($cardData['card_number'])) . '-' . substr($cardData['card_number'], -4),
                    'client_token' => $tokenCliente,
                    'card_token' => $responseToken['token_usuario'], // ← ¡TOKEN CORRECTO!
                    'card_last_four' => substr($cardData['card_number'], -4),
                    'card_brand' => $this->detectCardBrand($cardData['card_number']),
                    'card_expiration' => $cardData['expiration'],
                    'card_holder' => $cardData['card_holder'],
                    'customer_id' => $userId,
                    'environment' => $this->environment,
                    'expires_at' => now()->addYear(),
                    'is_active' => true,
                    'metadata' => [
                        'transaction_id' => $responseToken['id'] ?? null,
                        'numref' => $responseToken['numref'] ?? null,
                        'numtxn' => $responseToken['numtxn'] ?? null,
                        'id_approved' => $responseToken['id_approved'] ?? null,
                    ],
                ]);

                // Actualizar transacción
                $transaction->update([
                    'efevoo_token_id' => $efevooToken->id,
                    'status' => EfevooTransaction::STATUS_APPROVED,
                    'response_code' => $codigo,
                    'response_message' => $responseToken['descripcion'] ?? 'Aprobado',
                    'response_data' => $responseToken,
                    'processed_at' => now(),
                ]);

                return [
                    'success' => true,
                    'message' => 'Tarjeta tokenizada exitosamente',
                    'token_id' => $efevooToken->id,
                    'efevoo_token_id' => $efevooToken->id,
                    'efevoo_token' => $efevooToken,
                    'card_token' => $responseToken['token'],
                    'transaction' => $transaction,
                    'transaction_id' => $transaction->id,
                    'codigo' => $codigo,
                    'data' => $responseToken,
                ];

            } else {
                // Error en tokenización
                $transaction->update([
                    'status' => EfevooTransaction::STATUS_DECLINED,
                    'response_code' => $codigo,
                    'response_message' => $responseToken['descripcion'] ?? ($responseToken['message'] ?? 'Error'),
                    'response_data' => $responseToken,
                    'processed_at' => now(),
                ]);

                return [
                    'success' => false,
                    'message' => 'Error en tokenización: ' . ($responseToken['descripcion'] ?? 'Código ' . $codigo),
                    'codigo' => $codigo,
                    'data' => $responseToken,
                ];
            }

        } catch (\Exception $e) {
            $transaction->update([
                'status' => EfevooTransaction::STATUS_ERROR,
                'response_message' => $e->getMessage(),
                'processed_at' => now(),
            ]);

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }

    // MÉTODOS AUXILIARES EXACTOS como tu script
    protected function getClientTokenExact(array $config, string $totp, string $hash): array
    {
        $headers = [
            'Content-Type: application/json',
            'X-API-USER: ' . $config['api_user'],
            'X-API-KEY: ' . $config['api_key']
        ];

        $body = json_encode([
            'payload' => ['hash' => $hash, 'cliente' => $config['cliente']],
            'method' => 'getClientToken'
        ]);

        $result = $this->makeRequestExact($config['api_url'], $headers, $body);

        if ($result['code'] != 200) {
            return [
                'success' => false,
                'message' => 'HTTP Error: ' . $result['code'],
            ];
        }

        $response = json_decode($result['body'], true);
        $token = $response['token'] ?? null;

        if (!$token) {
            return [
                'success' => false,
                'message' => 'No se obtuvo token',
                'response' => $response,
            ];
        }

        return [
            'success' => true,
            'token' => $token,
        ];
    }

    protected function makeRequestExact(string $url, array $headers, $body): array
    {
        // Si $body es array, convertirlo a JSON
        if (is_array($body)) {
            $body = json_encode($body, JSON_UNESCAPED_UNICODE);
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false, // ← IMPORTANTE: false como tu script
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $httpCode, 'body' => $response];
    }

    protected function buildHeadersExact(): array
    {
        return [
            'Content-Type: application/json',
            'X-API-USER: ' . $this->config['api_user'],
            'X-API-KEY: ' . $this->config['api_key']
        ];
    }

    protected function encryptDataExact(array $data, string $clave, string $vector): string
    {
        $plaintext = json_encode($data, JSON_UNESCAPED_UNICODE);
        return base64_encode(openssl_encrypt(
            $plaintext,
            'AES-128-CBC',
            $clave,
            OPENSSL_RAW_DATA,
            $vector
        ));
    }
}