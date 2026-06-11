<?php

namespace App\Notifications\Api\V1\Auth;

use App\Models\OtpCode;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AkubicaOtpNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $otp,
        public string $purpose,
        public int $expiryMinutes,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $context = $this->purpose === OtpCode::PURPOSE_AKUBICA_REGISTER
            ? 'completar tu registro en Akubica'
            : 'iniciar sesión en Akubica';

        return (new MailMessage)
            ->subject('Tu código de verificación Akubica')
            ->line("Tu código de verificación para {$context} es: {$this->otp}")
            ->line("El código expira en {$this->expiryMinutes} minutos.")
            ->line('Es de un solo uso. No lo compartas con nadie.');
    }
}
