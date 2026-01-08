<?php

namespace App\Notifications;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class PaymentProcessed extends Notification
{
    use Queueable;

    private Transaction $transaction;
    
    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
    }
    
    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }
    
    public function toMail($notifiable): MailMessage
    {
        $status = $this->transaction->gateway_status;
        $amount = number_format($this->transaction->amount_cents / 100, 2);
        
        $subject = match($status) {
            'approved' => "✅ Pago aprobado - \${$amount} MXN",
            'declined' => "❌ Pago rechazado - \${$amount} MXN",
            'expired' => "⏰ Pago expirado - \${$amount} MXN",
            default => "📄 Estado de pago actualizado - \${$amount} MXN",
        };
        
        $message = (new MailMessage)
            ->subject($subject)
            ->greeting('Hola ' . $notifiable->name . ',');
        
        if ($status === 'approved') {
            $message->line('¡Excelente noticia! Tu pago ha sido aprobado exitosamente.')
                ->line("**Monto:** \${$amount} MXN")
                ->line("**Referencia:** {$this->transaction->id}")
                ->line("**Fecha:** " . $this->transaction->updated_at->format('d/m/Y H:i'))
                ->action('Ver detalles del pedido', $this->getOrderUrl())
                ->line('Gracias por tu compra.');
        } elseif ($status === 'declined') {
            $message->line('Lamentamos informarte que tu pago ha sido rechazado.')
                ->line("**Monto:** \${$amount} MXN")
                ->line("**Referencia:** {$this->transaction->id}")
                ->line('**Posibles causas:**')
                ->line('- Fondos insuficientes')
                ->line('- Tarjeta bloqueada o restringida')
                ->line('- Datos incorrectos')
                ->line('- Límite de la tarjeta excedido')
                ->action('Intentar con otro método de pago', $this->getCheckoutUrl())
                ->line('Si crees que esto es un error, por favor contacta a tu banco.');
        } else {
            $message->line('El estado de tu pago ha sido actualizado.')
                ->line("**Estado:** " . $this->getStatusText($status))
                ->line("**Monto:** \${$amount} MXN")
                ->line("**Referencia:** {$this->transaction->id}")
                ->action('Ver detalles', $this->getOrderUrl());
        }
        
        return $message;
    }
    
    public function toArray($notifiable): array
    {
        $status = $this->transaction->gateway_status;
        $amount = $this->transaction->amount_cents / 100;
        
        return [
            'transaction_id' => $this->transaction->id,
            'amount' => $amount,
            'currency' => $this->transaction->currency,
            'status' => $status,
            'status_text' => $this->getStatusText($status),
            'message' => $this->getNotificationMessage($status, $amount),
            'order_url' => $this->getOrderUrl(),
            'timestamp' => now()->toISOString(),
        ];
    }
    
    private function getStatusText(string $status): string
    {
        return match($status) {
            'approved' => 'Aprobado',
            'declined' => 'Rechazado',
            'pending' => 'Pendiente',
            'expired' => 'Expirado',
            'cancelled' => 'Cancelado',
            default => 'Desconocido',
        };
    }
    
    private function getNotificationMessage(string $status, float $amount): string
    {
        $formattedAmount = number_format($amount, 2);
        
        return match($status) {
            'approved' => "Pago de \${$formattedAmount} MXN aprobado exitosamente.",
            'declined' => "Pago de \${$formattedAmount} MXN rechazado.",
            'expired' => "El tiempo para completar el pago de \${$formattedAmount} MXN ha expirado.",
            'cancelled' => "Pago de \${$formattedAmount} MXN cancelado.",
            default => "Estado del pago de \${$formattedAmount} MXN actualizado a: " . $this->getStatusText($status),
        };
    }
    
    private function getOrderUrl(): string
    {
        // Ajusta según tu estructura de rutas
        if ($this->transaction->transactionable_type === 'App\Models\LaboratoryPurchase') {
            return route('laboratory-purchases.show', [
                'laboratory_purchase' => $this->transaction->transactionable_id,
            ]);
        }
        
        return route('dashboard');
    }
    
    private function getCheckoutUrl(): string
    {
        return route('checkout');
    }
}