<?php
// app/Services/EfevooPaySimulatorService.php

namespace App\Services;

use App\Models\EfevooToken;
use App\Models\EfevooTransaction;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class EfevooPaySimulatorService
{
    protected $environment = 'test';
    protected $config;
    protected $testCards;

    public function __construct()
    {
        $this->environment = config('efevoopay.environment', 'test');
        $this->config = config('efevoopay', []);
        
        // Tarjetas de prueba ficticias
        $this->testCards = [
            'approved_cards' => [
                '4111111111111111', // Visa de prueba
                '5555555555554444', // MasterCard de prueba
                '378282246310005',  // American Express de prueba
                '6011111111111117', // Discover de prueba
            ],
            'declined_cards' => [
                '4000000000000002', // Tarjeta rechazada genérica
                '5105105105105100', // Tarjeta rechazada
            ],
            'error_cards' => [
                '4242424242424241', // Error de procesamiento
            ]
        ];
    }

    /**
     * Verificar si el servicio real está disponible
     */
    public function isRealServiceAvailable(): bool
    {
        // Simular que en fines de semana está caído
        $dayOfWeek = date('N'); // 1=Lunes, 7=Domingo
        
        // Si es sábado (6) o domingo (7), simular caído
        if (in_array($dayOfWeek, [6, 7])) {
            Log::info('Simulador: Fin de semana - Servicio real no disponible');
            return false;
        }
        
        return false;
    }

    /**
     * TOKENIZACIÓN SIMULADA - CORREGIDA SIN REGEX PROBLEMÁTICOS
     */
    public function tokenizeCard(array $cardData, int $customerId): array
    {
        Log::info('Simulador: Iniciando tokenización de tarjeta', [
            'customer_id' => $customerId,
            'last_four' => substr($cardData['card_number'] ?? '', -4),
        ]);

        try {
            // Validación MANUAL para evitar problemas con regex
            $errors = $this->validateCardDataManually($cardData);
            
            if (!empty($errors)) {
                Log::warning('Simulador: Validación fallida', $errors);
                return [
                    'success' => false,
                    'message' => 'Datos de tarjeta inválidos',
                    'errors' => $errors,
                ];
            }

            $cardNumber = $cardData['card_number'];
            $lastFour = substr($cardNumber, -4);
            
            // Determinar si la tarjeta es de prueba aprobada, rechazada o con error
            $cardType = $this->getTestCardType($cardNumber);
            
            Log::info('Simulador: Tipo de tarjeta detectado', [
                'card_type' => $cardType,
                'last_four' => $lastFour,
            ]);

            // Crear transacción en DB
            $transaction = EfevooTransaction::create([
                'reference' => 'SIM-' . Str::random(10),
                'amount' => $cardData['amount'],
                'transaction_type' => EfevooTransaction::TYPE_TOKENIZATION,
                'status' => EfevooTransaction::STATUS_PENDING,
                'request_data' => [
                    'card_last_four' => $lastFour,
                    'expiration' => $cardData['expiration'],
                    'card_holder' => $cardData['card_holder'],
                    'amount' => $cardData['amount'],
                    'alias' => $cardData['alias'] ?? null,
                    'simulated' => true,
                ],
                'cav' => 'SIM-' . Str::upper(Str::random(8)),
            ]);

            // Simular delay de red
            usleep(rand(500000, 1500000)); // 0.5-1.5 segundos

            // Generar respuesta según tipo de tarjeta
            if ($cardType === 'approved') {
                return $this->simulateApprovedTokenization($cardData, $customerId, $transaction);
            } elseif ($cardType === 'declined') {
                return $this->simulateDeclinedTokenization($transaction, '05', 'Tarjeta rechazada por el banco');
            } else {
                return $this->simulateErrorTokenization($transaction, '96', 'Error de sistema');
            }

        } catch (\Exception $e) {
            Log::error('Simulador: Error en tokenizeCard', [
                'error' => $e->getMessage(),
                'customer_id' => $customerId,
                'trace' => $e->getTraceAsString(), // Añadir trace para ver línea exacta
            ]);

            return [
                'success' => false,
                'message' => 'Error simulado en tokenización: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Validación manual para evitar problemas con regex
     */
    protected function validateCardDataManually(array $cardData): array
    {
        $errors = [];
        
        // Validar número de tarjeta
        $cardNumber = $cardData['card_number'] ?? '';
        if (empty($cardNumber)) {
            $errors['card_number'] = ['El número de tarjeta es requerido'];
        } elseif (strlen($cardNumber) !== 16 || !ctype_digit($cardNumber)) {
            $errors['card_number'] = ['El número de tarjeta debe tener 16 dígitos'];
        }
        
        // Validar fecha de expiración
        $expiration = $cardData['expiration'] ?? '';
        if (empty($expiration)) {
            $errors['expiration'] = ['La fecha de expiración es requerida'];
        } elseif (strlen($expiration) !== 4 || !ctype_digit($expiration)) {
            $errors['expiration'] = ['La fecha de expiración debe tener formato MMYY (4 dígitos)'];
        } else {
            $month = (int) substr($expiration, 0, 2);
            $year = (int) substr($expiration, 2, 2);
            
            if ($month < 1 || $month > 12) {
                $errors['expiration'] = ['El mes debe estar entre 01 y 12'];
            }
            
            // Validar que no esté expirada
            $currentYear = (int) date('y');
            $currentMonth = (int) date('m');
            
            if ($year < $currentYear || ($year == $currentYear && $month < $currentMonth)) {
                $errors['expiration'] = ['La tarjeta está expirada'];
            }
        }
        
        // Validar titular
        $cardHolder = $cardData['card_holder'] ?? '';
        if (empty($cardHolder)) {
            $errors['card_holder'] = ['El nombre del titular es requerido'];
        } elseif (strlen($cardHolder) > 100) {
            $errors['card_holder'] = ['El nombre no puede exceder 100 caracteres'];
        }
        
        // Validar monto
        $amount = $cardData['amount'] ?? 0;
        if (!is_numeric($amount) || $amount < 0.01) {
            $errors['amount'] = ['El monto debe ser mayor a 0.01'];
        }
        
        return $errors;
    }

    /**
     * Simular tokenización exitosa
     */
    protected function simulateApprovedTokenization(array $cardData, int $customerId, EfevooTransaction $transaction): array
    {
        $cardToken = 'sim_token_' . Str::random(32);
        $clientToken = 'sim_client_' . Str::random(24);
        
        $alias = $cardData['alias'] ?? $this->generateCardAlias($cardData);
        
        // Crear token en base de datos
        $efevooToken = EfevooToken::create([
            'alias' => $alias,
            'client_token' => $clientToken,
            'card_token' => $cardToken,
            'card_last_four' => substr($cardData['card_number'], -4),
            'card_brand' => $this->detectCardBrand($cardData['card_number']),
            'card_expiration' => $cardData['expiration'],
            'card_holder' => $cardData['card_holder'],
            'customer_id' => $customerId,
            'environment' => 'test',
            'expires_at' => now()->addYear(),
            'is_active' => true,
            'metadata' => [
                'simulated' => true,
                'simulator' => true,
                'transaction_id' => 'SIM-' . Str::random(8),
                'numref' => 'SIMREF' . Str::random(6),
                'numtxn' => 'SIMTXN' . Str::random(6),
                'id_approved' => 'SIMAPP' . Str::random(8),
                'simulated_at' => now()->toISOString(),
            ],
        ]);

        // Actualizar transacción
        $transaction->update([
            'efevoo_token_id' => $efevooToken->id,
            'status' => EfevooTransaction::STATUS_APPROVED,
            'response_code' => '00',
            'response_message' => 'Tokenización simulada exitosa',
            'response_data' => [
                'codigo' => '00',
                'descripcion' => 'Tokenización simulada exitosa',
                'token_usuario' => $cardToken,
                'token' => $cardToken,
                'numref' => 'SIMREF' . Str::random(6),
                'numtxn' => 'SIMTXN' . Str::random(6),
                'id_approved' => 'SIMAPP' . Str::random(8),
                'simulated' => true,
            ],
            'processed_at' => now(),
        ]);

        Log::info('Simulador: Tokenización exitosa simulada', [
            'token_id' => $efevooToken->id,
            'customer_id' => $customerId,
            'alias' => $alias,
        ]);

        return [
            'success' => true,
            'message' => 'Tarjeta tokenizada exitosamente (simulado)',
            'token_id' => $efevooToken->id,
            'efevoo_token' => $efevooToken,
            'card_token' => $cardToken,
            'transaction_id' => $transaction->id,
            'code' => '00',
            'data' => [
                'codigo' => '00',
                'descripcion' => 'Tokenización simulada exitosa',
                'token_usuario' => $cardToken,
                'simulated' => true,
            ],
        ];
    }

    /**
     * Simular tokenización rechazada
     */
    protected function simulateDeclinedTokenization(EfevooTransaction $transaction, string $code, string $message): array
    {
        $transaction->update([
            'status' => EfevooTransaction::STATUS_DECLINED,
            'response_code' => $code,
            'response_message' => $message,
            'response_data' => [
                'codigo' => $code,
                'descripcion' => $message,
                'simulated' => true,
            ],
            'processed_at' => now(),
        ]);

        return [
            'success' => false,
            'message' => $message . ' (simulado)',
            'code' => $code,
            'data' => [
                'codigo' => $code,
                'descripcion' => $message,
                'simulated' => true,
            ],
        ];
    }

    /**
     * Simular tokenización con error
     */
    protected function simulateErrorTokenization(EfevooTransaction $transaction, string $code, string $message): array
    {
        $transaction->update([
            'status' => EfevooTransaction::STATUS_ERROR,
            'response_code' => $code,
            'response_message' => $message,
            'response_data' => [
                'codigo' => $code,
                'descripcion' => $message,
                'simulated' => true,
            ],
            'processed_at' => now(),
        ]);

        return [
            'success' => false,
            'message' => $message . ' (simulado)',
            'code' => $code,
            'data' => [
                'codigo' => $code,
                'descripcion' => $message,
                'simulated' => true,
            ],
        ];
    }

    /**
     * Detectar tipo de tarjeta de prueba
     */
    protected function getTestCardType(string $cardNumber): string
    {
        $cleanedCard = preg_replace('/\D/', '', $cardNumber);
        
        if (in_array($cleanedCard, $this->testCards['approved_cards'])) {
            return 'approved';
        } elseif (in_array($cleanedCard, $this->testCards['declined_cards'])) {
            return 'declined';
        } elseif (in_array($cleanedCard, $this->testCards['error_cards'])) {
            return 'error';
        }
        
        // Por defecto, aprobar si no está en las listas especiales
        return 'approved';
    }

    /**
     * Generar alias para la tarjeta
     */
    protected function generateCardAlias(array $cardData): string
    {
        $brand = strtolower($this->detectCardBrand($cardData['card_number']));
        $lastFour = substr($cardData['card_number'], -4);
        
        return "{$brand}-{$lastFour}-sim";
    }

    /**
     * Detectar marca de tarjeta - CORREGIDO
     */
    protected function detectCardBrand(string $cardNumber): string
    {
        // Limpiar el número de tarjeta
        $cleaned = preg_replace('/\D/', '', $cardNumber);
        
        if (empty($cleaned)) {
            return 'Unknown';
        }
        
        // Detectar marca SIN usar regex problemáticos
        $firstDigit = substr($cleaned, 0, 1);
        $firstTwoDigits = substr($cleaned, 0, 2);
        
        if ($firstDigit === '4') {
            return 'Visa';
        } elseif ($firstTwoDigits >= '51' && $firstTwoDigits <= '55') {
            return 'MasterCard';
        } elseif ($firstTwoDigits === '34' || $firstTwoDigits === '37') {
            return 'American Express';
        } elseif ($firstTwoDigits === '36' || $firstTwoDigits === '38' || 
                 ($firstTwoDigits >= '30' && $firstTwoDigits <= '35')) {
            return 'Diners Club';
        } elseif ($cleaned[0] === '6' && (substr($cleaned, 0, 4) === '6011' || $cleaned[0] === '65')) {
            return 'Discover';
        } elseif (substr($cleaned, 0, 4) === '2131' || substr($cleaned, 0, 4) === '1800' || 
                 substr($cleaned, 0, 2) === '35') {
            return 'JCB';
        }
        
        return 'Unknown';
    }

    /**
     * Tokenización RÁPIDA simulada
     */
    public function fastTokenize(array $cardData, int $customerId): array
    {
        return $this->tokenizeCard($cardData, $customerId);
    }

    /**
     * Obtener token de cliente simulado
     */
    public function getClientToken(bool $forceRefresh = false): array
    {
        $token = 'sim_client_token_' . Str::random(24);
        
        Log::info('Simulador: Token de cliente generado', [
            'token_preview' => substr($token, 0, 10) . '...',
        ]);
        
        return [
            'success' => true,
            'token' => $token,
            'duracion' => '1 año (simulado)',
            'cached' => false,
            'simulated' => true,
        ];
    }

    /**
     * Health check simulado
     */
    public function healthCheck(): array
    {
        return [
            'status' => 'online',
            'environment' => 'simulated',
            'client_token' => 'valid',
            'timestamp' => now()->toISOString(),
            'message' => 'Servicio EfevooPay SIMULADO operativo',
            'simulated' => true,
        ];
    }

    /**
     * Procesar pago simulado
     */
    public function processPayment(array $paymentData, int $customerId, ?int $tokenId = null): array
    {
        Log::info('Simulador: Procesando pago', [
            'customer_id' => $customerId,
            'amount' => $paymentData['amount'] ?? 0,
            'token_id' => $tokenId,
        ]);

        try {
            // Validación básica
            $validator = validator($paymentData, [
                'amount' => 'required|numeric|min:0.01',
                'description' => 'nullable|string|max:255',
                'reference' => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                return [
                    'success' => false,
                    'message' => 'Datos de pago inválidos',
                    'errors' => $validator->errors()->toArray(),
                ];
            }

            // Crear transacción
            $transaction = EfevooTransaction::create([
                'reference' => $paymentData['reference'] ?? 'PAY-SIM-' . Str::random(8),
                'amount' => $paymentData['amount'],
                'transaction_type' => EfevooTransaction::TYPE_PAYMENT,
                'status' => EfevooTransaction::STATUS_PENDING,
                'request_data' => array_merge($paymentData, ['simulated' => true]),
                'cav' => 'PAY-SIM-' . Str::upper(Str::random(8)),
            ]);

            if ($tokenId) {
                $transaction->efevoo_token_id = $tokenId;
                $transaction->save();
            }

            // Simular delay
            usleep(rand(800000, 2000000));

            // Decidir si aprobar o rechazar basado en el monto
            $amount = $paymentData['amount'];
            $shouldApprove = $amount <= 1000; // Aprobar pagos menores a 1000

            if ($shouldApprove) {
                return $this->simulateApprovedPayment($transaction, $paymentData);
            } else {
                return $this->simulateDeclinedPayment($transaction, '51', 'Fondos insuficientes (simulado)');
            }

        } catch (\Exception $e) {
            Log::error('Simulador: Error en processPayment', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error simulado en pago: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Simular pago aprobado
     */
    protected function simulateApprovedPayment(EfevooTransaction $transaction, array $paymentData): array
    {
        $transaction->update([
            'status' => EfevooTransaction::STATUS_APPROVED,
            'response_code' => '00',
            'response_message' => 'Pago aprobado (simulado)',
            'response_data' => [
                'codigo' => '00',
                'descripcion' => 'Pago aprobado exitosamente',
                'numref' => 'PAYREF' . Str::random(6),
                'numtxn' => 'PAYTXN' . Str::random(6),
                'id_approved' => 'PAYAPP' . Str::random(8),
                'authorization_code' => 'SIM' . Str::random(6),
                'simulated' => true,
                'amount' => $paymentData['amount'],
                'currency' => $paymentData['currency'] ?? 'MXN',
            ],
            'processed_at' => now(),
        ]);

        return [
            'success' => true,
            'message' => 'Pago procesado exitosamente (simulado)',
            'transaction_id' => $transaction->id,
            'reference' => $transaction->reference,
            'authorization_code' => 'SIM' . Str::random(6),
            'code' => '00',
            'data' => [
                'codigo' => '00',
                'descripcion' => 'Pago aprobado exitosamente',
                'simulated' => true,
            ],
        ];
    }

    /**
     * Simular pago rechazado
     */
    protected function simulateDeclinedPayment(EfevooTransaction $transaction, string $code, string $message): array
    {
        $transaction->update([
            'status' => EfevooTransaction::STATUS_DECLINED,
            'response_code' => $code,
            'response_message' => $message,
            'response_data' => [
                'codigo' => $code,
                'descripcion' => $message,
                'simulated' => true,
            ],
            'processed_at' => now(),
        ]);

        return [
            'success' => false,
            'message' => $message . ' (simulado)',
            'code' => $code,
            'transaction_id' => $transaction->id,
            'data' => [
                'codigo' => $code,
                'descripcion' => $message,
                'simulated' => true,
            ],
        ];
    }

    /**
     * Reembolso simulado
     */
    public function processRefund(array $refundData, int $transactionId): array
    {
        Log::info('Simulador: Procesando reembolso', [
            'transaction_id' => $transactionId,
            'refund_data' => $refundData,
        ]);

        try {
            $originalTransaction = EfevooTransaction::findOrFail($transactionId);

            // Crear transacción de reembolso
            $refundTransaction = EfevooTransaction::create([
                'reference' => 'REF-SIM-' . Str::random(8),
                'amount' => $refundData['amount'] ?? $originalTransaction->amount,
                'transaction_type' => EfevooTransaction::TYPE_REFUND,
                'status' => EfevooTransaction::STATUS_PENDING,
                'request_data' => array_merge($refundData, [
                    'original_transaction_id' => $transactionId,
                    'simulated' => true,
                ]),
                'parent_transaction_id' => $transactionId,
                'cav' => 'REF-SIM-' . Str::upper(Str::random(8)),
            ]);

            usleep(rand(1000000, 3000000));

            // Aprobar reembolso
            $refundTransaction->update([
                'status' => EfevooTransaction::STATUS_APPROVED,
                'response_code' => '00',
                'response_message' => 'Reembolso procesado (simulado)',
                'response_data' => [
                    'codigo' => '00',
                    'descripcion' => 'Reembolso exitoso',
                    'numref' => 'REFREF' . Str::random(6),
                    'numtxn' => 'REFTXN' . Str::random(6),
                    'id_approved' => 'REFAPP' . Str::random(8),
                    'simulated' => true,
                ],
                'processed_at' => now(),
            ]);

            return [
                'success' => true,
                'message' => 'Reembolso procesado exitosamente (simulado)',
                'transaction_id' => $refundTransaction->id,
                'refund_id' => $refundTransaction->id,
                'code' => '00',
                'data' => [
                    'codigo' => '00',
                    'descripcion' => 'Reembolso exitoso',
                    'simulated' => true,
                ],
            ];

        } catch (\Exception $e) {
            Log::error('Simulador: Error en processRefund', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error simulado en reembolso: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Buscar transacciones simuladas
     */
    public function searchTransactions(array $filters = []): array
    {
        $limit = $filters['limit'] ?? 10;
        $transactions = EfevooTransaction::where('simulated', true)
            ->orWhere(function ($query) {
                $query->where('request_data', 'LIKE', '%simulated%')
                      ->orWhere('response_data', 'LIKE', '%simulated%');
            })
            ->limit($limit)
            ->get()
            ->map(function ($tx) {
                return [
                    'ID' => $tx->id,
                    'reference' => $tx->reference,
                    'amount' => $tx->amount,
                    'status' => $tx->status,
                    'type' => $tx->transaction_type,
                    'date' => $tx->processed_at?->toISOString() ?? $tx->created_at->toISOString(),
                    'approved' => $tx->response_code === '00' ? '00' : '99',
                    'Transaccion' => $tx->transaction_type,
                    'simulated' => true,
                ];
            })
            ->toArray();

        return [
    'success' => true,
    'data' => [
        'data' => $transactions,
        'total' => count($transactions),
        'simulated' => true,
    ],
];
    }

    /**
     * Obtener tarjetas de prueba
     */
    public function getTestCards(): array
    {
        return [
            'approved' => [
                [
                    'number' => '4111111111111111',
                    'brand' => 'Visa',
                    'expiration' => '1228',
                    'cvv' => '123',
                    'holder' => 'JOHN DOE',
                    'description' => 'Visa de prueba - Siempre aprobada'
                ],
                [
                    'number' => '5555555555554444',
                    'brand' => 'MasterCard',
                    'expiration' => '0529',
                    'cvv' => '456',
                    'holder' => 'JANE SMITH',
                    'description' => 'MasterCard de prueba - Siempre aprobada'
                ],
                [
                    'number' => '378282246310005',
                    'brand' => 'American Express',
                    'expiration' => '0730',
                    'cvv' => '7890',
                    'holder' => 'ROBERT JOHNSON',
                    'description' => 'American Express de prueba - Siempre aprobada'
                ]
            ],
            'declined' => [
                [
                    'number' => '4000000000000002',
                    'brand' => 'Visa',
                    'expiration' => '0129',
                    'cvv' => '999',
                    'holder' => 'DECLINED USER',
                    'description' => 'Tarjeta rechazada - Siempre declinada'
                ]
            ],
            'error' => [
                [
                    'number' => '4242424242424241',
                    'brand' => 'Visa',
                    'expiration' => '0828',
                    'cvv' => '111',
                    'holder' => 'ERROR USER',
                    'description' => 'Tarjeta con error - Error de procesamiento'
                ]
            ]
        ];
    }
}