<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\VonageMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

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
        $environment = app()->environment();
        $shouldUseMail = app()->environment('local');
        $channels = $shouldUseMail ? ['mail'] : ['vonage'];

        Log::info('OTP notification channel selected', [
            'environment' => $environment,
            'channels' => $channels,
            'user_id' => $notifiable->id ?? null,
            'has_phone' => !empty($notifiable->phone ?? null),
            'has_email' => !empty($notifiable->email ?? null),
        ]);

        return $channels;
    }

    public function toVonage($notifiable)
    {
        Log::info('OTP notification sending via SMS (Vonage)', [
            'user_id' => $notifiable->id ?? null,
            'phone_e164' => method_exists($notifiable, 'routeNotificationForVonage')
                ? $notifiable->routeNotificationForVonage($this)
                : null,
        ]);

        return (new VonageMessage())
            ->content('Tu codigo de verificacion para Famedic es: ' . $this->otp . '. El codigo es valido por solo 10 minutos.');
    }

    public function toMail($notifiable)
    {
        Log::info('OTP notification sending via MAIL fallback', [
            'user_id' => $notifiable->id ?? null,
            'email' => $notifiable->email ?? null,
        ]);

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
