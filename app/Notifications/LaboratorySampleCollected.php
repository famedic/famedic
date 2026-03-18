<?php
// app/Notifications/LaboratorySampleCollected.php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryQuote;
use Carbon\Carbon;

class LaboratorySampleCollected extends Notification
{
    use Queueable;

    protected $laboratoryPurchase;
    protected $laboratoryQuote;
    protected $gdaOrderId;

    public function __construct($laboratoryPurchase = null, $laboratoryQuote = null, $gdaOrderId = null)
    {
        $this->laboratoryPurchase = $laboratoryPurchase;
        $this->laboratoryQuote = $laboratoryQuote;
        $this->gdaOrderId = $gdaOrderId;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $orderId = $this->laboratoryPurchase?->gda_order_id
            ?? $this->laboratoryQuote?->gda_external_id
            ?? $this->gdaOrderId
            ?? 'N/A';

        $firstName = explode(' ', $notifiable->name)[0];

        $collectionDateTime = $this->laboratoryPurchase?->ready_at
            ?? $this->laboratoryQuote?->ready_at
            ?? now();

        $dt = $collectionDateTime instanceof Carbon
            ? $collectionDateTime
            : Carbon::parse($collectionDateTime);

        $dt = $dt->copy()->timezone(config('app.timezone'))->locale('es');

        $formattedCollectionDateTime = sprintf(
            '%s %s de %s de %s a las %s',
            ucfirst($dt->isoFormat('dddd')),
            $dt->isoFormat('D'),
            ucfirst($dt->isoFormat('MMMM')),
            $dt->isoFormat('YYYY'),
            strtolower($dt->isoFormat('hh:mm A')) // formato tipo "09:40 a. m."
        );

        $mailMessage = (new MailMessage)
            ->subject('Confirmación de toma de muestra — Orden ' . $orderId)
            ->greeting('Hola ' . $firstName . ',')
            ->line('Te confirmamos que la toma de muestra para tu estudio de laboratorio se realizó exitosamente.')
            ->line('')
            ->line('Sabemos que tus resultados son lo más importante para ti. Por eso, te notificaremos automáticamente en cuanto el laboratorio termine de procesar tus estudios, para que puedas consultarlos de inmediato en tu cuenta.')
            ->line('')
            ->line('Detalles de tu estudio')
            ->line('• Número de orden: ' . $orderId)
            ->line('• Fecha y hora: ' . $formattedCollectionDateTime)
            ->line('')
            ->line('¿Qué sigue?')
            ->line('Nuestro laboratorio ya está procesando tus muestras. El tiempo de entrega puede variar según el tipo de estudio solicitado. En cuanto estén listos, recibirás una nueva notificación y podrás verlos en tu cuenta.')
            ->line('')
            ->line('Recuerda: en Famedic cuentas con precios preferenciales en una amplia variedad de estudios de laboratorio. Si necesitas complementar o repetir algún estudio, con gusto te ayudamos.');

        if ($this->laboratoryPurchase) {
            $mailMessage->action(
                'Ver detalles de mi orden',
                url(route('laboratory-purchases.show', $this->laboratoryPurchase->id))
            );
        }

        $mailMessage
            ->line('')
            ->line('¿Necesitas apoyo? Responde a este correo o contáctanos en:')
            ->line('📧 contacto@famedic.com | 📱 (81) 8172-2882')
            ->line('')
            ->line('Gracias por confiar en nosotros,')
            ->line('Equipo Famedic');

        return $mailMessage;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'laboratory_purchase_id' => $this->laboratoryPurchase?->id,
            'laboratory_quote_id' => $this->laboratoryQuote?->id,
            'gda_order_id' => $this->gdaOrderId,
            'type' => 'laboratory_sample_collected',
            'timestamp' => now()->toIso8601String(),
            'message' => 'Toma de muestra realizada exitosamente',
            'order_number' => $this->laboratoryPurchase?->gda_order_id ?? $this->gdaOrderId
        ];
    }
}
