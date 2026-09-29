<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryQuote;
use App\Models\User;
use App\Services\Laboratory\LabResultsAccessTokenService;
use App\Support\LabResultsOtp;
use App\Support\Laboratory\PatientEmailDetails;
use Carbon\Carbon;

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

        $resultsAccessUrl = null;
        if ($this->laboratoryPurchase && $notifiable instanceof User) {
            if (LabResultsOtp::required()) {
                $plainToken = app(LabResultsAccessTokenService::class)->generate(
                    $notifiable,
                    $this->laboratoryPurchase
                );

                $resultsAccessUrl = route('lab-results.show', ['token' => $plainToken]);
            } else {
                $resultsAccessUrl = url(route('laboratory-purchases.show', $this->laboratoryPurchase->id));
            }
        }

        $patientDetails = $this->patientDetails();
        $patientIsNotifiable = PatientEmailDetails::isSameAsNotifiable($patientDetails, $notifiable);
        $subject = $patientIsNotifiable
            ? '¡Tus resultados de laboratorio están disponibles! - Famedic'
            : 'Resultados de laboratorio disponibles - Famedic';
        $actionLabel = $patientIsNotifiable ? '🔬 Consultar mis resultados' : '🔬 Consultar resultados';
        $resultsAvailableLine = $patientIsNotifiable
            ? 'Te confirmamos que los resultados de tus estudios de laboratorio, a nombre de **'.$patientDetails['name'].'**, ya están disponibles en nuestro sistema.'
            : 'Te confirmamos que los resultados de los estudios de laboratorio del paciente **'.$patientDetails['name'].'** ya están disponibles en nuestro sistema.';
        $clinicalInterpretationLine = $patientIsNotifiable
            ? '• Para la interpretación clínica de tus resultados, consulta a tu médico tratante.'
            : '• Para la interpretación clínica de los resultados, consulta al médico tratante.';

        $mailMessage = (new MailMessage)
            ->subject($subject)
            ->greeting('Hola ' . $notifiable->name . ',')
            ->line($resultsAvailableLine);
        
        // Agregar información específica
        $mailMessage->line('**Número de orden:** ' . $orderId);
        
        // Incluir fecha si está disponible
        if ($this->laboratoryPurchase?->created_at) {
            $dt = $this->laboratoryPurchase->created_at instanceof Carbon
                ? $this->laboratoryPurchase->created_at
                : Carbon::parse($this->laboratoryPurchase->created_at);

            $dt = $dt->copy()->timezone(config('app.timezone'))->locale('es');

            $formattedStudyDate = sprintf(
                '%s %s de %s de %s',
                ucfirst($dt->isoFormat('dddd')),
                $dt->isoFormat('D'),
                ucfirst($dt->isoFormat('MMMM')),
                $dt->isoFormat('YYYY')
            );

            $mailMessage->line('**Fecha del estudio:** ' . $formattedStudyDate);
        }
        
        // Agregar enlace según lo que tengamos
        if ($resultsAccessUrl) {
            $mailMessage->action(
                $actionLabel,
                $resultsAccessUrl
            );
        } elseif ($this->laboratoryPurchase) {
            $mailMessage->action(
                $actionLabel,
                url(route('laboratory-purchases.show', $this->laboratoryPurchase->id))
            );
        } elseif ($this->laboratoryQuote) {
            $mailMessage->action(
                $actionLabel,
                url(route('laboratory.quote.show', $this->laboratoryQuote->id))
            );
        } else {
            $mailMessage->action(
                $actionLabel,
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
            ->line($clinicalInterpretationLine)
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

    /**
     * @return array{name: string, birth_date: string, gender: string|null, phone: string|null}
     */
    private function patientDetails(): array
    {
        if ($this->laboratoryPurchase instanceof LaboratoryPurchase) {
            return PatientEmailDetails::fromPurchase($this->laboratoryPurchase);
        }

        if ($this->laboratoryQuote instanceof LaboratoryQuote) {
            return PatientEmailDetails::fromQuote($this->laboratoryQuote);
        }

        return [
            'name' => 'Paciente',
            'birth_date' => '—',
            'gender' => null,
            'phone' => null,
        ];
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
