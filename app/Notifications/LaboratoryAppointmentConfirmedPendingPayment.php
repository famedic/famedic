<?php

namespace App\Notifications;

use App\Actions\Laboratories\PrepareLaboratoryCheckoutPaymentLinkAction;
use App\Models\LaboratoryAppointment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LaboratoryAppointmentConfirmedPendingPayment extends Notification
{
    use Queueable;

    public function __construct(
        protected LaboratoryAppointment $appointment,
        protected string $checkoutUrl,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appointment = $this->appointment->loadMissing([
            'laboratoryStore',
            'customer',
        ]);

        $summary = app(PrepareLaboratoryCheckoutPaymentLinkAction::class)
            ->checkoutSummaryForMail($appointment);

        return (new MailMessage)
            ->subject('Tu cita está registrada — completa el pago en Famedic')
            ->markdown('emails.laboratory.appointment-confirmed-pending-payment', [
                'nombre_usuario' => $notifiable->full_name ?? trim((string) $notifiable->name),
                'laboratorio_marca' => $appointment->brand->label(),
                'famedic_logo_url' => $this->emailPublicAssetUrl('images/logo.png'),
                'laboratorio_logo_url' => $this->emailPublicAssetUrl('images/gda/'.$appointment->brand->imageSrc()),
                'checkout_url' => $this->checkoutUrl,
                ...$summary,
            ]);
    }

    protected function emailPublicAssetUrl(string $path): string
    {
        $base = rtrim((string) config('famedic.email_public_url'), '/');
        $path = ltrim($path, '/');

        return $base.'/'.$path;
    }

    public function toArray(object $notifiable): array
    {
        return [];
    }
}
