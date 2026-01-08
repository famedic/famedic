<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;
use Pusher\PusherException;

class WebSocketService
{
    private Pusher $pusher;
    private EfevooPayService $efevooPayService;
    
    public function __construct(EfevooPayService $efevooPayService)
    {
        $this->efevooPayService = $efevooPayService;
        
        try {
            $this->pusher = new Pusher(
                config('broadcasting.connections.pusher.key'),
                config('broadcasting.connections.pusher.secret'),
                config('broadcasting.connections.pusher.app_id'),
                config('broadcasting.connections.pusher.options')
            );
        } catch (PusherException $e) {
            Log::error('Error inicializando Pusher', [
                'error' => $e->getMessage(),
            ]);
            
            // Crear un pusher falso para desarrollo
            $this->pusher = new class {
                public function trigger($channel, $event, $data) {
                    Log::info('WebSocket (simulado):', [
                        'channel' => $channel,
                        'event' => $event,
                        'data' => $data,
                    ]);
                    return true;
                }
            };
        }
    }
    
    /**
     * Procesar notificación de EfevooPay
     */
    public function processEfevooPayNotification(array $notification): void
    {
        try {
            $processed = $this->efevooPayService->processNotification($notification);
            
            Log::info('Procesando notificación EfevooPay', [
                'processed_data' => $processed,
            ]);
            
            // Buscar transacción por token
            $transaction = Transaction::where('gateway_token', $processed['order_token'])
                ->with(['customer.user'])
                ->first();
            
            if (!$transaction) {
                Log::warning('Transacción no encontrada para notificación EfevooPay', [
                    'token' => $processed['order_token'],
                ]);
                return;
            }
            
            // Actualizar transacción
            $transaction->update([
                'gateway_status' => $processed['payment_status'],
                'status' => $this->mapPaymentStatus($processed['payment_status']),
                'gateway_response' => array_merge(
                    $transaction->gateway_response ?? [],
                    ['websocket_notification' => $processed]
                ),
                'gateway_processed_at' => now(),
            ]);
            
            // Notificar al frontend
            $this->notifyPaymentStatus($transaction, $processed);
            
            // Disparar evento interno
            event(new \App\Events\PaymentStatusUpdated($transaction, $processed));
            
        } catch (\Exception $e) {
            Log::error('Error procesando notificación EfevooPay', [
                'notification' => $notification,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
    
    /**
     * Notificar cambio de estado al frontend
     */
    public function notifyPaymentStatus(Transaction $transaction, array $data): void
    {
        $channel = 'private-payment.' . $transaction->id;
        
        $payload = [
            'transaction_id' => $transaction->id,
            'status' => $data['payment_status'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'date' => $data['date'],
            'timestamp' => now()->toISOString(),
            'event_type' => $data['event_type'],
        ];
        
        try {
            $this->pusher->trigger($channel, 'PaymentStatusUpdated', $payload);
            
            Log::info('Notificación WebSocket enviada', [
                'channel' => $channel,
                'transaction_id' => $transaction->id,
                'status' => $data['payment_status'],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error enviando notificación WebSocket', [
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
        }
        
        // También notificar al canal del usuario
        if ($transaction->customer && $transaction->customer->user) {
            $userChannel = 'private-user.' . $transaction->customer->user->id;
            $userPayload = [
                'message' => $this->getStatusMessage($data['payment_status']),
                'transaction_id' => $transaction->id,
                'status' => $data['payment_status'],
                'amount' => $data['amount'],
                'currency' => $data['currency'],
            ];
            
            try {
                $this->pusher->trigger($userChannel, 'PaymentNotification', $userPayload);
            } catch (\Exception $e) {
                Log::warning('Error notificando al usuario', [
                    'user_id' => $transaction->customer->user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
    
    /**
     * Enviar mensaje a un canal específico
     */
    public function sendToChannel(string $channel, string $event, array $data): bool
    {
        try {
            $this->pusher->trigger($channel, $event, $data);
            return true;
        } catch (\Exception $e) {
            Log::error('Error enviando a canal WebSocket', [
                'channel' => $channel,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    /**
     * Mapear estado de EfevooPay a estado interno
     */
    private function mapPaymentStatus(string $efevooStatus): string
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
     * Obtener mensaje amigable para el usuario
     */
    private function getStatusMessage(string $status): string
    {
        return match(strtolower($status)) {
            'approved' => '¡Tu pago ha sido aprobado exitosamente!',
            'declined' => 'Tu pago ha sido rechazado. Por favor intenta con otro método.',
            'pending' => 'Tu pago está siendo procesado. Te notificaremos cuando se complete.',
            'expired' => 'El tiempo para completar el pago ha expirado.',
            default => 'Estado de pago actualizado.',
        };
    }
}