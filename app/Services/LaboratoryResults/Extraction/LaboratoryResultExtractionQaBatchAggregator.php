<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryResultExtractionQaDocumentCategory;

class LaboratoryResultExtractionQaBatchAggregator
{
    /**
     * @param  list<LaboratoryResultExtractionQaBatchDocumentResult>  $documents
     */
    public function aggregate(array $documents): LaboratoryResultExtractionQaBatchResult
    {
        $totalVersions = count($documents);
        $versionsWithText = 0;
        $versionsWithVision = 0;
        $totalComparablePairs = 0;
        $totalMatch = 0;
        $totalConflict = 0;
        $totalTextOnly = 0;
        $totalVisionOnly = 0;
        $totalUnresolved = 0;
        $totalTextObservations = 0;
        $totalVisionObservations = 0;

        $categoryCounts = array_fill_keys(array_map(
            fn (LaboratoryResultExtractionQaDocumentCategory $c) => $c->value,
            LaboratoryResultExtractionQaDocumentCategory::cases(),
        ), 0);

        $patternsBySource = [];
        $patternsByFallback = [];
        $patternsByTextStatus = [];
        $patternsByPageCount = [];
        $aliasCandidates = [
            'text_only_analyte_keys' => [],
            'vision_only_analyte_keys' => [],
            'version_ids_with_overlap_gaps' => [],
        ];

        foreach ($documents as $document) {
            if (! $document->success) {
                $categoryCounts[LaboratoryResultExtractionQaDocumentCategory::NoComparable->value]++;

                continue;
            }

            if (($document->textObservationCount ?? 0) > 0) {
                $versionsWithText++;
            }

            if ($document->visionExecuted) {
                $versionsWithVision++;
            }

            $comparablePairs = $document->matchCount + $document->conflictCount + $document->unresolvedCount;
            $totalComparablePairs += $comparablePairs;
            $totalMatch += $document->matchCount;
            $totalConflict += $document->conflictCount;
            $totalTextOnly += $document->textOnlyCount;
            $totalVisionOnly += $document->visionOnlyCount;
            $totalUnresolved += $document->unresolvedCount;
            $totalTextObservations += $document->textObservationCount ?? 0;
            $totalVisionObservations += $document->visionObservationCount ?? 0;

            if ($document->documentCategory !== null) {
                $categoryCounts[$document->documentCategory->value]++;
            }

            $this->incrementPattern($patternsBySource, $document->source ?? 'unknown', $document);
            $this->incrementPattern(
                $patternsByFallback,
                $document->fallbackReasons !== [] ? implode('|', $document->fallbackReasons) : 'none',
                $document,
            );
            $this->incrementPattern($patternsByTextStatus, $document->textExtractionStatus ?? 'unknown', $document);
            $this->incrementPattern(
                $patternsByPageCount,
                (string) ($document->textPageCount ?? 'unknown'),
                $document,
            );

            if ($document->textOnlyCount > 0 || $document->visionOnlyCount > 0) {
                $aliasCandidates['version_ids_with_overlap_gaps'][] = $document->versionId;
            }

            foreach ($document->textOnlyAnalyteKeys as $key) {
                $aliasCandidates['text_only_analyte_keys'][$key] = ($aliasCandidates['text_only_analyte_keys'][$key] ?? 0) + 1;
            }

            foreach ($document->visionOnlyAnalyteKeys as $key) {
                $aliasCandidates['vision_only_analyte_keys'][$key] = ($aliasCandidates['vision_only_analyte_keys'][$key] ?? 0) + 1;
            }
        }

        return new LaboratoryResultExtractionQaBatchResult(
            documents: $documents,
            totalVersions: $totalVersions,
            versionsWithText: $versionsWithText,
            versionsWithVision: $versionsWithVision,
            totalComparablePairs: $totalComparablePairs,
            totalMatch: $totalMatch,
            totalConflict: $totalConflict,
            totalTextOnly: $totalTextOnly,
            totalVisionOnly: $totalVisionOnly,
            totalUnresolved: $totalUnresolved,
            matchRate: $this->safeRate($totalMatch, $totalComparablePairs),
            conflictRate: $this->safeRate($totalConflict, $totalComparablePairs),
            visionOnlyRate: $this->safeRate($totalVisionOnly, $totalMatch + $totalConflict + $totalTextOnly + $totalVisionOnly + $totalUnresolved),
            textOnlyRate: $this->safeRate($totalTextOnly, $totalMatch + $totalConflict + $totalTextOnly + $totalVisionOnly + $totalUnresolved),
            visionCoverage: $this->safeRate($totalVisionObservations, $totalTextObservations),
            categoryCounts: $categoryCounts,
            patternsBySource: $patternsBySource,
            patternsByFallback: $patternsByFallback,
            patternsByTextStatus: $patternsByTextStatus,
            patternsByPageCount: $patternsByPageCount,
            aliasCandidateVersionIds: $aliasCandidates,
        );
    }

    /**
     * @param  array<string, array<string, int>>  $bucket
     */
    private function incrementPattern(array &$bucket, string $key, LaboratoryResultExtractionQaBatchDocumentResult $document): void
    {
        if (! isset($bucket[$key])) {
            $bucket[$key] = [
                'documents' => 0,
                'conflicts' => 0,
                'vision_only' => 0,
                'text_only' => 0,
            ];
        }

        $bucket[$key]['documents']++;
        $bucket[$key]['conflicts'] += $document->conflictCount;
        $bucket[$key]['vision_only'] += $document->visionOnlyCount;
        $bucket[$key]['text_only'] += $document->textOnlyCount;
    }

    private function safeRate(int|float $numerator, int|float $denominator): ?float
    {
        if ($denominator <= 0) {
            return null;
        }

        return round($numerator / $denominator, 4);
    }
}
