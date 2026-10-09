<?php

namespace App\Services\LaboratoryPreparation;

use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionCategory;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionParseResult;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionParseStudyResult;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionRecognitionStatus;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionRecognizerRules;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionRequirement;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionTextNormalizer;

class LaboratoryInstructionParser
{
    private const KNOWN_UNITS = [
        'hora', 'horas', 'h', 'dia', 'dias', 'día', 'días', 'semana', 'semanas',
        'mes', 'meses', 'minuto', 'minutos', 'ml', 'mililitros', 'l', 'litros',
        'g', 'gramos', 'mg', 'años', 'anos', 'año', 'ano', 'hr', 'hrs',
    ];

    /** @var list<string> */
    private const UNIT_FALSE_POSITIVES = [
        'no', 'de', 'en', 'y', 'a', 'el', 'la', 'los', 'las', 'un', 'una', 'del', 'al', 'con', 'por', 'para',
    ];

    public function __construct(
        private readonly LaboratoryInstructionTextNormalizer $normalizer = new LaboratoryInstructionTextNormalizer,
    ) {}

    /**
     * @param  list<array{id: int|string, name: string, gda_id?: string|null, indications?: string|null}>  $items
     */
    public function parseItems(array $items): LaboratoryInstructionParseResult
    {
        $studies = [];

        foreach ($items as $item) {
            $studyId = filled($item['gda_id'] ?? null)
                ? (string) $item['gda_id']
                : (string) ($item['id'] ?? '');

            $studies[] = $this->parseStudy(
                studyId: $studyId !== '' ? $studyId : null,
                studyName: (string) ($item['name'] ?? ''),
                indications: isset($item['indications']) ? (string) $item['indications'] : null,
                purchaseItemId: isset($item['id']) ? (string) $item['id'] : null,
            );
        }

        $hasUnrecognized = collect($studies)->contains(fn (LaboratoryInstructionParseStudyResult $study) => $study->hasUnrecognizedContent);

        return new LaboratoryInstructionParseResult($studies, $hasUnrecognized);
    }

    public function parseStudy(
        ?string $studyId,
        string $studyName,
        ?string $indications,
        ?string $purchaseItemId = null,
    ): LaboratoryInstructionParseStudyResult {
        $sourceText = trim((string) $indications);

        if ($sourceText === '') {
            return new LaboratoryInstructionParseStudyResult(
                studyId: $studyId,
                studyName: $studyName,
                sourceText: '',
                requirements: [],
                unrecognizedFragments: [],
                hasUnrecognizedContent: false,
                isEmptySource: true,
            );
        }

        $requirements = [];
        $unrecognizedFragments = [];

        foreach ($this->normalizer->splitClauses($sourceText) as $clause) {
            [$clauseRequirements, $clauseUnknown] = $this->parseClause(
                clause: $clause,
                fullSourceText: $sourceText,
                studyId: $studyId,
                studyName: $studyName,
                purchaseItemId: $purchaseItemId,
            );

            $requirements = [...$requirements, ...$clauseRequirements];
            $unrecognizedFragments = [...$unrecognizedFragments, ...$clauseUnknown];
        }

        $requirements = [...$requirements, ...$this->detectAmbiguousUnits($sourceText, $studyId, $studyName, $requirements)];

        $hasUnrecognized = $unrecognizedFragments !== []
            || collect($requirements)->contains(
                fn (LaboratoryInstructionRequirement $requirement) => in_array(
                    $requirement->recognitionStatus,
                    [
                        LaboratoryInstructionRecognitionStatus::UNRECOGNIZED,
                        LaboratoryInstructionRecognitionStatus::AMBIGUOUS,
                        LaboratoryInstructionRecognitionStatus::INCOMPLETE,
                    ],
                    true,
                ),
            );

        return new LaboratoryInstructionParseStudyResult(
            studyId: $studyId,
            studyName: $studyName,
            sourceText: $sourceText,
            requirements: $requirements,
            unrecognizedFragments: array_values(array_unique(array_filter($unrecognizedFragments))),
            hasUnrecognizedContent: $hasUnrecognized,
            isEmptySource: false,
        );
    }

