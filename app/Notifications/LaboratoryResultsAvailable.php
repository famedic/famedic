<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryQuote;
use App\Models\User;

class LaboratoryResultsAvailable extends Notification
{
    use Queueable;

    protected $laboratoryPurchase;
    protected $laboratoryQuote;
    protected $gdaOrderId;
    protected $hasPdfInPayload;

    public function __construct($laboratoryPurchase = null, $laboratoryQuote = null, $gdaOrderId = null, $hasPdfInPayload = false)
    {
        $this->laboratoryPurchase = $laboratoryPurchase;
        $this->laboratoryQuote = $laboratoryQuote;
        $this->gdaOrderId = $gdaOrderId;
        $this->hasPdfInPayload = $hasPdfInPayload;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $orderId = $this->laboratoryPurchase?->gda_order_id 
            ?? $this->laboratoryQuote?->gda_external_id 
            ?? $this->gdaOrderId 
            ?? 'N/A';

        $mailMessage = (new MailMessage)
            ->subject('¡Tus resultados de laboratorio están disponibles! - Famedic')
            ->greeting('Hola ' . $notifiable->name . ',')
            ->line('Te informamos que los resultados de tu estudio de laboratorio ya están disponibles en nuestro sistema.');
        
        // Agregar información específica
        $mailMessage->line('**Número de orden:** ' . $orderId);
        
        // Incluir fecha si está disponible
        if ($this->laboratoryPurchase?->created_at) {
            $mailMessage->line('**Fecha del estudio:** ' . $this->laboratoryPurchase->created_at->format('d/m/Y'));
        }
        
        // Agregar enlace según lo que tengamos
        if ($this->laboratoryPurchase) {
            $mailMessage->action(
                '🔬 Consultar mis resultados', 
                url(route('laboratory-purchases.show', $this->laboratoryPurchase->id))
            );
        } elseif ($this->laboratoryQuote) {
            $mailMessage->action(
                '🔬 Consultar mis resultados', 
                url(route('laboratory.quote.show', $this->laboratoryQuote->id))
            );
        } else {
            $mailMessage->action(
                '🔬 Consultar mis resultados', 
                url(route('user.edit'))
            );
        }
        
        // Información adicional sobre el PDF
        if ($this->hasPdfInPayload) {
            $mailMessage->line('')
                ->line('📄 **Los resultados están disponibles en formato PDF.**')
                ->line('Puedes descargarlos directamente desde la plataforma.');
        }
        
        // Instrucciones importantes
        $mailMessage->line('')
            ->line('**📋 Información importante:**')
            ->line('• Para la interpretación clínica de tus resultados, consulta a tu médico tratante.')
            ->line('• Los resultados tienen validez oficial para fines médicos.')
            ->line('• Conserva este correo como comprobante.')
            ->line('')
            ->line('Si tienes alguna duda, contáctanos a través de nuestra plataforma.')
            ->line('')
            ->salutation('Atentamente,')
            ->line('**El equipo de Famedic**')
            ->line('📧 contacto@famedic.com')
            ->line('📱 (81) 8172-2882');
        
        return $mailMessage;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'laboratory_purchase_id' => $this->laboratoryPurchase?->id,
            'laboratory_quote_id' => $this->laboratoryQuote?->id,
            'gda_order_id' => $this->gdaOrderId,
            'has_pdf' => $this->hasPdfInPayload,
            'type' => 'laboratory_results_available',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}