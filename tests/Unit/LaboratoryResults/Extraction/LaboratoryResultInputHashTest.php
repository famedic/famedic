<?php

namespace Tests\Unit\LaboratoryResults\Extraction;

use App\Services\LaboratoryResults\Extraction\LaboratoryResultInputHash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryResultInputHashTest extends TestCase
{
    #[Test]
    public function mismo_pdf_y_extractor_produce_mismo_hash(): void
    {
        $sha = hash('sha256', 'pdf-bytes');

        $first = LaboratoryResultInputHash::compute($sha, 'RESULT_TEXT_EXTRACTOR_V1', null);
        $second = LaboratoryResultInputHash::compute($sha, 'RESULT_TEXT_EXTRACTOR_V1', null);

        $this->assertSame($first, $second);
    }

    #[Test]
    public function cambiar_extractor_version_cambia_hash(): void
    {
        $sha = hash('sha256', 'pdf-bytes');

        $v1 = LaboratoryResultInputHash::compute($sha, 'RESULT_TEXT_EXTRACTOR_V1', null);
        $v2 = LaboratoryResultInputHash::compute($sha, 'RESULT_TEXT_EXTRACTOR_V2', null);

        $this->assertNotSame($v1, $v2);
    }

    #[Test]
    public function cambiar_prompt_version_cambia_hash(): void
    {
        $sha = hash('sha256', 'pdf-bytes');

        $withoutPrompt = LaboratoryResultInputHash::compute($sha, 'RESULT_TEXT_EXTRACTOR_V1', null);
        $withPrompt = LaboratoryResultInputHash::compute($sha, 'RESULT_TEXT_EXTRACTOR_V1', 1);

        $this->assertNotSame($withoutPrompt, $withPrompt);
    }

    #[Test]
    public function cambiar_renderer_vision_gd_a_hybrid_cambia_hash(): void
    {
        $sha = hash('sha256', 'pdf-bytes');
        $promptVersion = 3;

        $gdHash = LaboratoryResultInputHash::compute(
            $sha,
            LaboratoryResultInputHash::VISION_EXTRACTOR_GD_LEGACY,
            $promptVersion,
        );
        $hybridHash = LaboratoryResultInputHash::compute(
            $sha,
            LaboratoryResultInputHash::VISION_EXTRACTOR_VERSION,
            $promptVersion,
        );

        $this->assertNotSame($gdHash, $hybridHash);
    }

    #[Test]
    public function cambiar_a_pii_safe_crop_cambia_hash(): void
    {
        $sha = hash('sha256', 'pdf-bytes');
        $promptVersion = 3;

        $realPdfHash = LaboratoryResultInputHash::compute(
            $sha,
            LaboratoryResultInputHash::VISION_EXTRACTOR_REAL_PDF_V1,
            $promptVersion,
        );
        $piiSafeHash = LaboratoryResultInputHash::compute(
            $sha,
            LaboratoryResultInputHash::VISION_EXTRACTOR_VERSION,
            $promptVersion,
        );

        $this->assertNotSame($realPdfHash, $piiSafeHash);
    }
}
