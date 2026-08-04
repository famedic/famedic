<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\VonageMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class CouponAuthorizationOtpNotification extends Notification
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

        $vonageDestination = $notifiable->routeNotificationForVonage($this);
        if ($vonageDestination === null || $vonageDestination === '') {
            Log::warning('coupon_authorization_otp: SMS sin teléfono válido; usando correo.', [
                'user_id' => $notifiable->id ?? null,
            ]);

            return ['mail'];
        }

        if (app()->environment(['local', 'testing']) || ! $this->vonageIsConfigured()) {
            return ['mail'];
        }

        return ['vonage'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('otp.expiry', 10);

        return (new MailMessage)
            ->subject('Código de verificación — aprobación de autorización')
            ->greeting('Verificación de seguridad')
            ->line('Estás aprobando una solicitud de crédito, cupón o código promocional en Famedic.')
            ->line("Tu código de verificación es: **{$this->otp}**")
            ->line("Este código expira en {$minutes} minutos.")
            ->line('Si no solicitaste este código, ignora este mensaje.');
    }

    public function toVonage(object $notifiable): VonageMessage
    {
        return (new VonageMessage)
            ->content("Famedic: tu código para aprobar la autorización es {$this->otp}. Expira en ".(int) config('otp.expiry', 10).' min.');
    }

    private function vonageIsConfigured(): bool
    {
        return ! empty(config('services.vonage.key')) && ! empty(config('services.vonage.secret'));
    }
}
