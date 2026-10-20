<?php

namespace App\Services\LaboratoryResults\Extraction;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Rasteriza una página real del PDF con Poppler pdftoppm (layout original).
 */
class LaboratoryResultVisionRealPdfPageRenderer
{
    public function isAvailable(): bool
    {
        return $this->resolvePdftoppmBinary() !== null;
    }

    /**
     * @return array{width: int, height: int, dpi: int, binary_path: string}|null
     */
    public function renderPageToPngFile(string $pdfBinary, int $pageNumber): ?array
    {
        $pdftoppm = $this->resolvePdftoppmBinary();

        if ($pdftoppm === null) {
            return null;
        }

        $dpi = max(72, (int) config('laboratory-results.vision_extraction.experiment.real_pdf_dpi', 150));
        $tempDir = sys_get_temp_dir().'/famedic_vision_'.uniqid('', true);
        $pdfPath = $tempDir.'.pdf';
        $outputPrefix = $tempDir.'_page';

        file_put_contents($pdfPath, $pdfBinary);

        try {
            $process = new Process([
                $pdftoppm,
                '-png',
                '-singlefile',
                '-f', (string) $pageNumber,
                '-l', (string) $pageNumber,
                '-r', (string) $dpi,
                $pdfPath,
                $outputPrefix,
            ]);
            $process->setTimeout(60);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $pngPath = $outputPrefix.'.png';

            if (! is_file($pngPath)) {
                return null;
            }

            $dimensions = $this->readPngDimensions($pngPath);

            return [
                'width' => $dimensions[0],
                'height' => $dimensions[1],
                'dpi' => $dpi,
                'binary_path' => $pngPath,
            ];
        } finally {
            @unlink($pdfPath);
        }
    }

    public function renderBase64Png(string $pdfBinary, int $pageNumber): ?string
    {
        $rendered = $this->renderPageToPngFile($pdfBinary, $pageNumber);

        if ($rendered === null) {
            return null;
        }

        try {
            $binary = file_get_contents($rendered['binary_path']);

            return $binary === false || $binary === '' ? null : base64_encode($binary);
        } finally {
            @unlink($rendered['binary_path']);
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function readPngDimensions(string $pngPath): array
    {
        $info = @getimagesize($pngPath);

        if ($info === false) {
            throw new RuntimeException('Unable to read PNG dimensions from rasterized PDF page.');
        }

        return [(int) $info[0], (int) $info[1]];
    }

    private function resolvePdftoppmBinary(): ?string
    {
        $candidates = [
            (string) config('laboratory-results.vision_extraction.experiment.pdftoppm_binary', 'pdftoppm'),
            '/usr/bin/pdftoppm',
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
