<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\LaboratoryPurchase;

class LaboratoryPurchaseCreated extends Notification
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
            ->subject('Gracias por tu orden de Laboratorio en Famedic')
            ->line('Hemos recibido tu orden de laboratorio con los siguientes detalles:')
            ->line('Folio: **' . $this->laboratoryPurchase->gda_order_id . '**')
            ->line('Paciente: **' . $this->laboratoryPurchase->full_name . '**')
            ->line('Total **' . $this->laboratoryPurchase->formatted_total . '**')
            ->line('A partir de este momento, puedes acudir a cualquiera de nuestras sucursales para realizarte los estudios. En el siguiente enlace, puedes encontrar la sucursal más cercana, consultar detalles adicionales de tu orden y, si lo deseas, solicitar tu factura:')
            ->action('Ver orden', route('laboratory-purchases.show', $this->laboratoryPurchase->id))
            ->line('Una vez realizadas las pruebas, te haremos llegar los resultados a través de la plataforma.')
            ->line('¡Gracias por confiar en Famedic para tus necesidades de salud!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
