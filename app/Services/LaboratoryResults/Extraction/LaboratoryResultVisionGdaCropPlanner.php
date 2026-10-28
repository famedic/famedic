<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryResultVisionPiiSafetyStatus;

/**
 * Planifica crop determinista para layouts GDA conocidos (Swisslab, OLAB, Jenner).
 */
class LaboratoryResultVisionGdaCropPlanner
{
    private const DEFAULT_PAGE_HEIGHT_PT = 792.0;

    private const DEFAULT_PAGE_WIDTH_PT = 612.0;

    /** @var array<string, array{min: float, max: float}> */
    private const TABLE_HEADER_Y_RANGES = [
        'swisslab' => ['min' => 200.0, 'max' => 225.0],
        'olab_jenner' => ['min' => 235.0, 'max' => 252.0],
    ];

    /**
     * @param  list<array{
     *     page: int,
     *     text: string,
     *     left: float,
     *     top: float,
     *     width: float,
     *     height: float,
     *     line_num: int
     * }>  $pageWords
     */
    public function plan(array $pageWords): LaboratoryResultVisionGdaCropPlan
    {
        if ($pageWords === []) {
            return new LaboratoryResultVisionGdaCropPlan(
                status: LaboratoryResultVisionPiiSafetyStatus::Unsafe,
                unsafeReason: 'no_text_positions',
            );
        }

        $pageHeightPt = $this->estimatePageHeight($pageWords);
        $pageWidthPt = $this->estimatePageWidth($pageWords);
        $tableHeaderY = $this->findResultsTableHeaderTop($pageWords);

        if ($tableHeaderY === null) {
            return new LaboratoryResultVisionGdaCropPlan(
                status: LaboratoryResultVisionPiiSafetyStatus::Unsafe,
                pageWidthPt: $pageWidthPt,
                pageHeightPt: $pageHeightPt,
                unsafeReason: 'table_header_not_found',
            );
        }

        $layoutFamily = $this->detectLayoutFamily($pageWords, $tableHeaderY);
        $cropTopPt = max(0.0, $tableHeaderY - 2.0);
        $cropTopPct = ($cropTopPt / $pageHeightPt) * 100;

        if ($cropTopPct < 15.0 || $cropTopPct > 45.0) {
            return new LaboratoryResultVisionGdaCropPlan(
                status: LaboratoryResultVisionPiiSafetyStatus::Unsafe,
                cropTopPt: $cropTopPt,
                pageWidthPt: $pageWidthPt,
                pageHeightPt: $pageHeightPt,
                layoutFamily: $layoutFamily,
                unsafeReason: 'crop_top_out_of_expected_range',
            );
        }

        $wordsInCropRegion = array_values(array_filter(
            $pageWords,
            fn (array $word): bool => $word['top'] >= ($cropTopPt - 1.0),
        ));

        $qualityStatus = $this->resolveQualityStatus($layoutFamily, $tableHeaderY);

        return new LaboratoryResultVisionGdaCropPlan(
            status: LaboratoryResultVisionPiiSafetyStatus::SafeCrop,
            cropTopPt: $cropTopPt,
            pageWidthPt: $pageWidthPt,
            pageHeightPt: $pageHeightPt,
            layoutFamily: $layoutFamily,
            cropMethod: 'gda_table_header_crop',
            qualityStatus: $qualityStatus,
            wordsInCropRegion: $wordsInCropRegion,
        );
    }

