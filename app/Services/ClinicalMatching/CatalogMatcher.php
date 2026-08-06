<?php

namespace App\Services\ClinicalMatching;

/**
 * Rank catalog candidates. Scores against name, short name, aliases and codes (match_texts).
 * Composite strategy — not SQL LIKE alone.
 */
class CatalogMatcher
{
    public function __construct(
        private TextNormalizer $normalizer,
        private SynonymCatalog $synonyms,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $catalog  CatalogItem::toArray() shapes
     * @return list<array{item: array<string, mixed>, score: float, reason: string}>
     */
    public function rank(string $detected, array $catalog, int $limit = 8): array
    {
        $variants = $this->buildVariants($detected);
        $scored = [];

        foreach ($catalog as $item) {
            $texts = $item['match_texts'] ?? [$item['name'] ?? ''];
            $best = ['score' => 0.0, 'reason' => 'none'];

            foreach ($texts as $text) {
                $itemNorm = $this->normalizer->normalize((string) $text);
                $result = $this->scoreAgainstVariants($itemNorm, $variants);
                if ($result['score'] > $best['score']) {
                    $best = $result;
                }
            }

            if ($best['score'] <= 0) {
                continue;
            }

            $scored[] = [
                'item' => $item,
                'score' => $best['score'],
                'reason' => $best['reason'],
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * @return list<string>
     */
    public function buildVariants(string $detected): array
    {
        $normalized = $this->normalizer->normalize($detected);
        $normalized = $this->normalizer->expandAbbreviations(
            $normalized,
            $this->synonyms->abbreviations()
        );

        return $this->synonyms->expandQuery($normalized);
    }

    /**
     * @param  list<string>  $variants
     * @return array{score: float, reason: string}
     */
    private function scoreAgainstVariants(string $itemNorm, array $variants): array
    {
        $bestScore = 0.0;
        $bestReason = 'none';

        foreach ($variants as $variant) {
            if ($variant === '' || $itemNorm === '') {
                continue;
            }

            if ($itemNorm === $variant) {
                return ['score' => 1.0, 'reason' => 'exact'];
            }

            if (str_starts_with($itemNorm, $variant) || str_starts_with($variant, $itemNorm)) {
                $score = 0.96;
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestReason = 'prefix';
                }
            }

            $tokenScore = $this->tokenOverlap($variant, $itemNorm);
            if ($tokenScore > $bestScore) {
                $bestScore = $tokenScore;
                $bestReason = 'tokens';
            }

            similar_text($variant, $itemNorm, $percent);
            $similarity = round($percent / 100, 4);

            if ($similarity > $bestScore) {
                $bestScore = $similarity;
                $bestReason = 'similarity';
            }
        }

        return ['score' => round($bestScore, 4), 'reason' => $bestReason];
    }

    private function tokenOverlap(string $a, string $b): float
    {
        $ta = preg_split('/\s+/u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tb = preg_split('/\s+/u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($ta === [] || $tb === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($ta, $tb));
        $union = count(array_unique(array_merge($ta, $tb)));

        if ($union === 0) {
            return 0.0;
        }

        return round($intersection / $union, 4);
    }

    public function statusFromScore(float $score): string
    {
        if ($score >= 0.92) {
            return 'exact';
        }

        if ($score >= 0.55) {
            return 'partial';
        }

        return 'not_found';
    }
}
