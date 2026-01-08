<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\EfevooPayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MonitorEfevooPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $maxExceptions = 3;
    public $timeout = 60;
    public $backoff = [30, 60, 120, 300, 600]; // Segundos entre reintentos
    
    private Transaction $transaction;
    
    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction->withoutRelations();
    }
    
    public function handle(EfevooPayService $efevooPayService): void
    {
        // Recargar la transacción para tener datos frescos
        $transaction = Transaction::find($this->transaction->id);
        
        if (!$transaction) {
            Log::warning('Transaction no encontrada en job', [
                'transaction_id' => $this->transaction->id,
            ]);
            return;
        }
        
        // Si ya está procesada, no hacer nada
        if ($transaction->gateway_processed_at) {
            Log::debug('Transaction ya procesada, ignorando job', [
                'transaction_id' => $transaction->id,
                'processed_at' => $transaction->gateway_processed_at,
            ]);
            return;
        }
        
        // Si ha expirado (más de 2 horas)
        if ($transaction->created_at->diffInMinutes(now()) > 120) {
            Log::info('Transaction expirada por tiempo', [
                'transaction_id' => $transaction->id,
                'created_at' => $transaction->created_at,
                'minutes_elapsed' => $transaction->created_at->diffInMinutes(now()),
            ]);
            
            $transaction->update([
                'status' => 'expired',
                'gateway_status' => 'expired',
                'gateway_processed_at' => now(),
            ]);
            
            return;
        }
        
        // Si no tiene token, no podemos verificar
        if (!$transaction->gateway_token) {
            Log::warning('Transaction sin gateway_token en job', [
                'transaction_id' => $transaction->id,
            ]);
            
            $this->release(300); // Reintentar en 5 minutos
            return;
        }
        
        try {
            Log::debug('Consultando estado de pago', [
                'transaction_id' => $transaction->id,
                'gateway_token' => $transaction->gateway_token,
                'attempt' => $this->attempts(),
            ]);
            
            $status = $efevooPayService->checkStatus($transaction->gateway_token);
            
            Log::debug('Respuesta de estado recibida', [
                'transaction_id' => $transaction->id,
                'status_response' => $status,
            ]);
            
            if ($status['status'] === 'success' && $status['payment_status']) {
                $newStatus = $this->mapStatus($status['payment_status']);
                $isFinal = $this->isFinalStatus($status['payment_status']);
                
                // Solo actualizar si el estado cambió
                if ($transaction->gateway_status !== $status['payment_status']) {
                    $transaction->update([
                        'gateway_status' => $status['payment_status'],
                        'status' => $newStatus,
                        'gateway_response' => array_merge(
                            $transaction->gateway_response ?? [],
                            [
                                'monitor_checks' => [
                                    'check_' . $this->attempts() => [
                                        'timestamp' => now()->toISOString(),
                                        'status' => $status['payment_status'],
                                        'attempt' => $this->attempts(),
                                    ]
                                ]
                            ]
                        ),
                        'gateway_processed_at' => $isFinal ? now() : null,
                    ]);
                    
                    Log::info('Estado de transacción actualizado', [
                        'transaction_id' => $transaction->id,
                        'old_status' => $this->transaction->gateway_status,
                        'new_status' => $status['payment_status'],
                        'is_final' => $isFinal,
                    ]);
                    
                    // Disparar evento si es final
                    if ($isFinal) {
                        event(new \App\Events\PaymentStatusUpdated($transaction, [
                            'monitor_check' => true,
                            'attempt' => $this->attempts(),
                        ]));
                    }
                }
                
                // Si aún está pendiente, reprogramar
                if (!$isFinal) {
                    // Calcular delay basado en el tiempo desde creación
                    $minutesElapsed = $transaction->created_at->diffInMinutes(now());
                    
                    if ($minutesElapsed < 10) {
                        $nextDelay = 30; // 30 segundos para primeros 10 minutos
                    } elseif ($minutesElapsed < 30) {
                        $nextDelay = 60; // 1 minuto para primeros 30 minutos
                    } elseif ($minutesElapsed < 60) {
                        $nextDelay = 300; // 5 minutos para primeros 60 minutos
                    } else {
                        $nextDelay = 600; // 10 minutos después de 1 hora
                    }
                    
                    self::dispatch($transaction)
                        ->delay(now()->addSeconds($nextDelay))
                        ->onQueue('payments');
                }
                
            } else {
                // Error en la consulta, reintentar
                Log::warning('Error en consulta de estado', [
                    'transaction_id' => $transaction->id,
                    'status_response' => $status,
                ]);
                
                $this->release(60); // Reintentar en 1 minuto
            }
            
        } catch (\Exception $e) {
            Log::error('Error en MonitorEfevooPayment job', [
                'transaction_id' => $transaction->id,
                'gateway_token' => $transaction->gateway_token,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);
            
            // Reintentar con backoff
            throw $e;
        }
    }
    
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
    
    private function isFinalStatus(string $status): bool
    {
        return in_array(strtolower($status), [
            'approved', 'declined', 'expired', 'cancelled',
        ]);
    }
    
    public function failed(\Throwable $exception): void
    {
        Log::error('MonitorEfevooPayment job failed permanently', [
            'transaction_id' => $this->transaction->id,
            'gateway_token' => $this->transaction->gateway_token,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
        
        // Podrías notificar a un administrador aquí
        // Notification::send($adminUsers, new JobFailedNotification($this, $exception));
    }
}