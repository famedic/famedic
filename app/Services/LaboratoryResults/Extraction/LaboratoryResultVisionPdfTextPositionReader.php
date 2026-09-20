<?php

namespace App\Services\LaboratoryResults\Extraction;

use Symfony\Component\Process\Process;

/**
 * Lee posiciones de texto por palabra vía Poppler pdftotext -tsv.
 */
class LaboratoryResultVisionPdfTextPositionReader
{
    /**
     * @return list<array{
     *     page: int,
     *     text: string,
     *     left: float,
     *     top: float,
     *     width: float,
     *     height: float,
     *     line_num: int
     * }>
     */
    public function readWords(string $pdfBinary): array
    {
        $pdftotext = $this->resolvePdftotextBinary();

        if ($pdftotext === null) {
            return [];
        }

        $pdfPath = sys_get_temp_dir().'/famedic_vision_tsv_'.uniqid('', true).'.pdf';

        file_put_contents($pdfPath, $pdfBinary);

        try {
            $process = new Process([$pdftotext, '-tsv', $pdfPath, '-']);
            $process->setTimeout(60);
            $process->run();

            if (! $process->isSuccessful()) {
                return [];
            }

            return $this->parseTsv($process->getOutput());
        } finally {
            @unlink($pdfPath);
        }
    }

    /**
     * @return list<array{
     *     page: int,
     *     text: string,
     *     left: float,
     *     top: float,
     *     width: float,
     *     height: float,
     *     line_num: int
     * }>
     */
    public function readPageWords(string $pdfBinary, int $pageNumber): array
    {
        return array_values(array_filter(
            $this->readWords($pdfBinary),
            fn (array $word): bool => (int) $word['page'] === $pageNumber,
        ));
    }

    public function isAvailable(): bool
    {
        return $this->resolvePdftotextBinary() !== null;
    }

    /**
     * @return list<array{
     *     page: int,
     *     text: string,
     *     left: float,
     *     top: float,
     *     width: float,
     *     height: float,
     *     line_num: int
     * }>
     */
    private function parseTsv(string $output): array
    {
        $lines = preg_split('/\R/u', trim($output)) ?: [];
        $words = [];

        foreach ($lines as $index => $line) {
            if ($index === 0 && str_starts_with($line, 'level')) {
                continue;
            }

            $columns = str_getcsv($line, "\t");

            if (count($columns) < 12) {
                continue;
            }

            $level = (int) ($columns[0] ?? 0);

            if ($level !== 5) {
                continue;
            }

            $text = trim((string) ($columns[11] ?? ''));

            if ($text === '' || str_starts_with($text, '###')) {
                continue;
            }

            $words[] = [
                'page' => (int) ($columns[1] ?? 0),
                'text' => $text,
                'left' => (float) ($columns[6] ?? 0),
                'top' => (float) ($columns[7] ?? 0),
                'width' => (float) ($columns[8] ?? 0),
                'height' => (float) ($columns[9] ?? 0),
                'line_num' => (int) ($columns[4] ?? 0),
            ];
        }

        return $words;
    }

    private function resolvePdftotextBinary(): ?string
    {
        $candidates = [
            (string) config('laboratory-results.vision_extraction.pii_safe.pdftotext_binary', 'pdftotext'),
            '/usr/bin/pdftotext',
        ];

        foreach (array_unique($candidates) as $candidate) {
            if ($candidate === '') {
                continue;
            }

            if (str_contains($candidate, '/') && ! is_executable($candidate)) {
                continue;
            }

            $process = new Process([$candidate, '-h']);
            $process->run();

            if ($process->getExitCode() === 0 || $process->getExitCode() === 1) {
                return $candidate;
            }
        }

        return null;
    }
}
