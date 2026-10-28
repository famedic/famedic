<?php

namespace App\Services\LaboratoryResults\Extraction;

class LaboratoryResultVisionFallbackEvaluator
{
    /**
     * @param  array{
     *     candidates: list<LaboratoryResultObservationCandidate>,
     *     publishable: list<array<string, mixed>>,
     *     rejected_count: int,
     *     validation_errors: list<array<string, mixed>>,
     *     confidence_overall: ?float
     * }  $validationSummary
     */
    public function shouldFallback(
        LaboratoryResultTextMetrics $metrics,
        array $validationSummary,
        bool $textExtractionSucceeded,
    ): bool {
        if (! config('laboratory-results.vision_extraction.enabled', false)) {
            return false;
        }

        if (! $textExtractionSucceeded) {
            return true;
        }

        $fallback = config('laboratory-results.vision_extraction.fallback', []);

        $minCharacters = (int) ($fallback['min_characters'] ?? 50);
        $minTextDensity = (float) ($fallback['min_text_density'] ?? 0.5);
        $minObservations = (int) ($fallback['min_observations'] ?? 1);
        $minConfidence = (float) ($fallback['min_confidence'] ?? 0.75);

        if ($metrics->totalCharacters < $minCharacters) {
            return true;
        }

        if ($metrics->textDensity < $minTextDensity) {
            return true;
        }

        $observationCount = count($validationSummary['candidates']);

        if ($observationCount < $minObservations) {
            return true;
        }

        $publishableCount = count($validationSummary['publishable']);

        if ($publishableCount < $minObservations) {
            return true;
        }

        $confidence = $validationSummary['confidence_overall'];

        if ($confidence !== null && $confidence < $minConfidence) {
            return true;
        }

        return false;
    }

    /**
     * @param  array{
     *     candidates: list<LaboratoryResultObservationCandidate>,
     *     publishable: list<array<string, mixed>>,
     *     rejected_count: int,
     *     validation_errors: list<array<string, mixed>>,
     *     confidence_overall: ?float
     * }  $validationSummary
     * @return list<string>
     */
    public function resolveFallbackReasons(
        LaboratoryResultTextMetrics $metrics,
        array $validationSummary,
        bool $textExtractionSucceeded,
    ): array {
        if (! config('laboratory-results.vision_extraction.enabled', false)) {
            return ['vision_disabled'];
        }

        $reasons = [];

        if (! $textExtractionSucceeded) {
            $reasons[] = 'text_extraction_failed';
        }

        $fallback = config('laboratory-results.vision_extraction.fallback', []);

        $minCharacters = (int) ($fallback['min_characters'] ?? 50);
        $minTextDensity = (float) ($fallback['min_text_density'] ?? 0.5);
        $minObservations = (int) ($fallback['min_observations'] ?? 1);
        $minConfidence = (float) ($fallback['min_confidence'] ?? 0.75);

        if ($metrics->totalCharacters < $minCharacters) {
            $reasons[] = 'low_character_count';
        }

        if ($metrics->textDensity < $minTextDensity) {
            $reasons[] = 'low_text_density';
        }

        if (count($validationSummary['candidates']) < $minObservations) {
            $reasons[] = 'insufficient_observations';
        }

        if (count($validationSummary['publishable']) < $minObservations) {
            $reasons[] = 'insufficient_publishable_observations';
        }

        $confidence = $validationSummary['confidence_overall'];

        if ($confidence !== null && $confidence < $minConfidence) {
            $reasons[] = 'low_confidence';
        }

        return $reasons === [] ? ['fallback_not_required'] : array_values(array_unique($reasons));
    }
}
