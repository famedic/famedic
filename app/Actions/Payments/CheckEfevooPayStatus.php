<?php

namespace App\Actions\Payments;

use App\Models\Transaction;
use App\Services\EfevooPayService;
use App\Services\WebSocketService;
use App\Jobs\MonitorEfevooPayment;
use Illuminate\Support\Facades\Log;

class CheckEfevooPayStatus
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
    
    public function __invoke(Transaction $transaction): array
    {
        // Si ya está procesado, retornar estado actual
        if ($transaction->gateway_processed_at) {
            return [
                'success' => true,
                'payment_status' => $transaction->gateway_status,
                'status' => $transaction->status,
                'is_final' => true,
                'message' => 'Pago ya procesado',
            ];
        }
        
        // Si ha expirado (más de 1 hora)
        if ($transaction->created_at->diffInMinutes(now()) > 60) {
            $transaction->update([
                'status' => 'expired',
                'gateway_status' => 'expired',
                'gateway_processed_at' => now(),
            ]);
            
            return [
                'success' => true,
                'payment_status' => 'expired',
                'status' => 'expired',
                'is_final' => true,
                'message' => 'El pago ha expirado',
            ];
        }
        
        // Si no hay token, no podemos verificar
        if (!$transaction->gateway_token) {
            Log::warning('Transaction sin gateway_token', [
                'transaction_id' => $transaction->id,
            ]);
            
            return [
                'success' => false,
                'payment_status' => 'error',
                'message' => 'Error en la transacción',
            ];
        }
        
        try {
            // Consultar estado a EfevooPay
            $status = $this->efevooPayService->checkStatus($transaction->gateway_token);
            
            Log::debug('Estado consultado a EfevooPay', [
                'transaction_id' => $transaction->id,
                'status_response' => $status,
            ]);
            
            if ($status['status'] === 'success' && $status['payment_status']) {
                $newStatus = $this->mapStatus($status['payment_status']);
                $isFinal = in_array($status['payment_status'], ['approved', 'declined', 'expired']);
                
                // Solo actualizar si el estado cambió
                if ($transaction->gateway_status !== $status['payment_status']) {
                    $transaction->update([
                        'gateway_status' => $status['payment_status'],
                        'status' => $newStatus,
                        'gateway_response' => array_merge(
                            $transaction->gateway_response ?? [],
                            ['status_checks' => [
                                'last_check' => now()->toISOString(),
                                'status' => $status['payment_status'],
                            ]]
                        ),
                        'gateway_processed_at' => $isFinal ? now() : null,
                    ]);
                    
                    // Notificar cambio de estado
                    if ($isFinal) {
                        $this->webSocketService->notifyPaymentStatus($transaction, [
                            'payment_status' => $status['payment_status'],
                            'amount' => $transaction->amount_cents / 100,
                            'currency' => 'MXN',
                            'date' => now()->toISOString(),
                            'event_type' => 'status_checked',
                        ]);
                    }
                }
                
                // Si aún está pendiente, programar nueva verificación
                if (!$isFinal) {
                    MonitorEfevooPayment::dispatch($transaction)
                        ->delay(now()->addSeconds(30))
                        ->onQueue('payments');
                }
                
                return [
                    'success' => true,
                    'payment_status' => $status['payment_status'],
                    'status' => $newStatus,
                    'is_final' => $isFinal,
                    'message' => $status['message'],
                    'raw_response' => $status['raw_data'] ?? null,
                ];
            }
            
            // Si hay error en la consulta, programar reintento
            MonitorEfevooPayment::dispatch($transaction)
                ->delay(now()->addSeconds(60))
                ->onQueue('payments');
            
            return [
                'success' => false,
                'payment_status' => 'pending',
                'message' => 'Error consultando estado, reintentando...',
            ];
            
        } catch (\Exception $e) {
            Log::error('Error checking EfevooPay status', [
                'transaction_id' => $transaction->id,
                'gateway_token' => $transaction->gateway_token,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Programar reintento en caso de error
            MonitorEfevooPayment::dispatch($transaction)
                ->delay(now()->addSeconds(120))
                ->onQueue('payments');
            
            return [
                'success' => false,
                'payment_status' => 'pending',
                'message' => 'Error temporal, reintentando...',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ];
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
}