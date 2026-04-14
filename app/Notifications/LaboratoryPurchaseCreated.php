<?php

namespace App\Notifications;

use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryPurchase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

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

        $appointment = $purchase->laboratoryAppointment;
        $isWithAppointment = $appointment !== null && $appointment->appointment_date !== null;

        $data = $this->mailViewData($notifiable, $purchase, $appointment);

        if ($isWithAppointment) {
            return (new MailMessage)
                ->subject('Tu cita FAMEDIC está confirmada')
                ->markdown('emails.laboratory.purchase-with-appointment', $data);
        }

        return (new MailMessage)
            ->subject('Gracias por tu orden de Laboratorio en Famedic')
            ->markdown('emails.laboratory.purchase-without-appointment', $data);
    }

    /**
     * @return array<string, mixed>
     */
    protected function mailViewData(object $notifiable, LaboratoryPurchase $purchase, ?LaboratoryAppointment $appointment): array
    {
        $transaction = $purchase->transactions->first();

        $studies = $purchase->laboratoryPurchaseItems->map(function ($item) {
            return [
                'name' => $item->name,
                'instructions' => ($item->indications !== null && $item->indications !== '') ? $item->indications : '—',
            ];
        })->values()->all();

        // Logos: mismas rutas que el front (/images/...), pero host desde config('famedic.email_public_url')
        // (por defecto https://famedic.com.mx) para que carguen en bandeja aunque APP_URL sea local/staging.
        $famedicLogoUrl = $this->emailPublicAssetUrl('images/logo.png');
        $laboratorioLogoUrl = $this->emailPublicAssetUrl('images/gda/'.$purchase->brand->imageSrc());

        $data = [
            'nombre_usuario' => $notifiable->full_name ?? trim((string) $notifiable->name),
            'consecutivo' => $purchase->gda_consecutivo !== null ? (string) $purchase->gda_consecutivo : '—',
            'folio_orden' => (string) $purchase->gda_order_id,
            'nombre_paciente' => $purchase->full_name,
            'fecha_nacimiento' => $purchase->formatted_birth_date ?? '—',
            'laboratorio_marca' => $purchase->brand->label(),
            'famedic_logo_url' => $famedicLogoUrl,
            'laboratorio_logo_url' => $laboratorioLogoUrl,
            'estatus_pago' => $this->paymentStatusLabel($transaction?->payment_status),
            'metodo_pago' => $this->paymentMethodLabel($transaction?->payment_method ?? $transaction?->gateway),
            'total' => $purchase->formatted_total,
            'fecha_compra' => $purchase->formatted_created_at ?? '—',
            'studies' => $studies,
            'branches_url' => URL::route('laboratory-stores.index', ['brand' => $purchase->brand->value]),
        ];

        if ($appointment?->appointment_date) {
            $dt = localizedDate($appointment->appointment_date);
            $store = $appointment->laboratoryStore;

            $data['appointment_date'] = $dt->isoFormat('dddd D [de] MMMM [de] YYYY');
            $data['appointment_time'] = $dt->isoFormat('h:mm a');
            $data['branch_name'] = $store?->name ?? '—';
            $data['branch_address'] = ($store?->address !== null && $store->address !== '') ? $store->address : '—';
        }

        return $data;
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

        $appointment = $purchase->laboratoryAppointment;
        $hasAppointment = $appointment !== null && $appointment->appointment_date !== null;

        $data = $this->mailViewData($notifiable, $purchase, $appointment);

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
                'subject' => 'Tu cita FAMEDIC está confirmada',
                'data' => $data,
            ];
        }

        return [
            'view' => 'emails.laboratory.purchase-without-appointment',
            'subject' => 'Gracias por tu orden de Laboratorio en Famedic',
            'data' => $data,
        ];
    }

    /**
     * URL absoluta a un recurso en /public (p. ej. images/logo.png) usando config('famedic.email_public_url').
     */
    protected function emailPublicAssetUrl(string $path): string
    {
        $base = rtrim((string) config('famedic.email_public_url'), '/');
        $path = ltrim($path, '/');

        return $base.'/'.$path;
    }

    protected function paymentStatusLabel(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'captured', 'completed', 'paid', 'success', 'succeeded' => 'Pagado',
            'pending', 'processing' => 'En proceso',
            'failed', 'declined' => 'No completado',
            'refunded' => 'Reembolsado',
            'credit' => 'Acreditado',
            default => $status ? ucfirst(str_replace('_', ' ', $status)) : '—',
        };
    }

    protected function paymentMethodLabel(?string $method): string
    {
        return match (strtolower((string) $method)) {
            'paypal' => 'PayPal',
            'efevoopay' => 'EfevooPay',
            'odessa' => 'Caja de ahorro',
            'stripe' => 'Tarjeta',
            default => $method ? ucfirst(str_replace('_', ' ', $method)) : '—',
        };
    }

    public function toArray(object $notifiable): array
    {
        return [];
    }
}
