<?php

namespace App\Services\CommercialIntegration;

use App\Models\LaboratoryTest;
use App\Services\ClinicalMatching\TextNormalizer;
use Illuminate\Support\Collection;

/**
 * Detects when validated individual studies are covered by an existing catalog package (feature_list).
 */
class PackageDetector
{
    public function __construct(
        private TextNormalizer $normalizer,
    ) {}

    /**
     * @param  list<array{name: string, code?: string|null, laboratory_test_id?: int|null}>  $selectedStudies
     * @return list<array<string, mixed>>
     */
    public function detect(array $selectedStudies): array
    {
        if (count($selectedStudies) < 2) {
            return [];
        }

        $selectedNames = collect($selectedStudies)
            ->map(fn ($s) => $this->normalizer->normalize((string) ($s['name'] ?? '')))
            ->filter()
            ->unique()
            ->values();

        if ($selectedNames->count() < 2) {
            return [];
        }

        $individualCents = collect($selectedStudies)->sum(
            fn ($s) => (int) ($s['price_cents'] ?? 0)
        );

        /** @var Collection<int, LaboratoryTest> $packages */
        $packages = LaboratoryTest::query()
            ->select([
                'id',
                'name',
                'gda_id',
                'brand',
                'feature_list',
                'famedic_price_cents',
                'public_price_cents',
                'requires_appointment',
            ])
            ->whereNotNull('feature_list')
            ->limit(500)
            ->get()
            ->filter(function (LaboratoryTest $test) {
                return is_array($test->feature_list) && count($test->feature_list) > 0;
            });

        $matches = [];

        foreach ($packages as $package) {
            $features = collect($package->feature_list)
                ->map(fn ($f) => $this->normalizer->normalize((string) $f))
                ->filter()
                ->unique()
                ->values();

            if ($features->isEmpty()) {
                continue;
            }

            $covered = $selectedNames->every(
                fn (string $name) => $features->contains(
                    fn (string $feature) => $feature === $name
                        || str_contains($feature, $name)
                        || str_contains($name, $feature)
                )
            );

            if (! $covered) {
                continue;
            }

            $packageCents = (int) $package->famedic_price_cents;
            $savings = max(0, $individualCents - $packageCents);

            $matches[] = [
                'package_id' => $package->id,
                'name' => $package->name,
                'code' => $package->gda_id,
                'laboratory' => $package->brand?->label() ?? (string) $package->brand,
                'feature_count' => count($package->feature_list),
                'individual_price_cents' => $individualCents,
                'individual_price' => $this->formatCents($individualCents),
                'package_price_cents' => $packageCents,
                'package_price' => $this->formatCents($packageCents),
                'savings_cents' => $savings,
                'savings' => $this->formatCents($savings),
                'requires_appointment' => (bool) $package->requires_appointment,
                'message' => 'Este conjunto pertenece al paquete '.$package->name,
            ];
        }

        usort($matches, fn ($a, $b) => $b['savings_cents'] <=> $a['savings_cents']);

        return array_slice($matches, 0, 5);
    }

    private function formatCents(int $cents): string
    {
        if (function_exists('formattedCentsPrice')) {
            return formattedCentsPrice($cents);
        }

        return '$'.number_format($cents / 100, 2);
    }
}
