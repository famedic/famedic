<?php

namespace App\Http\Resources;

use App\Actions\Laboratories\LaboratoryPurchaseConfirmationViewData;
use App\Enums\LaboratoryBrand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SharedLaboratoryPurchaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $purchase = $this->resource;
        $appointment = $purchase->laboratoryAppointment;
        $store = $appointment?->laboratoryStore;
        $brand = $purchase->brand instanceof LaboratoryBrand
            ? $purchase->brand
            : LaboratoryBrand::tryFrom((string) $purchase->brand);

        $appointmentDate = $appointment?->appointment_date
            ? localizedDate($appointment->appointment_date)
            : null;

        return [
            'order' => [
                'folio' => (string) ($purchase->gda_order_id ?: $purchase->id),
                'brand' => [
                    'value' => $brand?->value,
                    'label' => $brand?->label() ?? (string) $purchase->brand,
                    'logo_url' => $brand ? asset('images/gda/'.$brand->imageSrc()) : null,
                ],
                'created_at' => $purchase->created_at?->toIso8601String(),
                'formatted_created_at' => $purchase->formatted_created_at,
            ],
            'patient' => [
                'full_name' => $purchase->full_name ?: 'Paciente',
            ],
            'studies' => $purchase->laboratoryPurchaseItems
                ->map(fn ($item) => [
                    'name' => $item->name,
                    'feature_list' => LaboratoryPurchaseConfirmationViewData::normalizePackageFeatureList($item->feature_list),
                    'indications' => filled($item->indications) ? $item->indications : null,
                ])
                ->values()
                ->all(),
            'appointment' => [
                'scheduled' => $appointmentDate !== null,
                'date' => $appointment?->appointment_date?->toIso8601String(),
                'formatted_date' => $appointmentDate?->isoFormat('dddd D [de] MMMM [de] YYYY'),
                'formatted_time' => $appointmentDate?->isoFormat('h:mm a'),
                'status' => $appointment?->confirmed_at ? 'confirmed' : 'pending',
            ],
            'store' => $store ? [
                'name' => $store->name,
                'address' => $store->address,
                'google_maps_url' => $this->safePublicUrl($store->google_maps_url),
                'phone' => $store->phone,
                'weekly_hours' => $store->weekly_hours,
                'saturday_hours' => $store->saturday_hours,
                'sunday_hours' => $store->sunday_hours,
            ] : null,
            'notice' => 'Este enlace permite consultar únicamente información de tu cita. Los resultados y datos de facturación no están disponibles desde este enlace.',
        ];
    }

    private function safePublicUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }
}
