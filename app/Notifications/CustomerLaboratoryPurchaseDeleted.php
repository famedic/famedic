<?php

namespace App\Notifications;

use App\Models\LaboratoryPurchase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerLaboratoryPurchaseDeleted extends Notification
{
    use Queueable;

    public function __construct(
        private LaboratoryPurchase $laboratoryPurchase,
        private int $couponBalanceRestoredCents = 0,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Cancelación de tu orden de laboratorio - ' . $this->laboratoryPurchase->gda_order_id)
            ->greeting('¡Hola!')
            ->line('Te informamos que tu orden de laboratorio ha sido cancelada. Los detalles de la orden cancelada son:')
            ->line('Folio: **' . $this->laboratoryPurchase->gda_order_id . '**')
            ->line('Paciente: **' . $this->laboratoryPurchase->full_name . '**')
            ->line('Total pagado: **' . $this->laboratoryPurchase->formatted_net_total . '**')
            ->line('Hemos iniciado el proceso de reembolso por el monto total de tu compra. El reembolso se verá reflejado en tu cuenta en los próximos días hábiles.');

        if ($this->couponBalanceRestoredCents > 0) {
            $mail->line('Tu saldo a favor aplicado a este pedido fue restaurado.')
                ->line('Saldo restaurado: **' . formattedCentsPrice($this->couponBalanceRestoredCents) . '**');
        }

        return $mail
            ->line('Si tienes alguna pregunta o necesitas más información, no dudes en contactar a nuestro equipo de atención al cliente.')
            ->line('¡Gracias por confiar en Famedic para tus necesidades de salud!');
    }

    public function toArray(object $notifiable): array
    {
        return [];
    }
}
