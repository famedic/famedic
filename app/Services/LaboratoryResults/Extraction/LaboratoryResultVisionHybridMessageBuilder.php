<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Models\AiPrompt;

/**
 * Construye input híbrido: imagen raster real + texto Smalot por página.
 */
class LaboratoryResultVisionHybridMessageBuilder
{
    public const SOURCE_TEXT_HEADER = 'SOURCE TEXT EXTRACTED FROM PDF';

    public const STRUCTURED_TSV_HEADER = 'STRUCTURED PDF TABLE ROWS FROM TSV';

    public function __construct(
        private readonly LaboratoryResultVisionPiiSafePageRenderer $piiSafePageRenderer,
        private readonly LaboratoryResultVisionPdfTextPositionReader $textPositionReader,
    ) {}

    /**
     * @param  list<array{page: int, text: string, char_count: int, score: float}>  $selectedPages
     * @return array{
     *     0: list<array{role: string, content: string|list<array<string, mixed>>}>,
     *     1: int,
     *     2: list<array<string, mixed>>
     * }
     */
    public function build(
        AiPrompt $prompt,
        string $pdfBinary,
        array $selectedPages,
    ): array {
        $pageNumbers = implode(', ', array_map(fn (array $page): string => (string) $page['page'], $selectedPages));
        $userPrompt = str_replace('{{page_numbers}}', $pageNumbers, $prompt->user_prompt);

        $content = [
            ['type' => 'text', 'text' => $userPrompt],
        ];
        $imageCount = 0;
        $pageDiagnostics = [];

        foreach ($selectedPages as $page) {
            $pageNumber = (int) $page['page'];
            $sourceText = trim((string) $page['text']);
            $structuredRows = $this->formatStructuredTextPositionBlock(
                $pageNumber,
                $this->textPositionReader->readPageWords($pdfBinary, $pageNumber),
            );

            if ($structuredRows !== null) {
                $content[] = [
                    'type' => 'text',
                    'text' => $structuredRows,
                ];
            }

            $content[] = [
                'type' => 'text',
                'text' => $this->formatSourceTextBlock($pageNumber, $sourceText),
            ];

            $renderResult = $this->piiSafePageRenderer->render($pdfBinary, $pageNumber);

            if ($renderResult->isSendableToVision()) {
                $content[] = [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => 'data:image/png;base64,'.$renderResult->pngBase64,
                        'detail' => 'high',
                    ],
                ];
                $imageCount++;
            }

            $pageDiagnostics[] = $renderResult->toPageDiagnostic(
                $pageNumber,
                $sourceText !== '',
            );
        }