    /**
     * @return array{0: list<LaboratoryInstructionRequirement>, 1: list<string>}
     */
    private function parseClause(
        string $clause,
        string $fullSourceText,
        ?string $studyId,
        string $studyName,
        ?string $purchaseItemId,
    ): array {
        $candidates = [];
        $matchSurface = $this->normalizer->normalizeAccentsOnly($clause);

        foreach (LaboratoryInstructionRecognizerRules::rules() as $rule) {
            $matchCount = preg_match_all($rule['pattern'], $matchSurface, $matches, PREG_OFFSET_CAPTURE);
            if ($matchCount === false || $matchCount === 0) {
                continue;
            }

            foreach ($matches[0] as $index => $match) {
                [$start, $end] = $this->byteCaptureToCharRange($matchSurface, (string) $match[0], (int) $match[1]);
                $span = mb_substr($clause, $start, $end - $start);
                if ($span === '') {
                    continue;
                }

                $matchValues = [];
                foreach ($matches as $key => $group) {
                    if ($key === 0) {
                        continue;
                    }

                    $matchValues[$key] = (string) ($group[$index][0] ?? '');
                }

                $built = ($rule['builder'])($matchValues);

                $candidates[] = [
                    'start' => $start,
                    'end' => $end,
                    'span' => $span,
                    'category' => $rule['category'],
                    'requirement_type' => $rule['requirement_type'],
                    'status' => $rule['status'],
                    'normalized_value' => $built['normalized_value'],
                    'unit' => $built['unit'],
                    'qualifiers' => $this->buildQualifiers($clause, $built['qualifiers'], $purchaseItemId),
                ];
            }
        }

        $selected = $this->selectNonOverlappingMatches($candidates);

        $requirements = [];
        foreach ($selected as $match) {
            $requirements[] = new LaboratoryInstructionRequirement(
                studyId: $studyId,
                studyName: $studyName,
                sourceText: $fullSourceText,
                sourceSpan: $match['span'],
                sourceSpanStart: $this->absoluteOffset($fullSourceText, $clause, $match['start']),
                sourceSpanEnd: $this->absoluteOffset($fullSourceText, $clause, $match['end']),
                category: $match['category'],
                requirementType: $match['requirement_type'],
                normalizedValue: $match['normalized_value'],
                unit: $match['unit'],
                qualifiers: $match['qualifiers'],
                recognitionStatus: $match['status'],
            );
        }

        $unknownFragments = $this->uncoveredFragmentRanges($matchSurface, $selected, $clause);

        foreach ($unknownFragments as $fragment) {
            $requirements[] = new LaboratoryInstructionRequirement(
                studyId: $studyId,
                studyName: $studyName,
                sourceText: $fullSourceText,
                sourceSpan: $fragment['text'],
                sourceSpanStart: $this->absoluteOffset($fullSourceText, $clause, $fragment['start']),
                sourceSpanEnd: $this->absoluteOffset($fullSourceText, $clause, $fragment['end']),
                category: LaboratoryInstructionCategory::UNKNOWN,
                requirementType: 'unrecognized_fragment',
                normalizedValue: ['text' => $fragment['text']],
                unit: null,
                qualifiers: $this->buildQualifiers($clause, [], $purchaseItemId),
                recognitionStatus: LaboratoryInstructionRecognitionStatus::UNRECOGNIZED,
            );
        }

        return [$requirements, array_column($unknownFragments, 'text')];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function selectNonOverlappingMatches(array $candidates): array
    {
        usort($candidates, function (array $left, array $right): int {
            $lengthLeft = $left['end'] - $left['start'];
            $lengthRight = $right['end'] - $right['start'];

            if ($lengthLeft !== $lengthRight) {
                return $lengthRight <=> $lengthLeft;
            }

            return $left['start'] <=> $right['start'];
        });

        $selected = [];

        foreach ($candidates as $candidate) {
            $overlaps = false;

            foreach ($selected as $existing) {
                if ($candidate['start'] < $existing['end'] && $candidate['end'] > $existing['start']) {
                    $overlaps = true;
                    break;
                }
            }

            if (! $overlaps) {
                $selected[] = $candidate;
            }
        }

        usort($selected, fn (array $left, array $right) => $left['start'] <=> $right['start']);

        return $selected;
    }

    /**
     * @param  list<array<string, mixed>>  $selected
     * @return list<array{start: int, end: int, text: string}>
     */
    private function uncoveredFragmentRanges(string $matchSurface, array $selected, string $originalClause): array
    {
        if ($selected === []) {
            $trimmed = trim($originalClause);
            if ($trimmed === '') {
                return [];
            }

            $start = mb_strpos($originalClause, $trimmed);
            if ($start === false) {
                $start = 0;
            }

            return [[
                'start' => $start,
                'end' => $start + mb_strlen($trimmed),
                'text' => $trimmed,
            ]];
        }

        $cursor = 0;
        $fragments = [];
        $length = mb_strlen($matchSurface);

        foreach ($selected as $match) {
            if ($match['start'] > $cursor) {
                $this->pushFragmentRange($fragments, $originalClause, $cursor, (int) $match['start']);
            }

            $cursor = max($cursor, (int) $match['end']);
        }

        if ($cursor < $length) {
            $this->pushFragmentRange($fragments, $originalClause, $cursor, $length);
        }

        return $fragments;
    }

    /**
     * @param  list<array{start: int, end: int, text: string}>  $fragments
     */
    private function pushFragmentRange(array &$fragments, string $clause, int $rangeStart, int $rangeEnd): void
    {
        $raw = mb_substr($clause, $rangeStart, $rangeEnd - $rangeStart);
        $trimmed = trim($raw);

        if (! $this->isSignificantFragment($trimmed)) {
            return;
        }

        $leading = mb_strlen($raw) - mb_strlen(ltrim($raw));
        $trailing = mb_strlen($raw) - mb_strlen(rtrim($raw));
        $start = $rangeStart + $leading;
        $end = $rangeEnd - $trailing;

        $fragments[] = [
            'start' => $start,
            'end' => $end,
            'text' => mb_substr($clause, $start, $end - $start),
        ];
    }

    /**
     * @param  list<LaboratoryInstructionRequirement>  $existing
     * @return list<LaboratoryInstructionRequirement>
     */
    private function detectAmbiguousUnits(
        string $sourceText,
        ?string $studyId,
        string $studyName,
        array $existing,
    ): array {
        $requirements = [];

        $matchCount = preg_match_all('/\b(\d+(?:[.,]\d+)?)\s+([a-záéíóúñ]{2,})\b/iu', $sourceText, $matches, PREG_OFFSET_CAPTURE);
        if ($matchCount === false || $matchCount === 0) {
            return [];
        }

        foreach ($matches[2] as $index => $unitMatch) {
            $unitText = mb_strtolower((string) $unitMatch[0]);
            $unitText = str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], $unitText);

            if (in_array($unitText, self::KNOWN_UNITS, true) || in_array($unitText, self::UNIT_FALSE_POSITIVES, true)) {
                continue;
            }

            $number = (string) $matches[1][$index][0];
            $span = $number.' '.$unitMatch[0];
            [$spanStart, $spanEnd] = $this->byteCaptureToCharRange(
                $sourceText,
                $span,
                (int) $matches[1][$index][1],
            );

            $alreadyCovered = collect($existing)->contains(
                fn (LaboratoryInstructionRequirement $requirement) => $requirement->requirementType !== 'unrecognized_fragment'
                    && $requirement->recognitionStatus !== LaboratoryInstructionRecognitionStatus::UNRECOGNIZED
                    && str_contains($requirement->sourceSpan, $span),
            );

            if ($alreadyCovered) {
                continue;
            }

            $requirements[] = new LaboratoryInstructionRequirement(
                studyId: $studyId,
                studyName: $studyName,
                sourceText: $sourceText,
                sourceSpan: $span,
                sourceSpanStart: $spanStart,
                sourceSpanEnd: $spanEnd,
                category: LaboratoryInstructionCategory::UNKNOWN,
                requirementType: 'unknown_unit',
                normalizedValue: [
                    'amount' => (float) str_replace(',', '.', $number),
                    'unit_text' => (string) $unitMatch[0],
                ],
                unit: null,
                qualifiers: [],
                recognitionStatus: LaboratoryInstructionRecognitionStatus::AMBIGUOUS,
            );
        }

