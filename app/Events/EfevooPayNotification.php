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

class EfevooPayNotification implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $type;
    public $data;
    public $userId;
    
    public function __construct(string $type, array $data, int $userId = null)
    {
        $this->type = $type;
        $this->data = $data;
        $this->userId = $userId;
    }
    
    public function broadcastOn(): array
    {
        $channels = [];
        
        if ($this->userId) {
            $channels[] = new PrivateChannel('user.' . $this->userId);
        }
        
        // Canal general para admin/soporte
        $channels[] = new PrivateChannel('efevoopay.notifications');
        
        return $channels;
    }
    
    public function broadcastAs(): string
    {
        return 'EfevooPayNotification';
    }
    
    public function broadcastWith(): array
    {
        return [
            'type' => $this->type,
            'data' => $this->data,
            'timestamp' => now()->toISOString(),
        ];
    }
}