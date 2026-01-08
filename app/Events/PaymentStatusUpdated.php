<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $transaction;
    public $notificationData;
    
    public function __construct(Transaction $transaction, array $notificationData = [])
    {
        $this->transaction = $transaction;
        $this->notificationData = $notificationData;
    }
    
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('payment.' . $this->transaction->id),
            new PrivateChannel('user.' . $this->transaction->customer->user_id),
        ];
    }
    
    public function broadcastAs(): string
    {
        return 'PaymentStatusUpdated';
    }
    
    public function broadcastWith(): array
    {
        return [
            'transaction_id' => $this->transaction->id,
            'status' => $this->transaction->gateway_status,
            'internal_status' => $this->transaction->status,
            'amount' => $this->transaction->amount_cents / 100,
            'currency' => $this->transaction->currency,
            'timestamp' => now()->toISOString(),
            'notification' => $this->notificationData,
            'message' => $this->getStatusMessage($this->transaction->gateway_status),
        ];
    }
    
    private function getStatusMessage(string $status): string
    {
        return match(strtolower($status)) {
            'approved' => '¡Pago aprobado exitosamente!',
            'declined' => 'El pago fue rechazado.',
            'pending' => 'El pago está siendo procesado.',
            'expired' => 'El tiempo para completar el pago ha expirado.',
            'cancelled' => 'El pago ha sido cancelado.',
            default => 'Estado del pago actualizado.',
        };
    }
}