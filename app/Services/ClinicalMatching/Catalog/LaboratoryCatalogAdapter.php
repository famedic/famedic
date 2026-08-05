<?php

namespace App\Services\ClinicalMatching\Catalog;

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryTest;
use App\Services\ClinicalMatching\TextNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Adapter over Famedic laboratory_tests. Isolates Eloquent from the Matching Engine.
 */
class LaboratoryCatalogAdapter implements CatalogAdapterInterface
{
    public function __construct(
        private TextNormalizer $normalizer,
    ) {}

    public function supports(string $type): bool
    {
        return in_array($type, ['laboratory', 'all'], true);
    }

    /**
     * @param  list<string>  $searchTerms
     * @return list<CatalogItem>
     */
    public function searchCandidates(array $searchTerms, string $type, int $limit = 40): array
    {
        if (! $this->supports($type) || $type === 'medication') {
            return [];
        }

        $tokens = $this->buildTokens($searchTerms);

        if ($tokens === []) {
            return [];
        }

        /** @var Collection<int, LaboratoryTest> $rows */
        $rows = LaboratoryTest::query()
            ->select([
                'id',
                'name',
                'other_name',
                'gda_id',
                'elements',
                'brand',
                'public_price_cents',
                'famedic_price_cents',
                'requires_appointment',
                'deleted_at',
            ])
            ->where(function (Builder $query) use ($tokens) {
                foreach ($tokens as $token) {
                    $like = '%'.$token.'%';
                    $query->orWhere(function (Builder $inner) use ($like) {
                        $inner->where('name', 'like', $like)
                            ->orWhere('other_name', 'like', $like)
                            ->orWhere('gda_id', 'like', $like)
                            ->orWhere('elements', 'like', $like);
                    });
                }
            })
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return $rows
            ->map(fn (LaboratoryTest $test) => $this->mapTest($test))
            ->all();
    }

    /**
     * @param  list<string>  $searchTerms
     * @return list<string>
     */
    private function buildTokens(array $searchTerms): array
    {
        $tokens = [];

        foreach ($searchTerms as $term) {
            $normalized = $this->normalizer->normalize((string) $term);
            if ($normalized === '') {
                continue;
            }

            $tokens[] = $normalized;

            foreach (preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                if (mb_strlen($part) >= 2) {
                    $tokens[] = $part;
                }
            }
        }

        $tokens = array_values(array_unique($tokens));

        // Prefer longer tokens first (better SQL selectivity for common words)
        usort($tokens, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return array_slice($tokens, 0, 8);
    }

    private function mapTest(LaboratoryTest $test): CatalogItem
    {
        $aliases = [];
        if (filled($test->other_name)) {
            $aliases[] = (string) $test->other_name;
        }

        if (filled($test->elements)) {
            foreach (preg_split('/[,;\|\/]+/u', (string) $test->elements) ?: [] as $chunk) {
                $chunk = trim($chunk);
                if ($chunk !== '') {
                    $aliases[] = $chunk;
                }
            }
        }

        $aliases = array_values(array_unique($aliases));

        $brandLabel = $test->brand instanceof LaboratoryBrand
            ? $test->brand->label()
            : (string) $test->brand;

        $matchTexts = array_values(array_filter(array_unique(array_merge(
            [$test->name, $test->other_name, $test->gda_id],
            $aliases,
        ))));

        $priceCents = $test->famedic_price_cents;
        $price = function_exists('formattedCentsPrice')
            ? formattedCentsPrice($priceCents)
            : ('$'.number_format($priceCents / 100, 2));

        return new CatalogItem(
            id: 'lab-'.$test->id,
            type: 'laboratory',
            name: $test->name,
            shortName: $test->other_name,
            aliases: $aliases,
            code: (string) $test->gda_id,
            price: $price,
            priceCents: $priceCents,
            deliveryTime: $test->requires_appointment
                ? 'Requiere cita'
                : 'Según laboratorio',
            laboratory: $brandLabel,
            available: true,
            matchTexts: $matchTexts,
            brand: $brandLabel,
        );
    }
}
