<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\VonageMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class LaboratoryResultsOtpNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $otp,
        public string $channel
    ) {}

    public function via(object $notifiable): array
    {
        if ($this->channel === 'email') {
            return ['mail'];
        }

        if (app()->environment('local')) {
            Log::info('Laboratory results OTP: SMS canal solicitado en local; se usa correo como respaldo.', [
                'user_id' => $notifiable->id ?? null,
            ]);

            return ['mail'];
        }

        return ['vonage'];
    }

    public function toVonage(object $notifiable): VonageMessage
    {
        return (new VonageMessage)
            ->content(
                'Tu codigo para ver tus resultados de laboratorio en Famedic es: '
                .$this->otp
                .'. El codigo es valido por solo 10 minutos.'
            );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Código para ver tus resultados de laboratorio')
            ->line('Tu código de verificación es: '.$this->otp)
            ->line('El código es válido solo por unos minutos. No lo compartas con nadie.');
    }
}