    /**
     * @param  list<array{text: string, top: float, left: float, width: float, height: float, line_num: int}>  $pageWords
     */
    private function findResultsTableHeaderTop(array $pageWords): ?float
    {
        $lines = $this->groupWordsByLine($pageWords);

        foreach ($lines as $lineWords) {
            $lineText = mb_strtoupper(implode(' ', array_map(
                fn (array $word): string => $word['text'],
                $lineWords,
            )), 'UTF-8');

            $hasEstudio = str_contains($lineText, 'ESTUDIO');
            $hasResultado = str_contains($lineText, 'RESULTADO');
            $hasUnidades = str_contains($lineText, 'UNIDADES') || str_contains($lineText, 'UNIDAD');
            $hasParametro = str_contains($lineText, 'PARAMETRO') || str_contains($lineText, 'PARÁMETRO');
            $hasAnalito = str_contains($lineText, 'ANALITO');

            if (($hasEstudio && $hasResultado) || ($hasParametro && $hasResultado) || ($hasAnalito && $hasResultado && $hasUnidades)) {
                return min(array_map(fn (array $word): float => $word['top'], $lineWords));
            }
        }

        return null;
    }

    /**
     * @param  list<array{text: string, top: float, left: float, width: float, height: float, line_num: int}>  $pageWords
     * @return list<list<array{text: string, top: float, left: float, width: float, height: float, line_num: int}>>
     */
    private function groupWordsByLine(array $pageWords): array
    {
        $lines = [];

        foreach ($pageWords as $word) {
            $lineKey = (int) round($word['top']);
            $lines[$lineKey][] = $word;
        }

        ksort($lines);

        return array_values($lines);
    }

    /**
     * @param  list<array{text: string, top: float}>  $pageWords
     */
    private function estimatePageHeight(array $pageWords): float
    {
        $maxBottom = 0.0;

        foreach ($pageWords as $word) {
            $maxBottom = max($maxBottom, $word['top'] + ($word['height'] ?? 0));
        }

        if ($maxBottom >= 400) {
            return $maxBottom;
        }

        return self::DEFAULT_PAGE_HEIGHT_PT;
    }

    /**
     * @param  list<array{left: float, width: float}>  $pageWords
     */
    private function estimatePageWidth(array $pageWords): float
    {
        $maxRight = 0.0;

        foreach ($pageWords as $word) {
            $maxRight = max($maxRight, $word['left'] + $word['width']);
        }

        return $maxRight > 0 ? $maxRight : self::DEFAULT_PAGE_WIDTH_PT;
    }

    /**
     * @param  list<array{text: string}>  $pageWords
     */
    private function detectLayoutFamily(array $pageWords, float $tableHeaderY): ?string
    {
        $joined = mb_strtoupper(implode(' ', array_map(
            fn (array $word): string => $word['text'],
            $pageWords,
        )), 'UTF-8');

        if (str_contains($joined, 'SWISSLAB') || str_contains($joined, '600-15-00-026')) {
            return 'swisslab';
        }

        if (str_contains($joined, 'JENNER')) {
            return 'jenner';
        }

        if (str_contains($joined, 'OLAB') || str_contains($joined, 'ORIARD') || str_contains($joined, 'AVIACION CIVIL')) {
            return 'olab';
        }

        if ($tableHeaderY >= self::TABLE_HEADER_Y_RANGES['swisslab']['min']
            && $tableHeaderY <= self::TABLE_HEADER_Y_RANGES['swisslab']['max']) {
            return 'swisslab';
        }

        if ($tableHeaderY >= self::TABLE_HEADER_Y_RANGES['olab_jenner']['min']
            && $tableHeaderY <= self::TABLE_HEADER_Y_RANGES['olab_jenner']['max']) {
            return 'olab_jenner';
        }

        return null;
    }

    private function resolveQualityStatus(?string $layoutFamily, float $tableHeaderY): string
    {
        if ($layoutFamily === 'swisslab') {
            $range = self::TABLE_HEADER_Y_RANGES['swisslab'];

            return ($tableHeaderY >= $range['min'] && $tableHeaderY <= $range['max']) ? 'high' : 'medium';
        }

        if (in_array($layoutFamily, ['olab', 'jenner', 'olab_jenner'], true)) {
            $range = self::TABLE_HEADER_Y_RANGES['olab_jenner'];

            return ($tableHeaderY >= $range['min'] && $tableHeaderY <= $range['max']) ? 'high' : 'medium';
        }

        return 'medium';
    }
}
