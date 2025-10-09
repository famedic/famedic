<?php

namespace App\Notifications;

use App\Models\LaboratoryPurchase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FewDaysLeftToRequestInvoice extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private LaboratoryPurchase $laboratoryPurchase,
        private int $daysLeft
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
        $daysText = $this->daysLeft === 1 ? '1 día' : $this->daysLeft . ' días';

        return (new MailMessage)
            ->subject('¡Solo te quedan ' . $daysText . ' para solicitar tu factura!')
            ->greeting('¡Hola!')
            ->line('Tu orden de laboratorio se realizó muy cerca del final del mes. Solo tienes **' . $daysText . ' restantes** para solicitar la factura:')
            ->line('Folio: **' . $this->laboratoryPurchase->gda_order_id . '**')
            ->line('Paciente: **' . $this->laboratoryPurchase->full_name . '**')
            ->line('Total **' . $this->laboratoryPurchase->formatted_total . '**')
            ->line('En el siguiente enlace puedes solicitar tu factura:')
            ->action('Solicitar factura', route('laboratory-purchases.show', $this->laboratoryPurchase))
            ->line('**Importante:** Si no solicitas la factura antes del final del mes, ya no podrás hacerlo para esta orden.')
            ->line('¡Gracias por confiar en Famedic para tus necesidades de salud!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'laboratory_purchase_id' => $this->laboratoryPurchase->id,
            'days_left' => $this->daysLeft,
        ];
    }
}
