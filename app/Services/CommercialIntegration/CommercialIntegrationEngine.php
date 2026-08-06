<?php

namespace App\Services\CommercialIntegration;

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryTest;

/**
 * Builds a commercial proposal from human-validated interpretation lines.
 * Does not touch OpenAI, Matching, or Validation layers.
 * Does not execute checkout/payment.
 */
class CommercialIntegrationEngine
{
    public function __construct(
        private PackageDetector $packages,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $validatedItems
     * @return array<string, mixed>
     */
    public function buildProposal(array $validatedItems, ?string $sessionId = null): array
    {
        $laboratories = [];
        $pharmacy = [];

        foreach ($validatedItems as $item) {
            $status = $item['validation_status'] ?? null;
            if (! in_array($status, ['confirmed', 'corrected'], true)) {
                continue;
            }

            $type = $item['type'] ?? 'laboratory';

            if ($type === 'medication') {
                $pharmacy[] = $this->mapPharmacyPlaceholder($item);
                continue;
            }

            $line = $this->mapLaboratoryLine($item);
            if ($line) {
                $laboratories[] = $line;
            }
        }

        $labSubtotal = collect($laboratories)->sum('price_cents');
        $pharmacySubtotal = collect($pharmacy)->sum(fn ($p) => (int) ($p['price_cents'] ?? 0));
        $subtotal = $labSubtotal + $pharmacySubtotal;
        $discount = 0;
        $total = max(0, $subtotal - $discount);

        $packageSuggestions = $this->packages->detect(
            array_map(fn ($l) => [
                'name' => $l['name'],
                'code' => $l['code'],
                'price_cents' => $l['price_cents'],
                'laboratory_test_id' => $l['laboratory_test_id'],
            ], $laboratories)
        );

        return [
            'meta' => [
                'module' => 'Commercial Integration Engine',
                'version' => '0.1.0',
                'session_id' => $sessionId,
                'note' => 'Propuesta comercial preparada. Sin checkout ni pagos en este sprint.',
                'checkout_ready' => false,
                'pharmacy_integrated' => false,
            ],
            'summary' => [
                'studies_count' => count($laboratories),
                'medications_count' => count($pharmacy),
                'subtotal_cents' => $subtotal,
                'subtotal' => $this->formatCents($subtotal),
                'discounts_cents' => $discount,
                'discounts' => $this->formatCents($discount),
                'total_cents' => $total,
                'total' => $this->formatCents($total),
                'price_total' => $this->formatCents($total),
            ],
            'groups' => [
                'laboratories' => $laboratories,
                'pharmacy' => $pharmacy,
            ],
            'packages' => $packageSuggestions,
            'actions' => [
                'create_quote' => [
                    'id' => 'create_quote',
                    'label' => 'Crear Cotización',
                    'enabled_when_validated' => true,
                    'implemented' => 'prepare_only',
                ],
                'add_to_cart' => [
                    'id' => 'add_to_cart',
                    'label' => 'Agregar al Carrito',
                    'enabled_when_validated' => true,
                    'implemented' => 'prepare_only',
                ],
                'save_draft' => [
                    'id' => 'save_draft',
                    'label' => 'Guardar como borrador',
                    'enabled_when_validated' => true,
                    'implemented' => true,
                ],
            ],
            'cart_payload' => [
                'laboratory_test_ids' => array_values(array_filter(array_column($laboratories, 'laboratory_test_id'))),
                'items' => array_map(fn ($l) => [
                    'laboratory_test_id' => $l['laboratory_test_id'],
                    'gda_id' => $l['code'],
                    'name' => $l['name'],
                    'price_cents' => $l['price_cents'],
                    'brand' => $l['laboratory'],
                ], $laboratories),
            ],
            'quote_payload' => [
                'items' => array_map(fn ($l) => [
                    'gda_id' => $l['code'],
                    'name' => $l['name'],
                    'price_cents' => $l['price_cents'],
                    'quantity' => 1,
                    'laboratory_test_id' => $l['laboratory_test_id'],
                    'requires_appointment' => $l['requires_appointment'],
                ], $laboratories),
                'subtotal_cents' => $labSubtotal,
                'discount_cents' => 0,
                'total_cents' => $labSubtotal,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function mapLaboratoryLine(array $item): ?array
    {
        $match = $item['match'] ?? [];
        $catalogId = $item['selected_catalog_id'] ?? ($match['catalog_id'] ?? null);
        $testId = $this->extractTestId($catalogId);

        $test = $testId
            ? LaboratoryTest::query()->find($testId)
            : null;

        if ($test) {
            $brand = $test->brand instanceof LaboratoryBrand
                ? $test->brand->label()
                : (string) $test->brand;

            return [
                'detection_id' => $item['detection_id'] ?? null,
                'laboratory_test_id' => $test->id,
                'name' => $test->name,
                'detected_name' => $item['detected_name'] ?? null,
                'code' => (string) $test->gda_id,
                'laboratory' => $brand,
                'price_cents' => (int) $test->famedic_price_cents,
                'price' => $this->formatCents((int) $test->famedic_price_cents),
                'delivery_time' => $test->requires_appointment ? 'Requiere cita' : 'Según laboratorio',
                'requires_appointment' => (bool) $test->requires_appointment,
                'available' => true,
            ];
        }

        // Fallback to match snapshot (identity only — never client prices)
        $name = $match['name'] ?? $item['detected_name'] ?? null;
        if (! $name) {
            return null;
        }

        return [
            'detection_id' => $item['detection_id'] ?? null,
            'laboratory_test_id' => $testId,
            'name' => $name,
            'detected_name' => $item['detected_name'] ?? null,
            'code' => $match['code'] ?? $match['sku'] ?? null,
            'laboratory' => $match['laboratory'] ?? $match['brand'] ?? null,
            'price_cents' => 0,
            'price' => $this->formatCents(0),
            'delivery_time' => $match['delivery_time'] ?? 'Según laboratorio',
            'requires_appointment' => false,
            'available' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function mapPharmacyPlaceholder(array $item): array
    {
        $match = $item['match'] ?? [];

        return [
            'detection_id' => $item['detection_id'] ?? null,
            'name' => $match['name'] ?? $item['detected_name'] ?? 'Medicamento',
            'detected_name' => $item['detected_name'] ?? null,
            'sku' => $match['sku'] ?? $match['code'] ?? null,
            'price_cents' => null,
            'price' => null,
            'available' => false,
            'placeholder' => true,
            'note' => 'Farmacia pendiente de integración (Vitau).',
        ];
    }

    private function extractTestId(mixed $catalogId): ?int
    {
        if ($catalogId === null) {
            return null;
        }

        if (is_numeric($catalogId)) {
            return (int) $catalogId;
        }

        if (is_string($catalogId) && preg_match('/^lab-(\d+)$/', $catalogId, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function formatCents(int $cents): string
    {
        if (function_exists('formattedCentsPrice')) {
            return formattedCentsPrice($cents);
        }

        return '$'.number_format($cents / 100, 2);
    }
}
