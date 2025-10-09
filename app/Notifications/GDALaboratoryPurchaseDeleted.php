<?php

namespace App\Notifications;

use App\Models\LaboratoryPurchase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GDALaboratoryPurchaseDeleted extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private LaboratoryPurchase $laboratoryPurchase
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
            ->subject('Cancelación de orden de laboratorio - ' . $this->laboratoryPurchase->gda_order_id)
            ->greeting('Hola equipo de GDA,')
            ->line('Se ha procesado la cancelación de una orden de laboratorio con los siguientes detalles:')
            ->line('Folio: **' . $this->laboratoryPurchase->gda_order_id . '**')
            ->line('Paciente: **' . $this->laboratoryPurchase->full_name . '**')
            ->line('Total: **' . $this->laboratoryPurchase->formatted_total . '**')
            ->line('Se ha notificado al cliente sobre la cancelación y se ha iniciado el proceso de reembolso por el monto total de la compra.')
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
