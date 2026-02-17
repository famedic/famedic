<?php

namespace App\Services\EfevooPay;

use Illuminate\Support\Facades\Log;
use App\Models\EfevooToken;

class EfevooPaySimulatorService
{
    protected string $environment = 'sandbox';
    
    public function __construct()
    {
        Log::info('EfevooPaySimulatorService inicializado');
    }

    /**
     * Simular un cargo con tarjeta
     */
    public function chargeCard(array $chargeData): array
    {
        try {
            Log::info('EfevooPaySimulatorService::chargeCard - Simulando cargo', [
                'charge_data' => $this->maskSensitiveData($chargeData),
                'environment' => $this->environment,
            ]);

            // Validar datos requeridos
            if (empty($chargeData['token_id']) || empty($chargeData['amount'])) {
                Log::warning('Datos de cargo incompletos en simulador', $chargeData);
                
                return [
                    'success' => false,
                    'message' => 'Datos de cargo incompletos',
                    'code' => 'INVALID_REQUEST',
                    'simulated' => true,
                ];
            }

            // Obtener información del token
            $tokenId = $chargeData['token_id'];
            
            // Buscar el token en la base de datos
            $token = null;
            
            // Buscar por client_token o ID
            if (str_starts_with($tokenId, 'tok_') || str_starts_with($tokenId, 'sim_')) {
                $token = EfevooToken::where('client_token', $tokenId)->first();
            } else {
                // Asumir que es el ID de la base de datos
                $token = EfevooToken::find($tokenId);
            }
            
            $cardLastFour = $token ? $token->card_last_four : '4444';
            $cardBrand = $token ? $token->card_brand : 'visa';

            // Generar ID de transacción simulado
            $transactionId = 'sim_' . time() . '_' . rand(1000, 9999);
            
            // Monto para simulación
            $amount = floatval($chargeData['amount']);
            
            Log::info('Simulando cargo con monto', [
                'amount' => $amount,
                'transaction_id' => $transactionId,
                'card_last_four' => $cardLastFour,
                'card_brand' => $cardBrand,
                'token_id_received' => $tokenId,
                'token_found' => $token ? 'yes' : 'no',
            ]);

            // **PARA PRUEBAS DE LABORATORIO - SIEMPRE APROBAR**
            return [
                'success' => true,
                'transaction_id' => $transactionId,
                'authorization_code' => 'AUTH_SIM_' . strtoupper(bin2hex(random_bytes(8))),
                'status' => 'approved',
                'message' => '✅ Pago simulado exitosamente - Ambiente de pruebas',
                'amount' => $amount,
                'currency' => 'MXN',
                'card_last_four' => $cardLastFour,
                'card_brand' => $cardBrand,
                'simulated' => true,
                'environment' => $this->environment,
                'timestamp' => now()->toISOString(),
                'metadata' => [
                    'simulation_type' => 'successful_lab_payment',
                    'test_scenario' => 'always_approve_lab',
                    'customer_id' => $chargeData['customer_id'] ?? null,
                    'reference' => $chargeData['reference'] ?? null,
                    'original_token_id' => $tokenId,
                ],
            ];
            
        } catch (\Exception $e) {
            Log::error('Error en EfevooPaySimulatorService::chargeCard', [
                'error' => $e->getMessage(),
                'charge_data' => $this->maskSensitiveData($chargeData),
            ]);
            
            return [
                'success' => false,
                'message' => 'Error en simulación: ' . $e->getMessage(),
                'simulated' => true,
            ];
        }
    }

    /**
     * Simular tokenización de tarjeta
     */
    public function tokenizeCard(array $cardData, int $customerId): array
    {
        try {
            Log::info('EfevooPaySimulatorService::tokenizeCard - Simulando tokenización', [
                'customer_id' => $customerId,
                'card_data_masked' => $this->maskSensitiveData($cardData),
            ]);

            // Validar datos de tarjeta
            if (empty($cardData['card_number']) || empty($cardData['expiration']) || empty($cardData['card_holder'])) {
                return [
                    'success' => false,
                    'message' => 'Datos de tarjeta incompletos',
                    'errors' => ['card' => 'Por favor completa todos los campos de la tarjeta.'],
                ];
            }

            // Simular validación de tarjeta
            $cardNumber = str_replace(' ', '', $cardData['card_number']);
            $lastFour = substr($cardNumber, -4);
            
            // Detectar marca de tarjeta
            $brand = $this->detectCardBrand($cardNumber);
            
            // Generar token simulado
            $tokenId = 'tok_sim_' . time() . '_' . rand(1000, 9999);
            $clientToken = 'clt_sim_' . bin2hex(random_bytes(16));

            // Para simplicidad en pruebas, siempre aprobar
            return [
                'success' => true,
                'token_id' => $tokenId,
                'client_token' => $clientToken,
                'card_last_four' => $lastFour,
                'card_brand' => $brand,
                'expiration' => $cardData['expiration'],
                'card_holder' => $cardData['card_holder'],
                'alias' => $cardData['alias'] ?? null,
                'message' => 'Tarjeta tokenizada exitosamente (simulación)',
                'simulated' => true,
                'environment' => $this->environment,
                'test_charge_amount' => 1.00,
            ];
            
        } catch (\Exception $e) {
            Log::error('Error en EfevooPaySimulatorService::tokenizeCard', [
                'error' => $e->getMessage(),
                'customer_id' => $customerId,
            ]);
            
            return [
                'success' => false,
                'message' => 'Error en tokenización: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Obtener tarjetas de prueba para el frontend
     */
    public function getTestCards(): array
    {
        return [
            [
                'number' => '4242 4242 4242 4242',
                'cvv' => '123',
                'exp_month' => '12',
                'exp_year' => '29',
                'brand' => 'visa',
                'name' => 'Visa de Prueba - Siempre aprueba',
                'description' => 'Para pruebas exitosas'
            ],
            [
                'number' => '5555 5555 5555 4444',
                'cvv' => '123',
                'exp_month' => '12',
                'exp_year' => '29',
                'brand' => 'mastercard',
                'name' => 'Mastercard de Prueba - Siempre aprueba',
                'description' => 'Para pruebas exitosas'
            ],
            [
                'number' => '3782 822463 10005',
                'cvv' => '1234',
                'exp_month' => '12',
                'exp_year' => '29',
                'brand' => 'amex',
                'name' => 'American Express de Prueba',
                'description' => 'Para pruebas exitosas'
            ],
        ];
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
        
        if (preg_match('/^4/', $cleanNumber)) return 'visa';
        if (preg_match('/^5[1-5]/', $cleanNumber) || preg_match('/^2[2-7]/', $cleanNumber)) return 'mastercard';
        if (preg_match('/^3[47]/', $cleanNumber)) return 'amex';
        if (preg_match('/^6(?:011|4[4-9][0-9]|5)/', $cleanNumber)) return 'discover';
        
        return 'unknown';
    }

    /**
     * Enmascarar datos sensibles
     */
    private function maskSensitiveData(array $data): array
    {
        $masked = $data;
        
        if (isset($masked['card_number'])) {
            $masked['card_number'] = '**** **** **** ' . substr(str_replace(' ', '', $masked['card_number']), -4);
        }
        
        if (isset($masked['cvv'])) {
            $masked['cvv'] = '***';
        }
        
        if (isset($masked['token_id'])) {
            $masked['token_id'] = substr($masked['token_id'], 0, 8) . '...';
        }
        
        // No mostrar cvv en logs
        unset($masked['cvv']);
        
        return $masked;
    }
}