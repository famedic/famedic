<?php

namespace App\Notifications;

use App\Models\OdessaAfiliateAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OdessaPaymentRefunded extends Notification
{
    use Queueable;

    public function __construct(
        private string $reference_id,
        private string $amount,
        private OdessaAfiliateAccount $odessaAfiliateAccount
    ) {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Solicitud de reembolso - Odessa')
            ->greeting('Hola equipo de Odessa,')
            ->line('Se ha procesado una solicitud de reembolso con los siguientes detalles:')
            ->line('Referencia: **' . $this->reference_id . '**')
            ->line('Monto: **' . $this->amount . '**')
            ->line('Miembro de ODESSA: **' . $this->odessaAfiliateAccount->customer->user->full_name . '**')
            ->line('Identificador ODESSA: **' . $this->odessaAfiliateAccount->odessa_identifier . '**')
            ->line('El reembolso ha sido iniciado en nuestro sistema y requiere su procesamiento correspondiente.')
            ->line('Gracias por su atención a este asunto.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
