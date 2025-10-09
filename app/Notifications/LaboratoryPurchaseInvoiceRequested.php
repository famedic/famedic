<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\LaboratoryPurchase;

class LaboratoryPurchaseInvoiceRequested extends Notification
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
            ->subject('Solicitud de factura en Famedic')
            ->line('Hay una nueva solicitud de factura en Famedic.')
            ->line('Puedes consultar los detalles de la solicitud y completar el proceso de facturación en el siguiente enlace:')
            ->action('Ver orden', route('admin.laboratory-purchases.show', $this->laboratoryPurchase->id))
            ->line('Gracias por tu atención a este asunto.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
