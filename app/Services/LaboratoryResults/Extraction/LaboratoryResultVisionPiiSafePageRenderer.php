<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryResultVisionPiiSafetyStatus;
use RuntimeException;

/**
 * Rasteriza páginas PDF con crop/redacción determinista de PII para Vision Shadow.
 */
class LaboratoryResultVisionPiiSafePageRenderer
{
    public function __construct(
        private readonly LaboratoryResultVisionRealPdfPageRenderer $realPdfPageRenderer,
        private readonly LaboratoryResultVisionPdfTextPositionReader $textPositionReader,
        private readonly LaboratoryResultVisionGdaCropPlanner $cropPlanner,
        private readonly LaboratoryResultVisionPiiVisualRedactor $visualRedactor,
    ) {}

    public function isAvailable(): bool
    {
        return $this->realPdfPageRenderer->isAvailable()
            && $this->textPositionReader->isAvailable()
            && extension_loaded('gd');
    }

    public function render(string $pdfBinary, int $pageNumber): LaboratoryResultVisionPiiSafePageRenderResult
    {
        if (! $this->realPdfPageRenderer->isAvailable()) {
            return $this->unsafeResult('pdftoppm_unavailable');
        }

        if (! $this->textPositionReader->isAvailable()) {
            return $this->unsafeResult('pdftotext_unavailable');
        }

        if (! extension_loaded('gd')) {
            return $this->unsafeResult('gd_extension_unavailable');
        }

        $pageWords = $this->textPositionReader->readPageWords($pdfBinary, $pageNumber);
        $plan = $this->cropPlanner->plan($pageWords);

        if ($plan->status === LaboratoryResultVisionPiiSafetyStatus::Unsafe) {
            return new LaboratoryResultVisionPiiSafePageRenderResult(
                piiSafetyStatus: LaboratoryResultVisionPiiSafetyStatus::Unsafe,
                layoutFamily: $plan->layoutFamily,
                cropMethod: $plan->cropMethod,
                qualityStatus: $plan->qualityStatus,
                cropPoints: $plan->cropTopPt !== null ? [
                    'top_pt' => $plan->cropTopPt,
                    'page_width_pt' => $plan->pageWidthPt ?? 612.0,
                    'page_height_pt' => $plan->pageHeightPt ?? 792.0,
                ] : null,
                unsafeReason: $plan->unsafeReason ?? 'unsafe_page',
            );
        }

        if ($plan->status === LaboratoryResultVisionPiiSafetyStatus::SafeFullPage) {
            return $this->renderFullPage($pdfBinary, $pageNumber, $plan);
        }

        return $this->renderSafeCrop($pdfBinary, $pageNumber, $plan, $pageWords);
    }

