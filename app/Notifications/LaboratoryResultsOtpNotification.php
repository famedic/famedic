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
            $email = $notifiable->routeNotificationFor('mail', $this)
                ?? ($notifiable->email ?? null);

            Log::info('Laboratory results OTP: enviando por correo.', [
                'user_id' => $notifiable->id ?? null,
                'destination_masked' => $this->maskEmailForLog(is_string($email) ? $email : null),
                'mailer' => config('mail.default'),
                'from' => config('mail.from.address'),
                'app_env' => config('app.env'),
            ]);

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

        Log::info('Laboratory results OTP: enviando por SMS (Vonage).', [
            'user_id' => $notifiable->id ?? null,
            'destination_masked' => $this->maskPhoneForLog($vonageDestination),
            'app_env' => config('app.env'),
        ]);

        return ['vonage'];
    }

    private function maskEmailForLog(?string $email): ?string
    {
        if ($email === null || $email === '') {
            return null;
        }

        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '***';
        }

        $local = $parts[0];
        $maskedLocal = strlen($local) <= 1
            ? '*'
            : substr($local, 0, 1).str_repeat('*', max(1, strlen($local) - 1));

        return $maskedLocal.'@'.$parts[1];
    }

    private function maskPhoneForLog(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (strlen($digits) < 4) {
            return '***';
        }

        return '***'.substr($digits, -4);
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
