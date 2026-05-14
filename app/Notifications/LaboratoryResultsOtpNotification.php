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

        $vonageDestination = $this->resolveVonageDestination($notifiable);
        if ($vonageDestination === null || $vonageDestination === '') {
            Log::warning('Laboratory results OTP: SMS solicitado pero no hay número válido para Vonage; se usa correo.', [
                'user_id' => $notifiable->id ?? null,
                'app_env' => config('app.env'),
            ]);

            return ['mail'];
        }

        if (app()->environment(['local', 'testing'])) {
            Log::info('Laboratory results OTP: SMS solicitado en entorno local/testing; se usa correo como respaldo.', [
                'user_id' => $notifiable->id ?? null,
                'app_env' => config('app.env'),
            ]);

            return ['mail'];
        }

        if (! $this->vonageIsConfigured()) {
            Log::warning('Laboratory results OTP: SMS solicitado pero Vonage no está configurado (VONAGE_KEY / VONAGE_SECRET); se usa correo.', [
                'user_id' => $notifiable->id ?? null,
                'app_env' => config('app.env'),
            ]);

            return ['mail'];
        }

        return ['vonage'];
    }

    private function resolveVonageDestination(object $notifiable): ?string
    {
        if (method_exists($notifiable, 'routeNotificationForVonage')) {
            $to = $notifiable->routeNotificationForVonage($this);
            if (is_string($to) && $to !== '') {
                return $to;
            }
        }

        $fallback = $notifiable->routeNotificationFor('vonage', $this);

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    private function vonageIsConfigured(): bool
    {
        $key = config('vonage.api_key');
        $secret = config('vonage.api_secret');

        return filled($key) && filled($secret);
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
