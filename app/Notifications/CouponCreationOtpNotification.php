<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\VonageMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class CouponCreationOtpNotification extends Notification
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
            Log::warning('coupon_creation_otp: SMS sin teléfono válido; usando correo.', [
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
            ->subject('Código de verificación — creación de cupón')
            ->greeting('Verificación de seguridad')
            ->line('Estás creando un nuevo cupón o crédito en Famedic.')
            ->line("Tu código de verificación es: **{$this->otp}**")
            ->line("Este código expira en {$minutes} minutos y solo puede usarse una vez.")
            ->line('Si no solicitaste este código, ignora este mensaje.');
    }

    public function toVonage(object $notifiable): VonageMessage
    {
        $minutes = (int) config('otp.expiry', 10);

        return (new VonageMessage)
            ->content("Famedic: tu código para crear cupón es {$this->otp}. Expira en {$minutes} min.");
    }

    private function vonageIsConfigured(): bool
    {
        return filled(config('services.vonage.key')) && filled(config('services.vonage.secret'));
    }
}
