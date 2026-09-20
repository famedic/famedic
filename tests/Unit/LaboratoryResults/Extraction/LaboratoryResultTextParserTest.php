<?php

namespace Tests\Unit\LaboratoryResults\Extraction;

use App\Enums\LaboratoryResultObservationValueType;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultTextExtractionResult;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultTextParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryResultTextParserTest extends TestCase
{
    private LaboratoryResultTextParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = app(LaboratoryResultTextParser::class);
    }

    #[Test]
    public function parsea_resultado_numerico_con_unidad_y_rango(): void
    {
        $extraction = $this->extractionFromLines(['Glucosa        95       mg/dL       70-100']);
        $candidates = $this->parser->parse($extraction);

        $this->assertCount(1, $candidates);
        $this->assertSame('Glucosa', $candidates[0]->analyteNameRaw);
        $this->assertSame(95.0, $candidates[0]->numericValue);
        $this->assertSame('mg/dL', $candidates[0]->unit);
        $this->assertSame(70.0, $candidates[0]->referenceLow);
        $this->assertSame(100.0, $candidates[0]->referenceHigh);
    }

    #[Test]
    public function parsea_referencia_menor_que(): void
    {
        $extraction = $this->extractionFromLines(['Urea 38 mg/dL < 50']);
        $candidate = $this->parser->parse($extraction)[0];

        $this->assertNull($candidate->referenceLow);
        $this->assertSame(50.0, $candidate->referenceHigh);
    }

    #[Test]
    public function parsea_referencia_mayor_que(): void
    {
        $extraction = $this->extractionFromLines(['Creatinina 1.1 mg/dL > 0.6']);
        $candidate = $this->parser->parse($extraction)[0];

        $this->assertSame(0.6, $candidate->referenceLow);
        $this->assertNull($candidate->referenceHigh);
    }

    #[Test]
    public function parsea_rango_con_en_dash(): void
    {
        $extraction = $this->extractionFromLines(['Hemoglobina 14.2 g/dL 12 – 16']);
        $candidate = $this->parser->parse($extraction)[0];

        $this->assertSame(12.0, $candidate->referenceLow);
        $this->assertSame(16.0, $candidate->referenceHigh);
    }

    #[Test]
    public function parsea_resultado_cualitativo(): void
    {
        $extraction = $this->extractionFromLines(['Proteina C Reactiva Negativo']);
        $candidate = $this->parser->parse($extraction)[0];

        $this->assertSame(LaboratoryResultObservationValueType::Qualitative, $candidate->valueType);
        $this->assertSame('Negativo', $candidate->textValue);
    }

    /**
     * @param  list<string>  $lines
     */
    private function extractionFromLines(array $lines): LaboratoryResultTextExtractionResult
    {
        $fullText = implode("\n", $lines);

        return new LaboratoryResultTextExtractionResult(
            pages: [[
                'page' => 1,
                'text' => $fullText,
                'char_count' => mb_strlen($fullText, 'UTF-8'),
            ]],
            fullText: $fullText,
            pageCount: 1,
            success: true,
        );
    }
}
