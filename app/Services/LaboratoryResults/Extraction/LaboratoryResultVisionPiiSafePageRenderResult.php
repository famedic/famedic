<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryResultVisionPiiSafetyStatus;

final class LaboratoryResultVisionPiiSafePageRenderResult
{
    /**
     * @param  array{x: int, y: int, width: int, height: int}|null  $cropPixels
     * @param  array{top_pt: float, page_width_pt: float, page_height_pt: float}|null  $cropPoints
     */
    public function __construct(
        public readonly LaboratoryResultVisionPiiSafetyStatus $piiSafetyStatus,
        public readonly ?string $pngBase64 = null,
        public readonly ?string $layoutFamily = null,
        public readonly ?string $cropMethod = null,
        public readonly ?string $qualityStatus = null,
        public readonly ?array $cropPixels = null,
        public readonly ?array $cropPoints = null,
        public readonly ?int $outputWidth = null,
        public readonly ?int $outputHeight = null,
        public readonly ?int $sourceDpi = null,
        public readonly ?string $unsafeReason = null,
        public readonly int $redactionBoxCount = 0,
    ) {}

    public function isSendableToVision(): bool
    {
        return $this->pngBase64 !== null
            && $this->piiSafetyStatus !== LaboratoryResultVisionPiiSafetyStatus::Unsafe;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPageDiagnostic(int $pageNumber, bool $hasSourceText): array
    {
        $diagnostic = [
            'page' => $pageNumber,
            'has_source_text' => $hasSourceText,
            'pii_safety_status' => $this->piiSafetyStatus->value,
            'image_source' => $this->isSendableToVision() ? 'pii_safe_raster' : 'skipped_unsafe',
            'layout_family' => $this->layoutFamily,
            'crop_method' => $this->cropMethod,
            'quality_status' => $this->qualityStatus,
            'redaction_box_count' => $this->redactionBoxCount,
        ];

        if ($this->cropPoints !== null) {
            $diagnostic['crop_coordinates_pt'] = [
                'top' => round($this->cropPoints['top_pt'], 2),
                'page_width' => round($this->cropPoints['page_width_pt'], 2),
                'page_height' => round($this->cropPoints['page_height_pt'], 2),
            ];
        }

        if ($this->outputWidth !== null && $this->outputHeight !== null) {
            $diagnostic['image_dimensions'] = [
                'width' => $this->outputWidth,
                'height' => $this->outputHeight,
                'dpi' => $this->sourceDpi,
            ];
        }

        if ($this->unsafeReason !== null) {
            $diagnostic['unsafe_reason'] = $this->unsafeReason;
        }

        return $diagnostic;
    }
}