    private function renderSafeCrop(
        string $pdfBinary,
        int $pageNumber,
        LaboratoryResultVisionGdaCropPlan $plan,
        array $pageWords,
    ): LaboratoryResultVisionPiiSafePageRenderResult {
        $raster = $this->realPdfPageRenderer->renderPageToPngFile($pdfBinary, $pageNumber);

        if ($raster === null) {
            return $this->unsafeResult('rasterization_failed');
        }

        try {
            $cropTopPt = (float) $plan->cropTopPt;
            $dpi = (int) $raster['dpi'];
            $scale = $dpi / 72.0;
            $cropTopPx = max(0, (int) floor($cropTopPt * $scale));

            $source = @imagecreatefrompng($raster['binary_path']);

            if ($source === false) {
                return $this->unsafeResult('png_decode_failed');
            }

            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $cropHeight = max(1, $sourceHeight - $cropTopPx);

            $cropped = imagecrop($source, [
                'x' => 0,
                'y' => $cropTopPx,
                'width' => $sourceWidth,
                'height' => $cropHeight,
            ]);

            imagedestroy($source);

            if ($cropped === false) {
                return $this->unsafeResult('crop_failed');
            }

            $redaction = $this->visualRedactor->redactCropRegion(
                $cropped,
                $pageWords,
                $cropTopPt,
                $dpi,
            );

            if ($redaction['residual_pii']) {
                imagedestroy($redaction['image']);

                return new LaboratoryResultVisionPiiSafePageRenderResult(
                    piiSafetyStatus: LaboratoryResultVisionPiiSafetyStatus::Unsafe,
                    layoutFamily: $plan->layoutFamily,
                    cropMethod: $plan->cropMethod,
                    qualityStatus: $plan->qualityStatus,
                    cropPoints: [
                        'top_pt' => $cropTopPt,
                        'page_width_pt' => $plan->pageWidthPt ?? 612.0,
                        'page_height_pt' => $plan->pageHeightPt ?? 792.0,
                    ],
                    unsafeReason: 'residual_pii_after_redaction',
                    redactionBoxCount: $redaction['redaction_box_count'],
                );
            }

            $pngBase64 = $this->encodePngBase64($redaction['image']);
            $outputWidth = imagesx($redaction['image']);
            $outputHeight = imagesy($redaction['image']);
            imagedestroy($redaction['image']);

            if ($pngBase64 === null) {
                return $this->unsafeResult('png_encode_failed');
            }

            return new LaboratoryResultVisionPiiSafePageRenderResult(
                piiSafetyStatus: LaboratoryResultVisionPiiSafetyStatus::SafeCrop,
                pngBase64: $pngBase64,
                layoutFamily: $plan->layoutFamily,
                cropMethod: $plan->cropMethod,
                qualityStatus: $plan->qualityStatus,
                cropPixels: [
                    'x' => 0,
                    'y' => $cropTopPx,
                    'width' => $sourceWidth,
                    'height' => $cropHeight,
                ],
                cropPoints: [
                    'top_pt' => $cropTopPt,
                    'page_width_pt' => $plan->pageWidthPt ?? 612.0,
                    'page_height_pt' => $plan->pageHeightPt ?? 792.0,
                ],
                outputWidth: $outputWidth,
                outputHeight: $outputHeight,
                sourceDpi: $dpi,
                redactionBoxCount: $redaction['redaction_box_count'],
            );
        } finally {
            @unlink($raster['binary_path']);
        }
    }

    private function renderFullPage(
        string $pdfBinary,
        int $pageNumber,
        LaboratoryResultVisionGdaCropPlan $plan,
    ): LaboratoryResultVisionPiiSafePageRenderResult {
        $raster = $this->realPdfPageRenderer->renderPageToPngFile($pdfBinary, $pageNumber);

        if ($raster === null) {
            return $this->unsafeResult('rasterization_failed');
        }

        try {
            $binary = file_get_contents($raster['binary_path']);

            if ($binary === false || $binary === '') {
                return $this->unsafeResult('png_read_failed');
            }

            return new LaboratoryResultVisionPiiSafePageRenderResult(
                piiSafetyStatus: LaboratoryResultVisionPiiSafetyStatus::SafeFullPage,
                pngBase64: base64_encode($binary),
                layoutFamily: $plan->layoutFamily,
                cropMethod: 'documented_safe_full_page',
                qualityStatus: $plan->qualityStatus ?? 'medium',
                outputWidth: $raster['width'],
                outputHeight: $raster['height'],
                sourceDpi: $raster['dpi'],
            );
        } finally {
            @unlink($raster['binary_path']);
        }
    }

    private function unsafeResult(string $reason): LaboratoryResultVisionPiiSafePageRenderResult
    {
        return new LaboratoryResultVisionPiiSafePageRenderResult(
            piiSafetyStatus: LaboratoryResultVisionPiiSafetyStatus::Unsafe,
            unsafeReason: $reason,
        );
    }

    private function encodePngBase64(\GdImage $image): ?string
    {
        ob_start();
        $success = imagepng($image);
        $binary = ob_get_clean();

        if (! $success || ! is_string($binary) || $binary === '') {
            return null;
        }

        return base64_encode($binary);
    }
}
