<?php

namespace App\Actions\Payments;

use App\Models\Customer;
use App\Models\Transaction;
use App\Services\EfevooPayService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateEfevooPayOrder
{
    private EfevooPayService $efevooPayService;
    
    public function __construct(EfevooPayService $efevooPayService)
    {
        $this->efevooPayService = $efevooPayService;
    }
    
    public function __invoke(
        Customer $customer,
        int $amountCents,
        array $cartItems,
        array $metadata = []
    ): array
    {
        DB::beginTransaction();
        
        try {
            // 1. Crear transacción pendiente
            $transaction = Transaction::create([
                'customer_id' => $customer->id,
                'amount_cents' => $amountCents,
                'currency' => 'MXN',
                'status' => 'pending',
                'gateway' => 'efevoopay',
                'gateway_status' => 'pending',
                'metadata' => $metadata,
                'description' => 'Compra de productos de laboratorio',
            ]);
            
            Log::info('Transacción EfevooPay creada', [
                'transaction_id' => $transaction->id,
                'customer_id' => $customer->id,
                'amount_cents' => $amountCents,
            ]);
            
            // 2. Preparar items para EfevooPay
            $efevooItems = [];
            $subtotal = 0;
            
            foreach ($cartItems as $item) {
                $itemPrice = $item['price_cents'] / 100; // Convertir a unidades
                $itemTotal = $itemPrice * ($item['quantity'] ?? 1);
                
                $efevooItems[] = [
                    'item' => $item['name'] ?? 'Producto',
                    'cant' => $item['quantity'] ?? 1,
                    'price' => $itemTotal,
                    'item_price' => $itemPrice,
                ];
                
                $subtotal += $itemTotal;
            }
            
            // 3. Crear orden en EfevooPay
            $efevooPayOrder = $this->efevooPayService->createOrder([
                'description' => 'Compra de productos de laboratorio',
                'items' => $efevooItems,
                'subtotal' => $subtotal,
                'total' => $amountCents / 100,
                'order_details' => array_merge($metadata, [
                    'transaction_id' => $transaction->id,
                    'customer_id' => $customer->id,
                    'customer_email' => $customer->user->email,
                    'purchase_type' => 'laboratory',
                    'items_count' => count($cartItems),
                ]),
            ]);
            
            // 4. Actualizar transacción con datos de EfevooPay
            $transaction->update([
                'gateway_token' => $efevooPayOrder['token'],
                'gateway_transaction_id' => $efevooPayOrder['token'], // Usamos el token como ID
                'gateway_response' => [
                    'checkout_url' => $efevooPayOrder['checkout_url'],
                    'token' => $efevooPayOrder['token'],
                    'mode' => $efevooPayOrder['mode'],
                    'created_at' => now()->toISOString(),
                ],
            ]);
            
            DB::commit();
            
            Log::info('Orden EfevooPay creada exitosamente', [
                'transaction_id' => $transaction->id,
                'efevoopay_token' => $efevooPayOrder['token'],
                'checkout_url' => $efevooPayOrder['checkout_url'],
            ]);
            
            return [
                'transaction' => $transaction->fresh(),
                'checkout_url' => $efevooPayOrder['checkout_url'],
                'token' => $efevooPayOrder['token'],
                'success' => true,
                'message' => 'Orden creada exitosamente',
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error creating EfevooPay order', [
                'customer_id' => $customer->id,
                'amount_cents' => $amountCents,
                'cart_items_count' => count($cartItems),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Intentar determinar el tipo de error
            $errorMessage = 'No pudimos procesar tu pago. Por favor intenta nuevamente.';
            
            if (str_contains($e->getMessage(), 'comunicación')) {
                $errorMessage = 'Error de comunicación con el procesador de pagos. Por favor intenta en unos minutos.';
            } elseif (str_contains($e->getMessage(), 'token')) {
                $errorMessage = 'Error al generar la orden de pago. Por favor verifica la información e intenta nuevamente.';
            }
            
            return [
                'success' => false,
                'error' => $errorMessage,
                'debug_error' => config('app.debug') ? $e->getMessage() : null,
                'transaction' => null,
            ];
        }
    }
    
    /**
     * Método auxiliar para calcular totales
     */
    public function calculateTotals(array $cartItems): array
    {
        $subtotal = 0;
        $items = [];
        
        foreach ($cartItems as $item) {
            $itemPrice = $item['price_cents'] / 100;
            $itemTotal = $itemPrice * ($item['quantity'] ?? 1);
            
            $items[] = [
                'name' => $item['name'],
                'quantity' => $item['quantity'] ?? 1,
                'unit_price' => $itemPrice,
                'total' => $itemTotal,
            ];
            
            $subtotal += $itemTotal;
        }
        
        return [
            'subtotal' => $subtotal,
            'total' => $subtotal, // Por defecto sin impuestos adicionales
            'items' => $items,
            'items_count' => count($items),
        ];
    }
}