        return $requirements;
    }

    /**
     * @param  array<string, mixed>  $qualifiers
     * @return array<string, mixed>
     */
    private function buildQualifiers(string $clause, array $qualifiers, ?string $purchaseItemId): array
    {
        if ($purchaseItemId !== null) {
            $qualifiers['purchase_item_id'] = $purchaseItemId;
        }

        $normalizedClause = $this->normalizer->normalizeForDetection($clause);

        if (preg_match('/\bno\s+(?!requiere\b|tomar\b|estar\b|tener\b|consumir\b|realizar\b)/u', $normalizedClause)) {
            $qualifiers['contains_negation'] = true;
        }

        return $qualifiers;
    }

    private function isSignificantFragment(string $fragment): bool
    {
        $clean = trim($fragment, " \t\n\r\0\x0B.,;:-");
        $normalized = mb_strtolower($clean);

        if (mb_strlen($clean) < 3) {
            return false;
        }

        if (in_array($normalized, self::UNIT_FALSE_POSITIVES, true)) {
            return false;
        }

        return true;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function byteCaptureToCharRange(string $haystack, string $matchedText, int $byteOffset): array
    {
        $start = mb_strlen(substr($haystack, 0, $byteOffset), 'UTF-8');
        $end = $start + mb_strlen($matchedText, 'UTF-8');

        return [$start, $end];
    }

    private function absoluteOffset(string $fullSource, string $clause, int $clauseOffset): int
    {
        if ($clauseOffset < 0) {
            return 0;
        }

        $position = mb_strpos($fullSource, $clause);

        if ($position === false) {
            return $clauseOffset;
        }

        return $position + $clauseOffset;
    }
}
