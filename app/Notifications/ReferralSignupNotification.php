<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReferralSignupNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected User $newUser;

    public function __construct(User $newUser)
    {
        $this->newUser = $newUser;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $referrerName = $notifiable->name ?: 'Usuario';
        $newUserName = $this->newUser->full_name ?: $this->newUser->name ?: 'Usuario';

        return (new MailMessage)
            ->subject('¡Alguien se registró usando tu enlace de invitación!')
            ->greeting("¡Hola {$referrerName}!")
            ->line("¡Excelentes noticias! {$newUserName} se ha registrado en Famedic usando tu enlace de invitación.")
            ->line('Gracias por compartir Famedic con tus amigos y ayudar a que más personas accedan a nuestros servicios de salud.')
            ->line('¡Sigue invitando a más amigos para que conozcan Famedic!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'referral_signup',
            'new_user_id' => $this->newUser->id,
            'new_user_name' => $this->newUser->full_name ?: $this->newUser->name,
            'message' => 'Someone signed up using your referral link',
        ];
    }
}
