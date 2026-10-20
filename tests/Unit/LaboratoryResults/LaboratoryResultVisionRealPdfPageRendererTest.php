<?php

namespace Tests\Unit\LaboratoryResults;

use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionRealPdfPageRenderer;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LaboratoryResultsPdfFixture;
use Tests\TestCase;

class LaboratoryResultVisionRealPdfPageRendererTest extends TestCase
{
    #[Test]
    public function rasteriza_pagina_pdf_real_cuando_pdftoppm_disponible(): void
    {
        $renderer = new LaboratoryResultVisionRealPdfPageRenderer;

        if (! $renderer->isAvailable()) {
            $this->markTestSkipped('pdftoppm not available in this environment.');
        }

        $pdfBinary = LaboratoryResultsPdfFixture::binary([
            'PLAQUETAS 365 miles/uL 167-431',
            'HGM 29 pg 26.8-33.2',
        ]);

        $base64 = $renderer->renderBase64Png($pdfBinary, 1);

        $this->assertNotNull($base64);
        $this->assertNotSame('', $base64);

        $binary = base64_decode($base64, true);
        $this->assertNotFalse($binary);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $binary);
    }
}
