<?php

namespace App\Listeners;

use App\Events\PaymentStatusUpdated;
use App\Notifications\PaymentProcessed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SendPaymentNotification implements ShouldQueue
{
    use InteractsWithQueue;
    
    public $queue = 'notifications';
    public $delay = 10;
    
    public function __construct()
    {
        //
    }
    
    public function handle(PaymentStatusUpdated $event): void
    {
        try {
            $transaction = $event->transaction;
            $user = $transaction->customer->user;
            
            // Solo enviar notificaciones para estados finales
            $finalStatuses = ['approved', 'declined', 'expired', 'cancelled'];
            
            if (!in_array($transaction->gateway_status, $finalStatuses)) {
                return;
            }
            
            // Enviar notificación por email
            $user->notify(new PaymentProcessed($transaction));
            
            Log::info('Notificación de pago enviada', [
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'status' => $transaction->gateway_status,
                'email' => $user->email,
            ]);
            
            // También podrías enviar notificación push aquí
            if ($user->pushTokens()->exists()) {
                // \App\Services\PushNotificationService::send(...)
            }
            
        } catch (\Exception $e) {
            Log::error('Error en SendPaymentNotification listener', [
                'transaction_id' => $event->transaction->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    public function failed(PaymentStatusUpdated $event, \Throwable $exception): void
    {
        Log::error('SendPaymentNotification job failed', [
            'transaction_id' => $event->transaction->id ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}