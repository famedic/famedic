<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerPaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentMethodService
{
    private EfevooPayService $efevooPayService;
    
    public function __construct(EfevooPayService $efevooPayService)
    {
        $this->efevooPayService = $efevooPayService;
    }
    
    /**
     * Tokenizar una tarjeta con EfevooPay
     */
    public function tokenizeCard(Customer $customer, array $cardData, array $metadata = []): array
    {
        DB::beginTransaction();
        
        try {
            Log::info('Iniciando tokenización de tarjeta', [
                'customer_id' => $customer->id,
                'last_four' => substr($cardData['number'] ?? '', -4),
            ]);
            
            // 1. Validar datos de tarjeta
            $validation = $this->validateCardData($cardData);
            if (!$validation['valid']) {
                throw new \Exception($validation['errors'][0] ?? 'Datos de tarjeta inválidos');
            }
            
            // 2. Llamar a EfevooPay para tokenizar
            // NOTA: Esto depende de que EfevooPay proporcione el endpoint
            $tokenizationResult = $this->callEfevooPayTokenization($cardData, $customer);
            
            if (!$tokenizationResult['success']) {
                throw new \Exception('Error en tokenización: ' . ($tokenizationResult['error'] ?? 'Error desconocido'));
            }
            
            // 3. Detectar marca de tarjeta
            $brand = $this->detectCardBrand($cardData['number']);
            
            // 4. Crear registro en nuestra base de datos
            $paymentMethod = CustomerPaymentMethod::create([
                'customer_id' => $customer->id,
                'gateway' => 'efevoopay',
                'gateway_payment_method_id' => $tokenizationResult['payment_method_id'] ?? 'pm_' . Str::random(14),
                'gateway_token' => $tokenizationResult['token'] ?? 'tok_efv_' . Str::random(16),
                'last_four' => substr($cardData['number'], -4),
                'brand' => $brand,
                'card_type' => $cardData['type'] ?? 'credit',
                'exp_month' => str_pad($cardData['exp_month'], 2, '0', STR_PAD_LEFT),
                'exp_year' => $cardData['exp_year'],
                'alias' => $cardData['alias'] ?? $this->generateDefaultAlias($customer, $brand),
                'metadata' => array_merge($metadata, [
                    'tokenized_at' => now()->toISOString(),
                    'device' => request()->header('User-Agent'),
                    'ip' => request()->ip(),
                    'cardholder_name' => $cardData['cardholder_name'] ?? null,
                ]),
                'gateway_response' => $tokenizationResult,
                'is_default' => $customer->paymentMethods()->count() === 0, // Primera tarjeta = default
                'is_verified' => $tokenizationResult['verified'] ?? false,
                'verified_at' => ($tokenizationResult['verified'] ?? false) ? now() : null,
            ]);
            
            // 5. Si es verificado, marcar como usado
            if ($paymentMethod->is_verified) {
                $paymentMethod->markAsUsed();
            }
            
            DB::commit();
            
            Log::info('Tarjeta tokenizada exitosamente', [
                'customer_id' => $customer->id,
                'payment_method_id' => $paymentMethod->id,
                'last_four' => $paymentMethod->last_four,
                'brand' => $paymentMethod->brand,
            ]);
            
            return [
                'success' => true,
                'payment_method' => $paymentMethod,
                'message' => 'Tarjeta guardada exitosamente',
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error tokenizando tarjeta', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'card_data' => [
                    'last_four' => substr($cardData['number'] ?? '', -4),
                    'brand' => $this->detectCardBrand($cardData['number'] ?? ''),
                ],
            ]);
            
            return [
                'success' => false,
                'error' => 'No pudimos guardar tu tarjeta. Por favor intenta nuevamente.',
                'debug_error' => config('app.debug') ? $e->getMessage() : null,
            ];
        }
    }
    
    /**
     * Validar datos de tarjeta
     */
    private function validateCardData(array $cardData): array
    {
        $errors = [];
        
        // Validar número de tarjeta
        if (empty($cardData['number']) || !preg_match('/^[0-9]{13,19}$/', $cardData['number'])) {
            $errors[] = 'Número de tarjeta inválido';
        } elseif (!$this->validateLuhn($cardData['number'])) {
            $errors[] = 'Número de tarjeta no válido';
        }
        
        // Validar fecha de expiración
        if (empty($cardData['exp_month']) || !preg_match('/^(0[1-9]|1[0-2])$/', $cardData['exp_month'])) {
            $errors[] = 'Mes de expiración inválido';
        }
        
        if (empty($cardData['exp_year']) || !preg_match('/^20[2-9][0-9]$/', $cardData['exp_year'])) {
            $errors[] = 'Año de expiración inválido';
        }
        
        // Validar que no esté expirada
        if (!empty($cardData['exp_month']) && !empty($cardData['exp_year'])) {
            $expiryDate = \Carbon\Carbon::createFromDate(
                $cardData['exp_year'], 
                $cardData['exp_month'], 
                1
            )->endOfMonth();
            
            if ($expiryDate->isPast()) {
                $errors[] = 'La tarjeta ha expirado';
            }
        }
        
        // Validar CVC
        if (empty($cardData['cvc']) || !preg_match('/^[0-9]{3,4}$/', $cardData['cvc'])) {
            $errors[] = 'Código CVC inválido';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
    
    /**
     * Algoritmo de Luhn para validar números de tarjeta
     */
    private function validateLuhn(string $number): bool
    {
        $number = preg_replace('/\D/', '', $number);
        
        $sum = 0;
        $length = strlen($number);
        $parity = $length % 2;
        
        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $number[$i];
            
            if ($i % 2 === $parity) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            
            $sum += $digit;
        }
        
        return $sum % 10 === 0;
    }
    
    /**
     * Llamar a EfevooPay para tokenización
     * TODO: Reemplazar con el endpoint REAL de EfevooPay cuando lo proporcionen
     */
    private function callEfevooPayTokenization(array $cardData, Customer $customer): array
    {
        // Por ahora, simulamos una respuesta hasta que EfevooPay proporcione el endpoint
        
        Log::info('Simulando tokenización con EfevooPay', [
            'customer_id' => $customer->id,
            'last_four' => substr($cardData['number'], -4),
            'brand' => $this->detectCardBrand($cardData['number']),
        ]);
        
        // Simulación - En producción esto sería una llamada HTTP real
        // Ejemplo: 
        // $response = Http::post('https://ecommapi.efevoopay.com/tokenize', [
        //     'api_key' => config('efevoopay.api_key'),
        //     'card_number' => $cardData['number'],
        //     'exp_month' => $cardData['exp_month'],
        //     'exp_year' => $cardData['exp_year'],
        //     'cvc' => $cardData['cvc'],
        // ]);
        
        return [
            'success' => true,
            'token' => 'tok_efv_' . Str::random(16),
            'payment_method_id' => 'pm_' . Str::random(14),
            'verified' => true,
            'brand' => $this->detectCardBrand($cardData['number']),
            'last4' => substr($cardData['number'], -4),
            'exp_month' => $cardData['exp_month'],
            'exp_year' => $cardData['exp_year'],
            'gateway' => 'efevoopay',
            'created_at' => now()->toISOString(),
            'simulated' => true, // Indicar que es simulado
        ];
    }
    
    /**
     * Detectar marca de tarjeta por número
     */
    public function detectCardBrand(string $cardNumber): string
    {
        $cardNumber = preg_replace('/\D/', '', $cardNumber);
        
        if (empty($cardNumber)) {
            return 'unknown';
        }
        
        $firstDigit = substr($cardNumber, 0, 1);
        $firstTwoDigits = substr($cardNumber, 0, 2);
        $firstFourDigits = substr($cardNumber, 0, 4);
        
        // Visa
        if ($firstDigit === '4') {
            return 'visa';
        }
        
        // Mastercard
        if ($firstTwoDigits >= '51' && $firstTwoDigits <= '55') {
            return 'mastercard';
        }
        
        // American Express
        if ($firstTwoDigits === '34' || $firstTwoDigits === '37') {
            return 'amex';
        }
        
        // Discover
        if ($firstTwoDigits === '65' || 
            $firstFourDigits === '6011' || 
            ($firstTwoDigits >= '64' && $firstTwoDigits <= '65')) {
            return 'discover';
        }
        
        // Diners Club
        if (in_array($firstTwoDigits, ['36', '38', '39']) || 
            ($firstTwoDigits >= '30' && $firstTwoDigits <= '35')) {
            return 'diners';
        }
        
        // JCB
        if ($firstFourDigits >= '3528' && $firstFourDigits <= '3589') {
            return 'jcb';
        }
        
        // UnionPay
        if ($firstTwoDigits === '62') {
            return 'unionpay';
        }
        
        return 'unknown';
    }
    
    /**
     * Generar alias por defecto
     */
    private function generateDefaultAlias(Customer $customer, string $brand): string
    {
        $count = $customer->paymentMethods()->count() + 1;
        
        $brandNames = [
            'visa' => 'Visa',
            'mastercard' => 'Mastercard',
            'amex' => 'American Express',
            'discover' => 'Discover',
            'diners' => 'Diners Club',
            'jcb' => 'JCB',
            'unionpay' => 'UnionPay',
        ];
        
        $brandName = $brandNames[$brand] ?? 'Tarjeta';
        
        if ($count === 1) {
            return "Tarjeta Principal {$brandName}";
        }
        
        return "{$brandName} {$count}";
    }
    
    /**
     * Listar tarjetas de un cliente
     */
    public function listCustomerCards(Customer $customer, bool $activeOnly = true): array
    {
        try {
            $query = $customer->paymentMethods();
            
            if ($activeOnly) {
                $query->active()->verified();
            }
            
            $cards = $query->orderBy('is_default', 'desc')
                ->orderBy('last_used_at', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();
            
            return [
                'success' => true,
                'cards' => $cards,
                'count' => $cards->count(),
                'has_default' => $cards->where('is_default', true)->isNotEmpty(),
            ];
            
        } catch (\Exception $e) {
            Log::error('Error listando tarjetas', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'error' => 'Error al cargar las tarjetas',
                'cards' => collect(),
                'count' => 0,
                'has_default' => false,
            ];
        }
    }
    
    /**
     * Actualizar tarjeta (alias, estado default)
     */
    public function updateCard(CustomerPaymentMethod $paymentMethod, array $data): array
    {
        try {
            $updates = [];
            
            // Actualizar alias
            if (isset($data['alias']) && $data['alias'] !== $paymentMethod->alias) {
                $updates['alias'] = $data['alias'];
            }
            
            // Marcar como predeterminada
            if (isset($data['is_default']) && $data['is_default'] && !$paymentMethod->is_default) {
                $paymentMethod->markAsDefault();
                unset($updates['is_default']); // Ya se maneja en markAsDefault()
            }
            
            // Actualizar otros campos si existen
            if (!empty($updates)) {
                $paymentMethod->update($updates);
            }
            
            Log::info('Tarjeta actualizada', [
                'payment_method_id' => $paymentMethod->id,
                'updates' => $data,
            ]);
            
            return [
                'success' => true,
                'payment_method' => $paymentMethod->fresh(),
                'message' => 'Tarjeta actualizada exitosamente',
            ];
            
        } catch (\Exception $e) {
            Log::error('Error actualizando tarjeta', [
                'payment_method_id' => $paymentMethod->id,
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
            
            return [
                'success' => false,
                'error' => 'No pudimos actualizar la tarjeta',
                'debug_error' => config('app.debug') ? $e->getMessage() : null,
            ];
        }
    }
    
    /**
     * Eliminar/desactivar tarjeta
     */
    public function deleteCard(CustomerPaymentMethod $paymentMethod, bool $softDelete = true): array
    {
        try {
            // TODO: También eliminar en EfevooPay cuando tengan el endpoint
            // Ejemplo: $this->efevooPayService->deletePaymentMethod($paymentMethod->gateway_token);
            
            if ($softDelete) {
                $paymentMethod->deactivate();
                $message = 'Tarjeta desactivada exitosamente';
            } else {
                $paymentMethod->delete();
                $message = 'Tarjeta eliminada exitosamente';
            }
            
            Log::info('Tarjeta eliminada/desactivada', [
                'payment_method_id' => $paymentMethod->id,
                'customer_id' => $paymentMethod->customer_id,
                'soft_delete' => $softDelete,
                'last_four' => $paymentMethod->last_four,
            ]);
            
            return [
                'success' => true,
                'message' => $message,
            ];
            
        } catch (\Exception $e) {
            Log::error('Error eliminando tarjeta', [
                'payment_method_id' => $paymentMethod->id,
                'error' => $e->getMessage(),
                'soft_delete' => $softDelete,
            ]);
            
            return [
                'success' => false,
                'error' => 'No pudimos eliminar la tarjeta',
                'debug_error' => config('app.debug') ? $e->getMessage() : null,
            ];
        }
    }
    
    /**
     * Cobrar usando una tarjeta tokenizada
     */
    public function chargeWithToken(CustomerPaymentMethod $paymentMethod, array $chargeData): array
    {
        // Validar que la tarjeta pueda usarse
        if (!$paymentMethod->can_be_used) {
            $reason = $this->getUnavailabilityReason($paymentMethod);
            
            Log::warning('Intento de uso de tarjeta no usable', [
                'payment_method_id' => $paymentMethod->id,
                'reason' => $reason,
                'charge_data' => $chargeData,
            ]);
            
            return [
                'success' => false,
                'error' => 'La tarjeta no está disponible para uso',
                'reason' => $reason,
            ];
        }
        
        DB::beginTransaction();
        
        try {
            // TODO: Implementar cobro real con EfevooPay usando el gateway_token
            // Ejemplo:
            // $result = $this->efevooPayService->chargeWithToken([
            //     'token' => $paymentMethod->gateway_token,
            //     'amount' => $chargeData['amount'],
            //     'currency' => $chargeData['currency'] ?? 'MXN',
            //     'description' => $chargeData['description'] ?? 'Cargo con tarjeta guardada',
            // ]);
            
            // Por ahora, simulamos el cobro
            $transaction = [
                'id' => 'txn_' . Str::random(10),
                'amount' => $chargeData['amount'],
                'currency' => $chargeData['currency'] ?? 'MXN',
                'status' => 'succeeded',
                'payment_method_id' => $paymentMethod->gateway_payment_method_id,
                'gateway_response' => [
                    'charged' => true,
                    'timestamp' => now()->toISOString(),
                    'simulated' => true,
                ],
            ];
            
            // Marcar como usada
            $paymentMethod->markAsUsed();
            
            // Crear registro de transacción (si tienes modelo Transaction)
            // Transaction::create([...]);
            
            DB::commit();
            
            Log::info('Cobro exitoso con tarjeta tokenizada', [
                'payment_method_id' => $paymentMethod->id,
                'transaction_id' => $transaction['id'],
                'amount' => $chargeData['amount'],
                'currency' => $chargeData['currency'] ?? 'MXN',
            ]);
            
            return [
                'success' => true,
                'transaction' => $transaction,
                'payment_method' => $paymentMethod,
                'message' => 'Pago procesado exitosamente',
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error en cobro con token', [
                'payment_method_id' => $paymentMethod->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'charge_data' => $chargeData,
            ]);
            
            return [
                'success' => false,
                'error' => 'Error procesando el pago',
                'debug_error' => config('app.debug') ? $e->getMessage() : null,
            ];
        }
    }
    
    /**
     * Obtener razón por la que una tarjeta no puede usarse
     */
    private function getUnavailabilityReason(CustomerPaymentMethod $paymentMethod): string
    {
        if (!$paymentMethod->is_active) {
            return 'La tarjeta está desactivada';
        }
        
        if (!$paymentMethod->is_verified) {
            return 'La tarjeta no está verificada';
        }
        
        if ($paymentMethod->is_expired) {
            return 'La tarjeta ha expirado';
        }
        
        return 'Razón desconocida';
    }
    
    /**
     * Verificar tarjeta con cargo de autorización
     */
    public function verifyCardWithCharge(CustomerPaymentMethod $paymentMethod, float $amount = 1.00): array
    {
        try {
            // Realizar cargo de verificación (normalmente se reembolsa)
            $chargeResult = $this->chargeWithToken($paymentMethod, [
                'amount' => $amount,
                'currency' => 'MXN',
                'description' => 'Verificación de tarjeta',
                'verify_only' => true,
            ]);
            
            if ($chargeResult['success']) {
                $paymentMethod->markAsVerified();
                
                Log::info('Tarjeta verificada exitosamente', [
                    'payment_method_id' => $paymentMethod->id,
                    'amount' => $amount,
                ]);
                
                return [
                    'success' => true,
                    'payment_method' => $paymentMethod,
                    'message' => 'Tarjeta verificada exitosamente',
                ];
            }
            
            return [
                'success' => false,
                'error' => $chargeResult['error'] ?? 'Error en verificación',
            ];
            
        } catch (\Exception $e) {
            Log::error('Error verificando tarjeta', [
                'payment_method_id' => $paymentMethod->id,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'error' => 'Error en proceso de verificación',
            ];
        }
    }
    
    /**
     * Buscar tarjeta por token de gateway
     */
    public function findByGatewayToken(string $gatewayToken, string $gateway = 'efevoopay'): ?CustomerPaymentMethod
    {
        return CustomerPaymentMethod::where('gateway', $gateway)
            ->where('gateway_token', $gatewayToken)
            ->first();
    }
}