        return [
            [
                ['role' => 'system', 'content' => $prompt->system_prompt],
                ['role' => 'user', 'content' => $content],
            ],
            $imageCount,
            $pageDiagnostics,
        ];
    }

    public function formatSourceTextBlock(int $pageNumber, string $sourceText): string
    {
        return implode("\n", [
            '--- '.self::SOURCE_TEXT_HEADER." (page {$pageNumber}) ---",
            $sourceText,
            '--- END SOURCE TEXT ---',
        ]);
    }

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
    public function formatStructuredTextPositionBlock(int $pageNumber, array $pageWords): ?string
    {
        $rows = $this->extractStructuredRows($pageWords);

        if ($rows === []) {
            return null;
        }

        return implode("\n", [
            '--- '.self::STRUCTURED_TSV_HEADER." (page {$pageNumber}) ---",
            '| estudio | resultado | unidades | valores_de_referencia |',
            '| --- | --- | --- | --- |',
            ...array_map(
                fn (array $row): string => '| '.$row['study'].' | '.$row['result'].' | '.$row['unit'].' | '.$row['reference'].' |',
                $rows,
            ),
            '--- END STRUCTURED PDF TABLE ROWS ---',
        ]);
    }

    /**
     * @param  list<array{text: string, left: float, top: float, width: float, height: float, line_num: int}>  $pageWords
     * @return list<array{study: string, result: string, unit: string, reference: string}>
     */
    private function extractStructuredRows(array $pageWords): array
    {
        if ($pageWords === []) {
            return [];
        }

        $lines = $this->groupWordsByLine($pageWords);
        $header = $this->findTableHeader($lines);

        if ($header === null) {
            return [];
        }

        $rows = [];
        $pendingUnit = null;

        foreach ($lines as $lineWords) {
            $lineTop = min(array_map(fn (array $word): float => (float) $word['top'], $lineWords));

            if ($lineTop <= $header['top'] + 2.0) {
                continue;
            }

            $columns = $this->splitLineIntoColumns($lineWords, $header['starts']);

            if ($this->isUnitOnlyStructuredRow($columns)) {
                $pendingUnit = $columns[2];

                continue;
            }

            if ($this->shouldSkipStructuredRow($columns)) {
                continue;
            }

            if ($columns[2] === '' && $pendingUnit !== null) {
                $columns[2] = $pendingUnit;
            }

            if ($columns[2] !== '') {
                $pendingUnit = null;
            }

            $rows[] = [
                'study' => $columns[0],
                'result' => $columns[1],
                'unit' => $columns[2],
                'reference' => $columns[3],
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{text: string, top: float, left: float}>  $pageWords
     * @return list<list<array{text: string, top: float, left: float}>>
     */
    private function groupWordsByLine(array $pageWords): array
    {
        $lines = [];

        foreach ($pageWords as $word) {
            $lineKey = (int) round((float) $word['top']);
            $lines[$lineKey][] = $word;
        }

        ksort($lines);

        foreach ($lines as &$lineWords) {
            usort($lineWords, fn (array $a, array $b): int => $a['left'] <=> $b['left']);
        }

        return array_values($lines);
    }

    /**
     * @param  list<list<array{text: string, top: float, left: float}>>  $lines
     * @return array{top: float, starts: list<float>}|null
     */
    private function findTableHeader(array $lines): ?array
    {
        foreach ($lines as $lineWords) {
            $lineText = mb_strtoupper(implode(' ', array_map(
                fn (array $word): string => $word['text'],
                $lineWords,
            )), 'UTF-8');

            if (
                ! str_contains($lineText, 'ESTUDIO')
                || ! str_contains($lineText, 'RESULTADO')
                || ! (str_contains($lineText, 'UNIDADES') || str_contains($lineText, 'UNIDAD'))
            ) {
                continue;
            }

            $starts = [
                $this->findWordLeft($lineWords, 'ESTUDIO'),
                $this->findWordLeft($lineWords, 'RESULTADO'),
                $this->findWordLeft($lineWords, 'UNIDADES') ?? $this->findWordLeft($lineWords, 'UNIDAD'),
                $this->findWordLeft($lineWords, 'VALORES') ?? $this->findWordLeft($lineWords, 'REFERENCIA'),
            ];

            if (in_array(null, $starts, true)) {
                continue;
            }

            return [
                'top' => min(array_map(fn (array $word): float => (float) $word['top'], $lineWords)),
                'starts' => array_values(array_map(fn (float|int|null $start): float => (float) $start, $starts)),
            ];
        }

        return null;
    }

    /**
     * @param  list<array{text: string, left: float}>  $lineWords
     */
    private function findWordLeft(array $lineWords, string $needle): ?float
    {
        foreach ($lineWords as $word) {
            if (mb_strtoupper($word['text'], 'UTF-8') === $needle) {
                return (float) $word['left'];
            }
        }

        return null;
    }

    /**
     * @param  list<array{text: string, left: float}>  $lineWords
     * @param  list<float>  $starts
     * @return list<string>
     */
    private function splitLineIntoColumns(array $lineWords, array $starts): array
    {
        $boundaries = [
            ($starts[0] + $starts[1]) / 2,
            ($starts[1] + $starts[2]) / 2,
            ($starts[2] + $starts[3]) / 2,
        ];
        $columns = [[], [], [], []];

        foreach ($lineWords as $word) {
            $left = (float) $word['left'];
            $column = match (true) {
                $left < $boundaries[0] => 0,
                $left < $boundaries[1] => 1,
                $left < $boundaries[2] => 2,
                default => 3,
            };

            $columns[$column][] = $word['text'];
        }

        return array_map(
            fn (array $words): string => trim(preg_replace('/\s+/u', ' ', implode(' ', $words)) ?? ''),
            $columns,
        );
    }

    /**
     * @param  list<string>  $columns
     */
    private function shouldSkipStructuredRow(array $columns): bool
    {
        $line = trim(implode(' ', $columns));

        if ($line === '') {
            return true;
        }

        if ($columns[0] === '' && $columns[1] === '' && $columns[2] === '' && $columns[3] === '') {
            return true;
        }

        $normalized = mb_strtolower($line, 'UTF-8');

        if (preg_match('/\b(q\.?b\.?p\.?|cedula|c[eé]dula|estudio acreditado|resultados fuera de rango|interpretaci[oó]n|cualquier aclaraci[oó]n)\b/u', $normalized)) {
            return true;
        }

        if ($columns[1] === '' && $columns[2] === '' && $columns[3] === '' && ! str_contains($normalized, 'quimica sanguinea')) {
            return true;
        }

        return mb_strlen($line, 'UTF-8') < 3;
    }

    /**
     * @param  list<string>  $columns
     */
    private function isUnitOnlyStructuredRow(array $columns): bool
    {
        return $columns[0] === ''
            && $columns[1] === ''
            && $columns[2] !== ''
            && $columns[3] === '';
    }
}
