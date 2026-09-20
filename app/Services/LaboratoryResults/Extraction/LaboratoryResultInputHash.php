<?php

namespace App\Services\LaboratoryResults\Extraction;

final class LaboratoryResultInputHash
{
    public const DEFAULT_EXTRACTOR_VERSION = 'RESULT_TEXT_EXTRACTOR_V1';

    /** @deprecated GD imagestring renderer — historical AiExecution only */
    public const VISION_EXTRACTOR_GD_LEGACY = 'RESULT_VISION_EXTRACTOR_V1';

    /** @deprecated 8C-10D full-page raster without PII crop */
    public const VISION_EXTRACTOR_REAL_PDF_V1 = 'RESULT_VISION_RENDERER_REAL_PDF_V1';

    public const VISION_EXTRACTOR_VERSION = 'RESULT_VISION_PII_SAFE_CROP_TSV_V3';

    public const VISION_INPUT_MODE_REAL_PDF_HYBRID = 'real_pdf_raster_hybrid';

    public const VISION_INPUT_MODE_PII_SAFE_HYBRID = 'pii_safe_raster_hybrid';

    public const VISION_EXPERIMENT_10B_EXTRACTOR_VERSION = 'RESULT_VISION_EXP_10B_V1';

    public const VISION_EXPERIMENT_10B_KEY = '8c-10b_real_pdf_raster_hybrid';

    public const STRUCTURED_SHADOW_QA_EXPERIMENT_KEY = '8c-17d_structured_shadow_qa';

    public const STRUCTURED_SHADOW_QA_EXTRACTOR_VERSION = 'STRUCTURED_QA_SHADOW_V1';

    public static function compute(
        string $versionSha256,
        ?string $extractorVersion = null,
        ?int $promptVersion = null,
    ): string {
        $extractorVersion ??= (string) config(
            'laboratory-results.structured_extraction.extractor_version',
            self::DEFAULT_EXTRACTOR_VERSION
        );

        $promptPart = $promptVersion === null ? '' : (string) $promptVersion;

        return hash('sha256', implode('|', [
            $versionSha256,
            $extractorVersion,
            $promptPart,
        ]));
    }

    public static function computeExperiment(
        string $versionSha256,
        string $experimentKey,
        string $extractorVersion,
        ?int $promptVersion = null,
    ): string {
        return hash('sha256', implode('|', [
            $versionSha256,
            $experimentKey,
            $extractorVersion,
            (string) ($promptVersion ?? ''),
        ]));
    }
}
