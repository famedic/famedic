<?php

namespace App\Listeners;

use App\Events\PaymentStatusUpdated;
use App\Models\Transaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class UpdateTransactionStatus implements ShouldQueue
{
    use InteractsWithQueue;
    
    public $queue = 'transactions';
    public $delay = 5;
    
    public function __construct()
    {
        //
    }
    
    public function handle(PaymentStatusUpdated $event): void
    {
        try {
            $transaction = $event->transaction;
            
            // Aquí puedes realizar acciones adicionales cuando se actualiza el estado
            // Por ejemplo, enviar correos, notificaciones push, etc.
            
            Log::info('PaymentStatusUpdated event handled', [
                'transaction_id' => $transaction->id,
                'status' => $transaction->gateway_status,
                'event_data' => $event->notificationData,
            ]);
            
            // Si el pago fue aprobado, asegurarse de que la compra asociada esté completa
            if ($transaction->gateway_status === 'approved' && !$transaction->transactionable) {
                // Podrías disparar otro job aquí para crear la compra
                Log::warning('Transacción aprobada sin compra asociada', [
                    'transaction_id' => $transaction->id,
                    'customer_id' => $transaction->customer_id,
                ]);
                
                // \App\Jobs\CreatePurchaseFromTransaction::dispatch($transaction);
            }
            
        } catch (\Exception $e) {
            Log::error('Error en UpdateTransactionStatus listener', [
                'transaction_id' => $event->transaction->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    public function failed(PaymentStatusUpdated $event, \Throwable $exception): void
    {
        Log::error('UpdateTransactionStatus job failed', [
            'transaction_id' => $event->transaction->id ?? null,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}