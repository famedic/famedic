<?php

namespace App\Services\LaboratoryStores\Gda;

use App\Models\LaboratoryStore;
use App\Models\LaboratoryStoreImportRow;
use Illuminate\Support\Collection;

class GdaStoreMatcher
{
    public function __construct(
        private readonly GdaStringNormalizer $normalizer,
        private readonly GdaAddressNormalizer $addresses,
    ) {}

    public function match(GdaStoreRow|GdaSpecialServiceRow $row, ?string $brand, ?string $sourceName, array $planned): array
    {
        if ($brand === null || $sourceName === null || trim($sourceName) === '') {
            return [
                'matched_store_id' => null,
                'classification' => LaboratoryStoreImportRow::CLASSIFICATION_INVALID,
                'confidence' => 0,
                'action' => LaboratoryStoreImportRow::ACTION_SKIP,
                'diff' => [],
                'errors' => ['Missing brand or store name'],
                'evidence' => ['strength' => 'NONE', 'reason' => 'missing brand or store name'],
            ];
        }

        $normalizedName = $this->normalizer->normalize($sourceName);
        $stores = LaboratoryStore::query()
            ->withTrashed()
            ->where('brand', $brand)
            ->get();

        $active = $stores->reject(fn (LaboratoryStore $store) => $store->trashed());
        $trashed = $stores->filter(fn (LaboratoryStore $store) => $store->trashed());

        if ($store = $this->exactName($active, $sourceName, $normalizedName)) {
            return $this->matched($store, $planned, 100, [
                'strength' => 'STRONG',
                'name' => 'exact_or_normalized',
                'reason' => 'brand + exact/normalized name',
            ]);
        }

        if ($store = $this->exactName($trashed, $sourceName, $normalizedName)) {
            return [
                ...$this->matched($store, $planned, 95, [
                    'strength' => 'STRONG',
                    'name' => 'exact_or_normalized',
                    'reason' => 'soft-deleted brand + exact/normalized name',
                ]),
                'classification' => LaboratoryStoreImportRow::CLASSIFICATION_SOFT_DELETED_MATCH,
                'action' => LaboratoryStoreImportRow::ACTION_MANUAL_REVIEW,
            ];
        }

        $candidate = $this->bestCandidate($active, $normalizedName, $planned);

        if ($candidate !== null && in_array($candidate['evidence']['strength'], ['STRONG', 'MEDIUM', 'WEAK'], true)) {
            if ($candidate['evidence']['strength'] === 'STRONG') {
                return $this->matched($candidate['store'], $planned, $candidate['score'], $candidate['evidence']);
            }

            return [
                'matched_store_id' => $candidate['store']->id,
                'classification' => LaboratoryStoreImportRow::CLASSIFICATION_AMBIGUOUS,
                'confidence' => $candidate['score'],
                'action' => LaboratoryStoreImportRow::ACTION_MANUAL_REVIEW,
                'diff' => [
                    'candidate' => [
                        'id' => $candidate['store']->id,
                        'name' => $candidate['store']->name,
                        'address' => $candidate['store']->address,
                    ],
                    'planned' => $planned,
                    'reason' => $candidate['evidence'],
                ],
                'errors' => [],
                'evidence' => $candidate['evidence'],
            ];
        }

        return [
            'matched_store_id' => null,
            'classification' => LaboratoryStoreImportRow::CLASSIFICATION_NEW,
            'confidence' => 0,
            'action' => LaboratoryStoreImportRow::ACTION_CREATE,
            'diff' => ['planned' => $planned],
            'errors' => [],
            'evidence' => ['strength' => 'NONE', 'reason' => 'no exact, address, CP, municipality, token, soft-deleted, or fuzzy evidence'],
        ];
    }

    public function manualMatch(LaboratoryStore $store, array $planned, array $evidence): array
    {
        return $this->matched($store, $planned, 100, $evidence);
    }

    private function exactName(Collection $stores, string $sourceName, string $normalizedName): ?LaboratoryStore
    {
        return $stores->first(function (LaboratoryStore $store) use ($sourceName, $normalizedName) {
            return strcasecmp($store->name, $sourceName) === 0
                || $this->normalizer->normalize($store->name) === $normalizedName;
        });
    }

