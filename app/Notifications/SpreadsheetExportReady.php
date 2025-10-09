<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SpreadsheetExportReady extends Notification
{
    use Queueable;

    protected string $url;

    public function __construct(string $url)
    {
        $this->url = $url;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Tu reporte de Famedic se ha generado')
            ->line('Hemos terminado de generar tu reporte. Para descargarlo, haz clic en el siguiente enlace. Ten en cuenta que este enlace expirará en 2 horas.')
            ->action('Descargar reporte', $this->url);
    }
}
