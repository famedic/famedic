<?php

namespace App\Http\Controllers\EfevooPay;

use App\Actions\Payments\CreateEfevooPayOrder;
use App\Actions\Payments\CheckEfevooPayStatus;
use App\Http\Requests\CreateEfevooPayOrderRequest;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class EfevooPayController extends Controller
{
    /**
     * Crear checkout de EfevooPay
     */
    public function createCheckout(CreateEfevooPayOrderRequest $request, CreateEfevooPayOrder $createOrder)
    {
        try {
            $user = $request->user();
            $customer = $user->customer;
            
            // Obtener items del carrito
            $cartItems = $customer->laboratoryCartItems()
                ->with('laboratoryTest')
                ->get()
                ->map(function ($item) {
                    return [
                        'name' => $item->laboratoryTest->name,
                        'price_cents' => $item->laboratoryTest->famedic_price_cents,
                        'quantity' => 1,
                        'gda_id' => $item->laboratoryTest->gda_id,
                    ];
                })->toArray();
            
            if (empty($cartItems)) {
                return redirect()->back()
                    ->withErrors(['cart' => 'El carrito está vacío']);
            }
            
            $totalCents = array_sum(array_column($cartItems, 'price_cents'));
            
            $result = $createOrder(
                customer: $customer,
                amountCents: $totalCents,
                cartItems: $cartItems,
                metadata: [
                    'laboratory_brand' => $request->laboratory_brand,
                    'address_id' => $request->address,
                    'contact_id' => $request->contact,
                    'user_id' => $user->id,
                ]
            );
            
            if (!$result['success']) {
                return redirect()->back()
                    ->withErrors(['payment_method' => $result['error']]);
            }
            
            // Guardar en sesión para redirección
            session()->put('efevoopay_transaction_id', $result['transaction']->id);
            session()->put('efevoopay_checkout_url', $result['checkout_url']);
            
            Log::info('Checkout EfevooPay creado', [
                'transaction_id' => $result['transaction']->id,
                'user_id' => $user->id,
            ]);
            
            return Inertia::render('Checkout/EfevooPay', [
                'checkoutUrl' => $result['checkout_url'],
                'transactionId' => $result['transaction']->id,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error creando checkout EfevooPay', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return redirect()->back()
                ->withErrors(['payment_method' => 'Error al procesar el pago. Por favor intenta nuevamente.']);
        }
    }
    
    /**
     * Callback después del checkout
     */
    public function callback(Request $request, CheckEfevooPayStatus $checkStatus)
    {
        try {
            $transactionId = session()->get('efevoopay_transaction_id');
            
            if (!$transactionId) {
                return redirect()->route('dashboard')
                    ->withErrors(['error' => 'Sesión expirada. Por favor inicia el proceso nuevamente.']);
            }
            
            $transaction = Transaction::findOrFail($transactionId);
            
            // Verificar que la transacción pertenezca al usuario actual
            if ($transaction->customer_id !== $request->user()->customer->id) {
                return redirect()->route('dashboard')
                    ->withErrors(['error' => 'Acceso no autorizado.']);
            }
            
            $status = $checkStatus($transaction);
            
            if ($status['success'] && $status['payment_status'] === 'approved') {
                // Limpiar sesión
                session()->forget(['efevoopay_transaction_id', 'efevoopay_checkout_url']);
                
                // Obtener la compra asociada
                $laboratoryPurchase = $transaction->transactionable;
                
                if (!$laboratoryPurchase) {
                    return redirect()->route('dashboard')
                        ->with('success', 'Pago procesado correctamente. Tu pedido está siendo procesado.');
                }
                
                return redirect()->route('laboratory-purchases.show', [
                    'laboratory_purchase' => $laboratoryPurchase,
                ])->with('success', '¡Pago procesado correctamente!');
                
            } elseif ($status['success'] && $status['payment_status'] === 'declined') {
                return redirect()->route('checkout')
                    ->withErrors(['payment' => 'El pago fue rechazado. Por favor intenta con otro método.']);
                    
            } else {
                // Pago aún pendiente
                return Inertia::render('Checkout/PaymentStatus', [
                    'transactionId' => $transaction->id,
                    'status' => $status['payment_status'] ?? 'pending',
                    'message' => 'Estamos verificando el estado de tu pago...',
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error('Error en callback EfevooPay', [
                'transaction_id' => $transactionId ?? null,
                'error' => $e->getMessage(),
            ]);
            
            return redirect()->route('dashboard')
                ->withErrors(['error' => 'Error al procesar la respuesta del pago. Por favor contacta a soporte.']);
        }
    }
    
    /**
     * Webhook para notificaciones de EfevooPay
     */
    public function webhook(Request $request)
    {
        Log::info('Webhook EfevooPay recibido', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'payload' => $request->all(),
        ]);
        
        // Validar que la petición viene de EfevooPay (si tienen IPs fijas)
        // $allowedIps = ['x.x.x.x', 'y.y.y.y'];
        // if (!in_array($request->ip(), $allowedIps)) {
        //     Log::warning('Intento de webhook desde IP no autorizada', [
        //         'ip' => $request->ip(),
        //     ]);
        //     abort(403);
        // }
        
        $payload = $request->all();
        
        // Procesar la notificación
        try {
            $webSocketService = app(\App\Services\WebSocketService::class);
            $webSocketService->processEfevooPayNotification($payload);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Notificación procesada',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error procesando webhook EfevooPay', [
                'payload' => $payload,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error procesando notificación',
            ], 500);
        }
    }
    
    /**
     * Verificar estado de una transacción (API)
     */
    public function checkTransactionStatus(Request $request, $transactionId)
    {
        $transaction = Transaction::findOrFail($transactionId);
        
        // Verificar autorización
        if ($transaction->customer_id !== $request->user()->customer->id) {
            abort(403);
        }
        
        $checkStatus = app(CheckEfevooPayStatus::class);
        $status = $checkStatus($transaction);
        
        return response()->json([
            'transaction_id' => $transaction->id,
            'status' => $status['payment_status'] ?? 'pending',
            'gateway_status' => $transaction->gateway_status,
            'is_final' => in_array($status['payment_status'] ?? '', ['approved', 'declined', 'expired']),
            'timestamp' => now()->toISOString(),
        ]);
    }
}