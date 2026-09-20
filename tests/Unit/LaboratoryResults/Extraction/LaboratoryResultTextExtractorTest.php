<?php

namespace Tests\Unit\LaboratoryResults\Extraction;

use App\Services\LaboratoryResults\Extraction\LaboratoryResultTextExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LaboratoryResultsPdfFixture;
use Tests\TestCase;

class LaboratoryResultTextExtractorTest extends TestCase
{
    #[Test]
    public function extrae_texto_de_pdf_valido(): void
    {
        $binary = LaboratoryResultsPdfFixture::binary(['Glucosa 95 mg/dL 70-100']);

        $result = app(LaboratoryResultTextExtractor::class)->extractFromBinary($binary);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Glucosa', $result->fullText);
        $this->assertGreaterThan(0, $result->totalCharacters());
    }

    #[Test]
    public function soporta_multiples_paginas(): void
    {
        $binary = LaboratoryResultsPdfFixture::binary(
            ['Glucosa 95 mg/dL 70-100'],
            ['Hemoglobina 14.2 g/dL 12 - 16'],
        );

        $result = app(LaboratoryResultTextExtractor::class)->extractFromBinary($binary);

        $this->assertTrue($result->success);
        $this->assertGreaterThanOrEqual(1, $result->pageCount);
        $this->assertStringContainsString('Hemoglobina', $result->fullText);
    }

    #[Test]
    public function pdf_invalido_retorna_error(): void
    {
        $result = app(LaboratoryResultTextExtractor::class)->extractFromBinary('not-a-pdf');

        $this->assertFalse($result->success);
        $this->assertSame('invalid_pdf', $result->errorCode);
    }

    #[Test]
    public function pdf_vacio_retorna_sin_texto(): void
    {
        $binary = LaboratoryResultsPdfFixture::binary([], includeHeader: false);

        $result = app(LaboratoryResultTextExtractor::class)->extractFromBinary($binary);

        $this->assertTrue($result->success);
        $this->assertLessThan(5, $result->totalCharacters());
    }
}
