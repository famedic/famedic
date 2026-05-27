<?php

namespace App\Actions\Laboratories;

use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryPurchase;
use Illuminate\Support\Facades\URL;

/**
 * Datos compartidos entre el correo de confirmación de compra de laboratorio
 * y el PDF de comprobante (misma fuente de verdad).
 */
final class LaboratoryPurchaseConfirmationViewData
{
    /**
     * @return array<string, mixed>
     */
    public static function build(LaboratoryPurchase $purchase, object $notifiable, bool $forPdf = false): array
    {
        $purchase->loadMissing([
            'laboratoryPurchaseItems',
            'laboratoryAppointment.laboratoryStore',
            'transactions',
        ]);

        $appointment = $purchase->laboratoryAppointment;
        $transaction = $purchase->transactions->first();

        $studies = $purchase->laboratoryPurchaseItems->map(function ($item) {
            return [
                'name' => $item->name,
                'instructions' => ($item->indications !== null && $item->indications !== '') ? $item->indications : '—',
            ];
        })->values()->all();

        $famedicLogoUrl = self::assetUrl('images/logo.png', $forPdf);
        $laboratorioLogoUrl = self::assetUrl('images/gda/'.$purchase->brand->imageSrc(), $forPdf);

        $data = [
            'nombre_usuario' => $notifiable->full_name ?? trim((string) $notifiable->name),
            'consecutivo' => $purchase->gda_consecutivo !== null ? (string) $purchase->gda_consecutivo : '—',
            'folio_orden' => (string) $purchase->gda_order_id,
            'nombre_paciente' => $purchase->full_name,
            'fecha_nacimiento' => $purchase->formatted_birth_date ?? '—',
            'laboratorio_marca' => $purchase->brand->label(),
            'famedic_logo_url' => $famedicLogoUrl,
            'laboratorio_logo_url' => $laboratorioLogoUrl,
            'estatus_pago' => self::paymentStatusLabel($transaction?->payment_status),
            'metodo_pago' => self::paymentMethodLabel($transaction?->payment_method ?? $transaction?->gateway),
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

    public static function hasAppointmentForConfirmation(LaboratoryPurchase $purchase): bool
    {
        $purchase->loadMissing('laboratoryAppointment');

        $appointment = $purchase->laboratoryAppointment;

        return $appointment !== null && $appointment->appointment_date !== null;
    }

    protected static function assetUrl(string $path, bool $forPdf): string
    {
        $path = ltrim($path, '/');

        if ($forPdf) {
            return self::assetDataUri($path);
        }

        $base = rtrim((string) config('famedic.email_public_url'), '/');

        return $base.'/'.$path;
    }

    /**
     * Imágenes embebidas en base64 para PDF (DomPDF).
     */
    protected static function assetDataUri(string $path): string
    {
        $absolute = public_path($path);

        if (! is_readable($absolute)) {
            return '';
        }

        $extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));

        $mime = match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($absolute));
    }

    protected static function paymentStatusLabel(?string $status): string
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

    protected static function paymentMethodLabel(?string $method): string
    {
        return match (strtolower((string) $method)) {
            'paypal' => 'PayPal',
            'efevoopay' => 'EfevooPay',
            'odessa' => 'Caja de ahorro',
            'stripe' => 'Tarjeta',
            default => $method ? ucfirst(str_replace('_', ' ', $method)) : '—',
        };
    }
}
