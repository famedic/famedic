<?php

namespace App\Notifications;

use App\Actions\Laboratories\LaboratoryPurchaseConfirmationViewData;
use App\Models\LaboratoryPurchase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Correo de confirmación de compra de laboratorio (plantillas Markdown según PDF).
 *
 * Ejemplo del array que reciben las vistas (claves comunes):
 *
 * [
 *     'nombre_usuario' => 'María Pérez López',
 *     'consecutivo' => '12345',
 *     'folio_orden' => '987654',
 *     'nombre_paciente' => 'Juan Pérez García',
 *     'fecha_nacimiento' => '15 de ene de 1990',
 *     'laboratorio_marca' => 'Swisslab',
 *     'famedic_logo_url' => 'https://famedic.com.mx/images/logo.png', // config('famedic.email_public_url')
 *     'laboratorio_logo_url' => 'https://famedic.com.mx/images/gda/GDA-SWISSLAB.png',
 *     'estatus_pago' => 'Pagado',
 *     'total' => '$1,200.00 MXN',
 *     'fecha_compra' => '9 abr 2026 3:45 pm',
 *     'appointment_date' => 'miércoles 10 de abril de 2026', // solo con cita
 *     'appointment_time' => '9:00 am', // solo con cita
 *     'branch_name' => 'Sucursal Centro', // solo con cita
 *     'branch_address' => 'Av. ...', // solo con cita
 *     'studies' => [
 *         ['name' => 'Biometría hemática completa', 'instructions' => 'Ayuno de 8 horas.'],
 *     ],
 *     'branches_url' => 'https://ejemplo.test/laboratory-stores?brand=swisslab',
 * ]
 */
class LaboratoryPurchaseCreated extends Notification
{
    use Queueable;

    public function __construct(
        protected LaboratoryPurchase $laboratoryPurchase
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $purchase = $this->laboratoryPurchase->loadMissing([
            'laboratoryPurchaseItems',
            'laboratoryAppointment.laboratoryStore',
            'transactions',
        ]);

        $isWithAppointment = LaboratoryPurchaseConfirmationViewData::hasAppointmentForConfirmation($purchase);

        $data = LaboratoryPurchaseConfirmationViewData::build($purchase, $notifiable, false);

        if ($isWithAppointment) {
            return (new MailMessage)
                ->subject('Gracias por tu orden de Laboratorio en Famedic')
                ->markdown('emails.laboratory.purchase-with-appointment', $data);
        }

        return (new MailMessage)
            ->subject('Gracias por tu orden de Laboratorio en Famedic')
            ->markdown('emails.laboratory.purchase-without-appointment', $data);
    }

    /**
     * Vista previa HTML (debug): misma plantilla y datos que el correo real.
     *
     * @param  string  $variant  auto | with | without
     * @return array{view: string, subject: string, data: array<string, mixed>}
     */
    public function previewMail(object $notifiable, string $variant = 'auto'): array
    {
        if (! in_array($variant, ['auto', 'with', 'without'], true)) {
            $variant = 'auto';
        }

        $purchase = $this->laboratoryPurchase->loadMissing([
            'laboratoryPurchaseItems',
            'laboratoryAppointment.laboratoryStore',
            'transactions',
        ]);

        $hasAppointment = LaboratoryPurchaseConfirmationViewData::hasAppointmentForConfirmation($purchase);

        $data = LaboratoryPurchaseConfirmationViewData::build($purchase, $notifiable, false);

        if ($variant === 'auto') {
            $useWithAppointment = $hasAppointment;
        } elseif ($variant === 'with') {
            $useWithAppointment = true;
            if (! $hasAppointment) {
                $example = localizedDate(now());
                $data = array_merge($data, [
                    'appointment_date' => '(Ejemplo) '.$example->isoFormat('dddd D [de] MMMM [de] YYYY'),
                    'appointment_time' => '(Ejemplo) '.$example->isoFormat('h:mm a'),
                    'branch_name' => 'Sucursal (ejemplo de vista previa)',
                    'branch_address' => '—',
                ]);
            }
        } else {
            $useWithAppointment = false;
            unset(
                $data['appointment_date'],
                $data['appointment_time'],
                $data['branch_name'],
                $data['branch_address'],
            );
        }

        if ($useWithAppointment) {
            return [
                'view' => 'emails.laboratory.purchase-with-appointment',
                'subject' => 'Gracias por tu orden de Laboratorio en Famedic',
                'data' => $data,
            ];
        }

        return [
            'view' => 'emails.laboratory.purchase-without-appointment',
            'subject' => 'Gracias por tu orden de Laboratorio en Famedic',
            'data' => $data,
        ];
    }

    public function toArray(object $notifiable): array
    {
        return [];
    }
}
