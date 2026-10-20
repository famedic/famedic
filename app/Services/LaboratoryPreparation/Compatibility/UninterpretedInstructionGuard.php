<?php

namespace App\Services\LaboratoryPreparation\Compatibility;

use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionCategory;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionParseStudyResult;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionRecognitionStatus;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionRequirement;

final class UninterpretedInstructionGuard
{
    /** @var list<string> */
    private const INNOCUOUS_TOKENS = [
        'no', 'de', 'en', 'y', 'a', 'el', 'la', 'los', 'las', 'un', 'una', 'del', 'al', 'con', 'por', 'para',
        'su', 'sus', 'se', 'es', 'o', 'u', 'ni', 'que', 'the', 'and',
    ];

    /** @var list<string> */
    private const SAFE_PARTIAL_REQUIREMENT_TYPES = [
        'contrast_study',
        'cytology_prep',
        'fasting_present',
        'urine_general',
        'collect_urine',
    ];

    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     * @return array{reason: string, trace: array<string, mixed>}|null
     */
    public function detectBlockingContent(array $studies): ?array
    {
        foreach ($studies as $study) {
            if ($study->isEmptySource) {
                continue;
            }

            foreach ($study->requirements as $requirement) {
                $blocking = $this->blockingRequirement($requirement, $study);
                if ($blocking !== null) {
                    return [
                        'reason' => $blocking,
                        'trace' => [
                            'study_id' => $study->studyId,
                            'source_span' => $requirement->sourceSpan,
                            'requirement_type' => $requirement->requirementType,
                        ],
                    ];
                }
            }

            if ($this->shouldScanForCoverageGaps($study)) {
                foreach ($this->significantUncoveredGaps($study) as $gap) {
                    return [
                        'reason' => 'uninterpreted_instruction_fragment',
                        'trace' => [
                            'study_id' => $study->studyId,
                            'gap_text' => $gap,
                        ],
                    ];
                }
            }
        }

        return null;
    }

    private function blockingRequirement(
        LaboratoryInstructionRequirement $requirement,
        LaboratoryInstructionParseStudyResult $study,
    ): ?string {
        if ($requirement->requirementType === 'unrecognized_fragment') {
            if ($this->isInnocuousFragment($requirement->sourceSpan)) {
                return null;
            }

            if ($this->isBenignParserRemainder($requirement, $study)) {
                return null;
            }

            return 'uninterpreted_instruction_fragment';
        }

        if ($requirement->recognitionStatus === LaboratoryInstructionRecognitionStatus::AMBIGUOUS) {
            return 'ambiguous_instruction_fragment';
        }

        if (
            $requirement->recognitionStatus === LaboratoryInstructionRecognitionStatus::PARTIAL
            && ! in_array($requirement->requirementType, self::SAFE_PARTIAL_REQUIREMENT_TYPES, true)
            && $this->partialInstructionNeedsFallback($requirement)
        ) {
            return 'uninterpreted_instruction_fragment';
        }

        if ($requirement->category === LaboratoryInstructionCategory::MEDICATION) {
            $kind = (string) ($requirement->normalizedValue['kind'] ?? '');
            if ($kind === 'medication_change' && ($requirement->normalizedValue['detail_unspecified'] ?? false)) {
                return 'medication_not_fully_structured';
            }
        }

        return null;
    }

    private function shouldScanForCoverageGaps(LaboratoryInstructionParseStudyResult $study): bool
    {
        $hasUnrecognized = collect($study->requirements)
            ->contains(fn (LaboratoryInstructionRequirement $requirement) => $requirement->requirementType === 'unrecognized_fragment');

        if ($hasUnrecognized) {
            return false;
        }

        return $this->recognizedSpanCoverageRatio($study) < 0.82;
    }

    private function recognizedSpanCoverageRatio(LaboratoryInstructionParseStudyResult $study): float
    {
        $length = mb_strlen($study->sourceText);
        if ($length === 0) {
            return 1.0;
        }

        $coveredLength = 0;
        foreach ($study->requirements as $requirement) {
            if ($requirement->requirementType === 'unrecognized_fragment') {
                continue;
            }

            if ($requirement->sourceSpanEnd <= $requirement->sourceSpanStart) {
                continue;
            }

            $coveredLength += $requirement->sourceSpanEnd - $requirement->sourceSpanStart;
        }

        return min(1.0, $coveredLength / $length);
    }

    /**
     * @return list<string>
     */
    private function significantUncoveredGaps(LaboratoryInstructionParseStudyResult $study): array
    {
        $covered = collect($study->requirements)
            ->filter(fn (LaboratoryInstructionRequirement $requirement) => $requirement->requirementType !== 'unrecognized_fragment')
            ->filter(fn (LaboratoryInstructionRequirement $requirement) => $requirement->sourceSpanEnd > $requirement->sourceSpanStart)
            ->map(fn (LaboratoryInstructionRequirement $requirement) => [
                'start' => $requirement->sourceSpanStart,
                'end' => $requirement->sourceSpanEnd,
            ])
            ->sortBy('start')
            ->values()
            ->all();

        $covered = $this->mergeAdjacentCoverageRanges($covered);

        if ($covered === []) {
            $trimmed = trim($study->sourceText);

            return $trimmed !== '' && ! $this->isInnocuousFragment($trimmed) && $this->containsRelevanceSignal($trimmed)
                ? [$trimmed]
                : [];
        }

        $gaps = [];
        $cursor = 0;
        $length = mb_strlen($study->sourceText);

        foreach ($covered as $range) {
            if ($range['start'] > $cursor) {
                $this->pushGap($gaps, $study->sourceText, $cursor, (int) $range['start']);
            }
            $cursor = max($cursor, (int) $range['end']);
        }

        if ($cursor < $length) {
            $this->pushGap($gaps, $study->sourceText, $cursor, $length);
        }

        return $gaps;
    }

