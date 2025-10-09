<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\LaboratoryPurchase;

class LaboratoryPurchaseResultsUploaded extends Notification
{
    use Queueable;

    protected $laboratoryPurchase;

    public function __construct(LaboratoryPurchase $laboratoryPurchase)
    {
        $this->laboratoryPurchase = $laboratoryPurchase;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Resultados de laboratorio disponibles en Famedic')
            ->line('Los resultados de tu estudio de laboratorio ' . $this->laboratoryPurchase->gda_order_id . ' ya están disponibles en Famedic.')
            ->line('Puedes consultar y descargar tus resultados en el siguiente enlace:')
            ->action('Ver resultados', route('laboratory-purchases.show', $this->laboratoryPurchase->id))
            ->line('Gracias por confiar en Famedic.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
