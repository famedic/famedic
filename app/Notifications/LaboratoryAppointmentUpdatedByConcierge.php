<?php

namespace App\Notifications;

use App\Models\LaboratoryAppointment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LaboratoryAppointmentUpdatedByConcierge extends Notification
{
    use Queueable;

    public function __construct(
        protected LaboratoryAppointment $appointment
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
            'laboratoryPurchase.transactions',
            'laboratoryPurchase.laboratoryPurchaseItems',
            'customer.laboratoryCartItems.laboratoryTest',
        ]);

        $data = $this->mailViewData($notifiable, $appointment);

        return (new MailMessage)
            ->subject('Tu cita de laboratorio fue actualizada')
            ->markdown('emails.laboratory.appointment-updated-by-concierge', $data);
    }

    /**
     * @return array<string, mixed>
     */
    protected function mailViewData(object $notifiable, LaboratoryAppointment $appointment): array
    {
        $purchase = $appointment->laboratoryPurchase;
        $transaction = $purchase?->transactions?->first();
        $store = $appointment->laboratoryStore;
        $dt = localizedDate($appointment->appointment_date);
        $hasPurchase = $purchase !== null;

        $studies = $hasPurchase
            ? $purchase->laboratoryPurchaseItems->map(fn ($item) => [
                'name' => $item->name,
                'instructions' => ($item->indications !== null && $item->indications !== '') ? $item->indications : '—',
            ])->values()->all()
            : $appointment->customer->laboratoryCartItems
                ->filter(function ($item) use ($appointment) {
                    return $item->laboratoryTest?->brand?->value === $appointment->brand->value;
                })
                ->map(fn ($item) => [
                    'name' => $item->laboratoryTest?->name ?? 'Estudio',
                    'instructions' => ($item->laboratoryTest?->indications !== null && $item->laboratoryTest?->indications !== '') ? $item->laboratoryTest->indications : '—',
                ])->values()->all();

        return [
            'nombre_usuario' => $notifiable->full_name ?? trim((string) $notifiable->name),
            'nombre_paciente' => $appointment->patient_full_name ?? 'Paciente',
            'fecha_nacimiento' => $appointment->formatted_patient_birth_date ?? '—',
            'laboratorio_marca' => $appointment->brand->label(),
            'famedic_logo_url' => $this->emailPublicAssetUrl('images/logo.png'),
            'laboratorio_logo_url' => $this->emailPublicAssetUrl('images/gda/'.$appointment->brand->imageSrc()),
            'appointment_date' => $dt?->isoFormat('dddd D [de] MMMM [de] YYYY') ?? '—',
            'appointment_time' => $dt?->isoFormat('h:mm a') ?? '—',
            'branch_name' => $store?->name ?? '—',
            'branch_address' => ($store?->address !== null && $store->address !== '') ? $store->address : '—',
            'has_purchase' => $hasPurchase,
            'consecutivo' => $hasPurchase && $purchase->gda_consecutivo !== null ? (string) $purchase->gda_consecutivo : null,
            'folio_orden' => $hasPurchase ? (string) $purchase->gda_order_id : null,
            'estatus_pago' => $hasPurchase ? $this->paymentStatusLabel($transaction?->payment_status) : null,
            'metodo_pago' => $hasPurchase ? $this->paymentMethodLabel($transaction?->payment_method ?? $transaction?->gateway) : null,
            'total' => $hasPurchase ? $purchase->formatted_total : null,
            'fecha_compra' => $hasPurchase ? ($purchase->formatted_created_at ?? '—') : null,
            'studies' => $studies,
        ];
    }

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
