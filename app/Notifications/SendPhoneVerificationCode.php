<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\VonageMessage;
use Illuminate\Notifications\Notification;

class SendPhoneVerificationCode extends Notification
{
    use Queueable;

    public string $otp;

    public function __construct(string $otp)
    {
        $this->otp = $otp;
    }

    public function via(object $notifiable): array
    {
        if (app()->environment('local')) {
            return ['mail'];
        }
        return ['vonage'];
    }

    public function toVonage($notifiable)
    {
        return (new VonageMessage())
            ->content('Tu codigo de verificacion para Famedic es: ' . $this->otp . '. El codigo es valido por solo 10 minutos.');
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Código de verificación')
            ->line('Tu código de verificación es: ' . $this->otp)
            ->line('El código es valido por solo 10 minutos.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