    private function matched(LaboratoryStore $store, array $planned, int $confidence, array $evidence): array
    {
        $diff = [];

        foreach (['name', 'state', 'address', 'postal_code', 'phone', 'latitude', 'longitude'] as $field) {
            $current = $store->{$field};
            $next = $planned[$field] ?? null;

            if ($next !== null && (string) $current !== (string) $next) {
                $diff[$field] = ['current' => $current, 'planned' => $next];
            }
        }

        return [
            'matched_store_id' => $store->id,
            'classification' => LaboratoryStoreImportRow::CLASSIFICATION_MATCHED,
            'confidence' => $confidence,
            'action' => $diff === [] ? LaboratoryStoreImportRow::ACTION_NONE : LaboratoryStoreImportRow::ACTION_UPDATE_CANDIDATE,
            'diff' => $diff,
            'errors' => [],
            'evidence' => $evidence,
        ];
    }

    private function bestCandidate(Collection $stores, string $normalizedName, array $planned): ?array
    {
        $best = null;

        foreach ($stores as $store) {
            $candidateName = $this->normalizer->normalize($store->name);
            $sourceTokens = array_filter(explode(' ', $normalizedName));
            $candidateTokens = array_filter(explode(' ', $candidateName));
            $overlap = count(array_intersect($sourceTokens, $candidateTokens));
            $nameSimilarity = $this->similarity($normalizedName, $candidateName);
            $addressSimilarity = $this->addresses->similarity($planned['address'] ?? null, $store->address);
            $samePostalCode = $this->addresses->containsPostalCode($store->address, $planned['postal_code'] ?? null);
            $sameMunicipality = $this->addresses->containsMunicipality($store->address, $planned['municipality'] ?? null);
            $sameState = $this->addresses->normalizeState($store->state) === $this->addresses->normalizeState($planned['state'] ?? null);
            $evidence = $this->evidence(
                $nameSimilarity,
                $overlap,
                $addressSimilarity,
                $samePostalCode,
                $sameMunicipality,
                $sameState,
            );
            $score = min(99, $nameSimilarity + ($overlap * 10) + ($samePostalCode ? 15 : 0) + ($sameMunicipality ? 10 : 0) + ($addressSimilarity >= 75 ? 10 : 0));

            if ($best === null || $score > $best['score']) {
                $best = [
                    'store' => $store,
                    'score' => $score,
                    'evidence' => [
                        ...$evidence,
                        'name_similarity_percent' => $nameSimilarity,
                        'shared_tokens' => $overlap,
                        'address_similarity_percent' => $addressSimilarity,
                        'same_postal_code' => $samePostalCode,
                        'same_municipality' => $sameMunicipality,
                        'same_state' => $sameState,
                    ],
                ];
            }
        }

        return $best;
    }

    private function similarity(string $left, string $right): int
    {
        if ($left === '' || $right === '') {
            return 0;
        }

        similar_text($left, $right, $percent);

        return (int) round($percent);
    }

    private function evidence(
        int $nameSimilarity,
        int $sharedTokens,
        int $addressSimilarity,
        bool $samePostalCode,
        bool $sameMunicipality,
        bool $sameState,
    ): array {
        if (
            ($nameSimilarity >= 90 && $addressSimilarity >= 80)
            || ($nameSimilarity >= 85 && $samePostalCode && $sameMunicipality)
        ) {
            return ['strength' => 'STRONG', 'reason' => 'equivalent name plus strong address/CP/municipality evidence'];
        }

        if (
            ($sharedTokens > 0 && ($samePostalCode || $sameMunicipality || $addressSimilarity >= 60 || $sameState))
            || ($nameSimilarity >= 70 && ($samePostalCode || $sameMunicipality))
            || $addressSimilarity >= 80
        ) {
            return ['strength' => 'MEDIUM', 'reason' => 'token/fuzzy evidence supported by CP, municipality, state, or address'];
        }

        if ($nameSimilarity >= 70 || $sharedTokens > 0) {
            return ['strength' => 'WEAK', 'reason' => 'fuzzy or token-only evidence'];
        }

        return ['strength' => 'NONE', 'reason' => 'no reasonable evidence'];
    }
}
