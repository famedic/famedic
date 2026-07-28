<?php

namespace App\Notifications\Api\V1\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AkubicaSecureRegisterOtpMailNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $code)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Tu código de verificación Akubica')
            ->line("Tu código de verificación para completar tu registro en Akubica es: {$this->code}")
            ->line('El código expira en 10 minutos.')
            ->line('Es de un solo uso. No lo compartas con nadie.');
    }
}
