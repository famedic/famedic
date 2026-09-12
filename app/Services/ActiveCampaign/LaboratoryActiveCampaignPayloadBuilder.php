<?php

namespace App\Services\ActiveCampaign;

use App\Actions\Laboratories\GenerateLaboratoryCheckoutResumeLinkAction;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryStore;
use Illuminate\Support\Facades\Log;

class LaboratoryActiveCampaignPayloadBuilder
{
    /**
     * @return array<string, string|null>
     */
    public function forAbandonedCart(Cart $cart): array
    {
        $brand = $this->brandFromCart($cart);
        if (! $brand) {
            return [];
        }

        $fields = [
            'mapa_sucursales_labs' => $this->storesMapUrl($brand),
        ];

        try {
            $fields['url_finalizar_compra'] = app(GenerateLaboratoryCheckoutResumeLinkAction::class)
                ->forCart($cart, $brand);
        } catch (\Throwable $e) {
            Log::warning('AC lab fields: resume URL not generated for abandoned cart', [
                'cart_id' => $cart->id,
                'laboratory_brand' => $brand->value,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->withoutNulls($fields);
    }

    /**
     * @return array<string, string|null>
     */
    public function forPurchaseCompleted(LaboratoryPurchase $purchase): array
    {
        $brand = $purchase->brand instanceof LaboratoryBrand
            ? $purchase->brand
            : LaboratoryBrand::tryFrom((string) $purchase->brand);

        return $this->withoutNulls([
            'folio_famedic' => $purchase->gda_order_id ?: null,
            'gda_consecutivo' => $purchase->gda_consecutivo ? (string) $purchase->gda_consecutivo : null,
            'mapa_sucursales_labs' => $brand ? $this->storesMapUrl($brand) : null,
            'url_finalizar_compra' => '',
        ], keepEmptyStrings: true);
    }

    /**
     * @return array<string, string|null>
     */
    public function forAppointmentConfirmed(LaboratoryAppointment $appointment): array
    {
        $appointment->loadMissing('laboratoryStore');
        $store = $appointment->laboratoryStore;
        $brand = $appointment->brand instanceof LaboratoryBrand
            ? $appointment->brand
            : LaboratoryBrand::tryFrom((string) $appointment->brand);

        return $this->withoutNulls([
            'paciente_lab' => $appointment->patient_full_name,
            'sucursal_lab' => $store?->name,
            'google_maps_lab' => $store?->google_maps_url,
            'direccion_lab' => $store ? $this->formatStoreAddress($store) : null,
            'fecha_cita_lab' => $appointment->appointment_date?->format('Y-m-d'),
            'horario_cita_lab' => $appointment->appointment_date?->format('h:i A'),
            'mapa_sucursales_labs' => $brand ? $this->storesMapUrl($brand) : null,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function forSampleCompleted(LaboratoryPurchase $purchase): array
    {
        return ['toma_de_muestra_lab' => 'Sí'];
    }

    /**
     * @return array<string, string>
     */
    public function forResultsCompleted(LaboratoryPurchase $purchase): array
    {
        return ['resultados_lab' => 'Disponibles'];
    }

    public function storesMapUrl(LaboratoryBrand $brand): string
    {
        return route('laboratory-stores.index', ['brand' => $brand->value]);
    }

    private function brandFromCart(Cart $cart): ?LaboratoryBrand
    {
        if ($cart->type !== MonitoringCartType::Lab) {
            return null;
        }

        $brands = collect($cart->labBrands())
            ->pluck('value')
            ->filter()
            ->unique()
            ->values();

        if ($brands->count() !== 1) {
            return null;
        }

        return LaboratoryBrand::tryFrom((string) $brands->first());
    }

    private function formatStoreAddress(LaboratoryStore $store): ?string
    {
        if (filled($store->address)) {
            return trim((string) $store->address);
        }

        $parts = array_filter([
            $store->street,
            $store->neighborhood,
            $store->municipality,
            $store->state,
            $store->postal_code,
        ], static fn ($value) => filled($value));

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * @param  array<string, string|null>  $fields
     * @return array<string, string>
     */
    private function withoutNulls(array $fields, bool $keepEmptyStrings = false): array
    {
        return array_filter(
            $fields,
            static fn ($value) => $value !== null && ($keepEmptyStrings || $value !== '')
        );
    }
}
