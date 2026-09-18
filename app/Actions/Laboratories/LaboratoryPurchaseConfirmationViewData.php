<?php

namespace App\Actions\Laboratories;

use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryStore;
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
            'preferredLaboratoryStore',
            'transactions',
        ]);

        $purchase->hydrateLaboratoryPurchaseItemsFeatureLists();

        $appointment = $purchase->laboratoryAppointment;
        $transaction = $purchase->transactions->first();

        $studies = $purchase->laboratoryPurchaseItems->map(function ($item) {
            return [
                'name' => $item->name,
                'instructions' => ($item->indications !== null && $item->indications !== '') ? $item->indications : '—',
                'feature_list' => self::normalizePackageFeatureList($item->feature_list),
            ];
        })->values()->all();

        $famedicLogoUrl = self::assetUrl('images/logo.png', $forPdf);
        $laboratorioLogoUrl = self::assetUrl('images/gda/'.$purchase->brand->imageSrc(), $forPdf);

        $grossCents = (int) $purchase->total_cents;
        $creditCents = $purchase->appliedCouponDiscountCents();
        $netCents = $purchase->netTotalCents();
        $catalogDiscountCents = $purchase->catalogDiscountCents();
        $itemsSubtotalCents = $purchase->itemsSubtotalCents();
        $subtotalCents = $itemsSubtotalCents > 0 ? $itemsSubtotalCents : $grossCents;

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
            'subtotal' => formattedCentsPrice($subtotalCents),
            'catalog_discount' => $catalogDiscountCents > 0
                ? formattedCentsPrice($catalogDiscountCents)
                : null,
            'coupon_discount' => $creditCents > 0
                ? formattedCentsPrice($creditCents)
                : null,
            'has_coupon_credit' => $creditCents > 0,
            'credit_applied_message' => $creditCents > 0
                ? 'Se aplicó un crédito a favor de '.formattedCentsPrice($creditCents).'.'
                : null,
            'total' => formattedCentsPrice($netCents),
            'total_gross' => formattedCentsPrice($grossCents),
            'fecha_compra' => $purchase->formatted_created_at ?? '—',
            'studies' => $studies,
            'branches_url' => URL::route('laboratory-stores.index', ['brand' => $purchase->brand->value]),
            ...self::preferredStorePayload($purchase->preferredLaboratoryStore),
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

    /**
     * @param  mixed  $raw  array|string|null desde JSON o cast Eloquent
     * @return list<string>
     */
    public static function normalizePackageFeatureList(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $labels = [];
        foreach ($raw as $entry) {
            if (is_string($entry)) {
                $t = trim($entry);
                if ($t !== '') {
                    $labels[] = $t;
                }
            } elseif (is_array($entry)) {
                $t = trim((string) ($entry['name'] ?? $entry['label'] ?? ''));
                if ($t !== '') {
                    $labels[] = $t;
                }
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * @return array{
     *     has_preferred_store: bool,
     *     preferred_store_name?: string,
     *     preferred_store_address?: string|null,
     *     preferred_store_phone?: string|null,
     *     preferred_store_hours?: string|null,
     *     preferred_store_google_maps_url?: string|null,
     * }
     */
    protected static function preferredStorePayload(?LaboratoryStore $store): array
    {
        if ($store === null) {
            return [
                'has_preferred_store' => false,
            ];
        }

        return [
            'has_preferred_store' => true,
            'preferred_store_name' => $store->name,
            'preferred_store_address' => filled($store->address) ? $store->address : null,
            'preferred_store_phone' => filled($store->phone) ? $store->phone : null,
            'preferred_store_hours' => self::storeSummaryHours($store),
            'preferred_store_google_maps_url' => self::safePublicUrl($store->google_maps_url),
        ];
    }

    protected static function storeSummaryHours(LaboratoryStore $store): ?string
    {
        $hours = collect([
            filled($store->weekly_hours) ? 'Lun-vie: '.$store->weekly_hours : null,
            filled($store->saturday_hours) ? 'Sáb: '.$store->saturday_hours : null,
            filled($store->sunday_hours) ? 'Dom: '.$store->sunday_hours : null,
        ])->filter()->implode(' · ');

        return filled($hours) ? $hours : null;
    }

    protected static function safePublicUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
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
            'coupon_balance' => 'Crédito a favor',
            default => $method ? ucfirst(str_replace('_', ' ', $method)) : '—',
        };
    }
}
