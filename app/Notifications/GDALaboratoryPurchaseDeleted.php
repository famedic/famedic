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
            ->subject('Cancelación de orden de laboratorio - ' . $this->laboratoryPurchase->gda_order_id)
            ->greeting('Hola equipo de GDA,')
            ->line('Se ha procesado la cancelación de una orden de laboratorio con los siguientes detalles:')
            ->line('Folio: **' . $this->laboratoryPurchase->gda_order_id . '**')
            ->line('Paciente: **' . $this->laboratoryPurchase->full_name . '**')
            ->line('Total: **' . $this->laboratoryPurchase->formatted_total . '**')
            ->line('Se ha notificado al cliente sobre la cancelación y se ha iniciado el proceso de reembolso por el monto total de la compra.');

        if ($this->couponBalanceRestoredCents > 0) {
            $mail->line('El saldo a favor aplicado a este pedido fue restaurado al cliente.')
                ->line('Saldo restaurado: **' . formattedCentsPrice($this->couponBalanceRestoredCents) . '**');
        }

        return $mail->line('Gracias por su atención a este asunto.');
    }

    public function toArray(object $notifiable): array
    {
        return [];
    }
}
