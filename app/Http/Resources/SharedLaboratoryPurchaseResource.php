<?php

namespace App\Http\Resources;

use App\Actions\Laboratories\LaboratoryPurchaseConfirmationViewData;
use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryTest;
use App\Services\LaboratoryPreparation\LaboratoryPreparationPresenter;
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
        $requiresAppointment = $this->requiresAppointment();
        $hasAppointment = $appointmentDate !== null;
        $subtotalCents = (int) $purchase->total_cents;
        $couponDiscountCents = $purchase->appliedCouponDiscountCents();
        $totalCents = $purchase->netTotalCents();
        $storesCount = $brand ? LaboratoryStore::query()
            ->where('brand', $brand->value)
            ->where('is_active', true)
            ->count() : 0;
        $featuredStores = $brand && ! $hasAppointment && ! $requiresAppointment
            ? $this->featuredStores($brand)
            : [];
        $preparation = app(LaboratoryPreparationPresenter::class)->present($purchase);

        return [
            'order' => [
                'folio' => filled($purchase->gda_order_id) ? (string) $purchase->gda_order_id : null,
                'famedic_folio' => filled($purchase->gda_order_id) ? (string) $purchase->gda_order_id : null,
                'laboratory_order_folio' => filled($purchase->gda_order_id) ? (string) $purchase->gda_order_id : null,
                'consecutive' => $purchase->gda_consecutivo !== null ? (string) $purchase->gda_consecutivo : null,
                'brand' => [
                    'value' => $brand?->value,
                    'label' => $brand?->label() ?? (string) $purchase->brand,
                    'logo_url' => $brand ? asset('images/gda/'.$brand->imageSrc()) : null,
                    'stores_url' => $brand ? route('laboratory-stores.index', ['brand' => $brand->value]) : null,
                    'stores_count' => $storesCount,
                    'featured_stores' => $featuredStores,
                ],
                'created_at' => $purchase->created_at?->toIso8601String(),
                'formatted_created_at' => $purchase->formatted_created_at,
            ],
            'purchaser' => [
                'name' => $purchase->customer?->user?->full_name ?: null,
            ],
            'patient' => [
                'full_name' => $purchase->full_name ?: 'Paciente',
                'birth_date' => $purchase->birth_date?->toDateString(),
                'formatted_birth_date' => $purchase->formatted_birth_date,
                'gender' => $purchase->gender?->value,
                'formatted_gender' => $purchase->formatted_gender,
            ],
            'studies' => $purchase->laboratoryPurchaseItems
                ->map(fn ($item) => [
                    'name' => $item->name,
                    'feature_list' => LaboratoryPurchaseConfirmationViewData::normalizePackageFeatureList($item->feature_list),
                    'indications' => filled($item->indications) ? $item->indications : null,
                    'price' => formattedCentsPrice((int) $item->price_cents),
                ])
                ->values()
                ->all(),
            'preparation' => $preparation,
            'pricing' => [
                'currency' => 'MXN',
                'subtotal' => formattedCentsPrice($subtotalCents),
                'coupon_discount' => $couponDiscountCents > 0 ? formattedCentsPrice($couponDiscountCents) : null,
                'total' => formattedCentsPrice($totalCents),
            ],
            'appointment' => [
                'requires_appointment' => $requiresAppointment,
                'has_appointment' => $hasAppointment,
                'scheduled' => $hasAppointment,
                'date' => $appointment?->appointment_date?->toIso8601String(),
                'formatted_date' => $appointmentDate?->isoFormat('dddd D [de] MMMM [de] YYYY'),
                'formatted_time' => $appointmentDate?->isoFormat('h:mm a'),
                'status' => $hasAppointment
                    ? ($appointment?->confirmed_at ? 'confirmed' : 'scheduled')
                    : ($requiresAppointment ? 'pending' : 'not_required'),
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
        ];
    }

    private function requiresAppointment(): bool
    {
        $purchase = $this->resource;
        $gdaIds = $purchase->laboratoryPurchaseItems
            ->pluck('gda_id')
            ->filter()
            ->map(fn ($gdaId) => (string) $gdaId)
            ->unique()
            ->values();

        if ($gdaIds->isEmpty()) {
            return false;
        }

        return LaboratoryTest::query()
            ->where('brand', $purchase->brand?->value ?? $purchase->brand)
            ->whereIn('gda_id', $gdaIds->all())
            ->where('requires_appointment', true)
            ->exists();
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function featuredStores(LaboratoryBrand $brand): array
    {
        return LaboratoryStore::query()
            ->where('brand', $brand->value)
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN NULLIF(TRIM(address), '') IS NULL THEN 1 ELSE 0 END")
            ->orderByRaw("CASE WHEN NULLIF(TRIM(google_maps_url), '') IS NULL THEN 1 ELSE 0 END")
            ->orderBy('name')
            ->limit(4)
            ->get(['name', 'address', 'phone', 'weekly_hours', 'saturday_hours', 'sunday_hours', 'google_maps_url'])
            ->map(fn (LaboratoryStore $store) => [
                'name' => $store->name,
                'address' => $store->address,
                'phone' => $store->phone,
                'hours' => $this->summaryHours($store),
                'google_maps_url' => $this->safePublicUrl($store->google_maps_url),
            ])
            ->values()
            ->all();
    }

    private function summaryHours(LaboratoryStore $store): ?string
    {
        $hours = collect([
            filled($store->weekly_hours) ? 'Lun-vie: '.$store->weekly_hours : null,
            filled($store->saturday_hours) ? 'Sáb: '.$store->saturday_hours : null,
            filled($store->sunday_hours) ? 'Dom: '.$store->sunday_hours : null,
        ])->filter()->implode(' · ');

        return filled($hours) ? $hours : null;
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