    /**
     * @param  list<string>  $gaps
     */
    private function pushGap(array &$gaps, string $sourceText, int $start, int $end): void
    {
        $text = trim(mb_substr($sourceText, $start, $end - $start));
        if ($text === '' || $this->isInnocuousFragment($text)) {
            return;
        }

        if (mb_strlen($text) < 18) {
            return;
        }

        if ($this->containsHighRiskUninterpretedSignal($text)) {
            $gaps[] = $text;
        }
    }

    /**
     * @param  list<array{start: int, end: int}>  $ranges
     * @return list<array{start: int, end: int}>
     */
    private function mergeAdjacentCoverageRanges(array $ranges): array
    {
        if ($ranges === []) {
            return [];
        }

        $merged = [$ranges[0]];

        for ($index = 1; $index < count($ranges); $index++) {
            $current = $ranges[$index];
            $lastIndex = count($merged) - 1;
            $last = $merged[$lastIndex];

            if ($current['start'] <= $last['end'] + 4) {
                $merged[$lastIndex]['end'] = max($last['end'], $current['end']);

                continue;
            }

            $merged[] = $current;
        }

        return $merged;
    }

    private function partialInstructionNeedsFallback(LaboratoryInstructionRequirement $requirement): bool
    {
        if ($requirement->category === LaboratoryInstructionCategory::SAMPLE_TIMING) {
            return true;
        }

        return $this->containsHighRiskUninterpretedSignal($requirement->sourceSpan);
    }

    private function isInnocuousFragment(string $fragment): bool
    {
        $clean = trim($fragment, " \t\n\r\0\x0B.,;:-–—");
        if ($clean === '') {
            return true;
        }

        $tokens = preg_split('/\s+/u', mb_strtolower($clean)) ?: [];
        $tokens = array_values(array_filter($tokens, fn (string $token) => $token !== ''));

        if ($tokens === []) {
            return true;
        }

        foreach ($tokens as $token) {
            if (! in_array($token, self::INNOCUOUS_TOKENS, true) && mb_strlen($token) >= 3) {
                return false;
            }
        }

        return true;
    }

    private function containsHighRiskUninterpretedSignal(string $text): bool
    {
        return (bool) preg_match(
            '/\b(?:repetir\s+muestra|refrigeraci[oó]n|autorizaci[oó]n\s+especial|suplemento\s+herbal|'
            .'xyz-999|no\s+documentad|no\s+listad|cardi[oó]logo|semanas?\s+(?:durante|de)|'
            .'cada\s+\d+\s+horas?|medicamento\s+[a-z0-9\-]{3,}|axilas?|desodorante|crema|perfume|'
            .'enemas?|dieta|nulytely|laxober[oó]n|preparaci[oó]n\s+intestinal)\b/iu',
            $text,
        );
    }

    private function isBenignParserRemainder(
        LaboratoryInstructionRequirement $requirement,
        LaboratoryInstructionParseStudyResult $study,
    ): bool {
        $span = trim($requirement->sourceSpan);
        if ($span === '' || $this->containsHighRiskUninterpretedSignal($span)) {
            return false;
        }

        if (preg_match('/^(?:del\s+(?:estudio|examen)\.?|menstrual\.?|Acudir|Recolectar|Se\s+requiere)$/iu', $span)) {
            return true;
        }

        if (mb_strlen($span) <= 22 && $this->isTailOfRecognizedRequirement($requirement, $study)) {
            return true;
        }

        if (mb_strlen($span) <= 18 && preg_match('/^(?:de\s+\d+\s+horas?\.|\),\s*debera)$/iu', $span)) {
            return true;
        }

        return false;
    }

    private function isTailOfRecognizedRequirement(
        LaboratoryInstructionRequirement $requirement,
        LaboratoryInstructionParseStudyResult $study,
    ): bool {
        foreach ($study->requirements as $other) {
            if ($other->requirementType === 'unrecognized_fragment') {
                continue;
            }

            if ($other->sourceSpanEnd > $requirement->sourceSpanStart) {
                continue;
            }

            if ($requirement->sourceSpanStart - $other->sourceSpanEnd > 6) {
                continue;
            }

            $combined = mb_substr(
                $study->sourceText,
                $other->sourceSpanStart,
                $requirement->sourceSpanEnd - $other->sourceSpanStart,
            );

            if (trim($combined) !== '' && ! $this->containsHighRiskUninterpretedSignal($combined)) {
                return true;
            }
        }

        return false;
    }
}
