<?php

namespace App\Notifications;

use App\Actions\Laboratories\ResolveLaboratoryPurchasePdfPath;
use App\Models\LaboratoryPurchase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class LaboratoryPurchasePdfEmail extends Notification implements ShouldQueue
{
    use Queueable;

    private LaboratoryPurchase $laboratoryPurchase;

    private string $senderName;

    private ResolveLaboratoryPurchasePdfPath $resolvePdfPath;

    public function __construct(LaboratoryPurchase $laboratoryPurchase, string $senderName, ResolveLaboratoryPurchasePdfPath $resolvePdfPath)
    {
        $this->laboratoryPurchase = $laboratoryPurchase;
        $this->senderName = $senderName;
        $this->resolvePdfPath = $resolvePdfPath;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $storagePath = ($this->resolvePdfPath)($this->laboratoryPurchase);
        $filename = basename($storagePath);

        $displayOrderId = ! empty($this->laboratoryPurchase->gda_order_id)
            ? $this->laboratoryPurchase->gda_order_id
            : "#{$this->laboratoryPurchase->id}";

        return (new MailMessage)
            ->subject('Orden de Laboratorio - '.$displayOrderId)
            ->greeting('¡Hola!')
            ->line($this->senderName.' te ha compartido una orden de laboratorio de Famedic.')
            ->line('**Detalles de la orden:**')
            ->line('Folio: **'.$displayOrderId.'**')
            ->line('Paciente: **'.$this->laboratoryPurchase->full_name.'**')
            ->line('Total: **'.$this->laboratoryPurchase->formatted_total.'**')
            ->line('Puedes encontrar el PDF de la orden adjunto a este correo.')
            ->line('Para más información sobre Famedic, visita nuestra página web.')
            ->attachData(Storage::get($storagePath), $filename, [
                'mime' => 'application/pdf',
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'laboratory_purchase_id' => $this->laboratoryPurchase->id,
            'sender_name' => $this->senderName,
        ];
    }
}
