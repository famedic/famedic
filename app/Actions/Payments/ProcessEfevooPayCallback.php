<?php

namespace App\Actions\Payments;

use App\Models\Transaction;
use App\Services\EfevooPayService;
use App\Services\WebSocketService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessEfevooPayCallback
{
    private EfevooPayService $efevooPayService;
    private WebSocketService $webSocketService;
    
    public function __construct(
        EfevooPayService $efevooPayService,
        WebSocketService $webSocketService
    ) {
        $this->efevooPayService = $efevooPayService;
        $this->webSocketService = $webSocketService;
    }
    
    public function __invoke(array $notificationData, ?string $transactionId = null): array
    {
        DB::beginTransaction();
        
        try {
            // Procesar la notificación
            $processed = $this->efevooPayService->processNotification($notificationData);
            
            Log::info('Procesando callback EfevooPay', [
                'notification' => $processed,
                'transaction_id' => $transactionId,
            ]);
            
            // Buscar transacción por token o ID
            $transaction = null;
            
            if ($processed['order_token']) {
                $transaction = Transaction::where('gateway_token', $processed['order_token'])
                    ->first();
            }
            
            if (!$transaction && $transactionId) {
                $transaction = Transaction::find($transactionId);
            }
            
            if (!$transaction) {
                Log::warning('Transacción no encontrada para callback', [
                    'order_token' => $processed['order_token'],
                    'transaction_id' => $transactionId,
                ]);
                
                DB::rollBack();
                
                return [
                    'success' => false,
                    'error' => 'Transacción no encontrada',
                    'processed_data' => $processed,
                ];
            }
            
            // Verificar que no esté ya procesada
            if ($transaction->gateway_processed_at) {
                Log::info('Transacción ya procesada, ignorando callback', [
                    'transaction_id' => $transaction->id,
                    'current_status' => $transaction->gateway_status,
                ]);
                
                DB::rollBack();
                
                return [
                    'success' => true,
                    'message' => 'Transacción ya procesada',
                    'transaction_id' => $transaction->id,
                    'status' => $transaction->gateway_status,
                ];
            }
            
            // Actualizar transacción
            $newStatus = $this->mapStatus($processed['payment_status']);
            $isFinal = $this->isFinalStatus($processed['payment_status']);
            
            $transaction->update([
                'gateway_status' => $processed['payment_status'],
                'status' => $newStatus,
                'gateway_response' => array_merge(
                    $transaction->gateway_response ?? [],
                    [
                        'callback_received' => [
                            'data' => $processed,
                            'received_at' => now()->toISOString(),
                            'notification_raw' => $notificationData,
                        ]
                    ]
                ),
                'gateway_processed_at' => $isFinal ? now() : null,
            ]);
            
            // Si es un estado final, procesar la compra asociada
            if ($isFinal && $processed['payment_status'] === 'approved') {
                $this->processApprovedPayment($transaction);
            }
            
            // Notificar al frontend
            $this->webSocketService->notifyPaymentStatus($transaction, $processed);
            
            DB::commit();
            
            Log::info('Callback procesado exitosamente', [
                'transaction_id' => $transaction->id,
                'new_status' => $processed['payment_status'],
                'is_final' => $isFinal,
            ]);
            
            return [
                'success' => true,
                'transaction_id' => $transaction->id,
                'payment_status' => $processed['payment_status'],
                'status' => $newStatus,
                'is_final' => $isFinal,
                'message' => 'Callback procesado exitosamente',
                'processed_data' => $processed,
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error procesando callback EfevooPay', [
                'notification_data' => $notificationData,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return [
                'success' => false,
                'error' => 'Error procesando callback: ' . $e->getMessage(),
                'debug_error' => config('app.debug') ? $e->getTraceAsString() : null,
            ];
        }
    }
    
    /**
     * Procesar pago aprobado
     */
    private function processApprovedPayment(Transaction $transaction): void
    {
        try {
            // Aquí iría la lógica para completar la compra asociada
            // Por ejemplo, crear el LaboratoryPurchase si no existe
            
            if ($transaction->transactionable) {
                Log::info('Transacción ya tiene compra asociada', [
                    'transaction_id' => $transaction->id,
                    'purchase_id' => $transaction->transactionable_id,
                    'purchase_type' => $transaction->transactionable_type,
                ]);
                return;
            }
            
            // Si necesitas crear la compra aquí, necesitarías acceso a los datos del carrito
            // Normalmente esto se hace en el OrderAction, pero si el pago es asíncrono,
            // podrías necesitar crear la compra aquí
            
            Log::info('Pago aprobado, pendiente de procesar compra', [
                'transaction_id' => $transaction->id,
                'customer_id' => $transaction->customer_id,
            ]);
            
            // Podrías disparar un evento o job aquí
            event(new \App\Events\PaymentApproved($transaction));
            
        } catch (\Exception $e) {
            Log::error('Error procesando pago aprobado', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Mapear estado de EfevooPay a estado interno
     */
    private function mapStatus(string $efevooStatus): string
    {
        return match(strtolower($efevooStatus)) {
            'approved' => 'completed',
            'declined', 'rejected' => 'failed',
            'pending', 'in_process' => 'pending',
            'expired' => 'expired',
            'cancelled' => 'cancelled',
            default => 'pending',
        };
    }
    
    /**
     * Verificar si un estado es final
     */
    private function isFinalStatus(string $status): bool
    {
        return in_array(strtolower($status), [
            'approved', 'declined', 'expired', 'cancelled',
        ]);
    }
    
    /**
     * Validar firma de notificación (si EfevooPay la proporciona)
     */
    public function validateNotificationSignature(array $notification, string $secret): bool
    {
        // Implementar validación de firma si EfevooPay la proporciona
        // Por ahora retornamos true
        return true;
    }
}