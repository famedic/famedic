<?php

namespace Tests\Unit\LaboratoryResults;

use App\Enums\LaboratoryReferenceComparisonOutcome;
use App\Services\LaboratoryResults\Extraction\LaboratoryReferenceNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryReferenceNormalizerTest extends TestCase
{
    private LaboratoryReferenceNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new LaboratoryReferenceNormalizer;
    }

    #[Test]
    public function parsea_rango_70_100(): void
    {
        $parsed = $this->normalizer->parse('70-100');

        $this->assertSame('70-100', $parsed->referenceTextOriginal);
        $this->assertSame(70.0, $parsed->referenceLow);
        $this->assertSame(100.0, $parsed->referenceHigh);
    }

    #[Test]
    public function parsea_rango_con_guion_largo(): void
    {
        $parsed = $this->normalizer->parse('70 – 100');

        $this->assertSame(70.0, $parsed->referenceLow);
        $this->assertSame(100.0, $parsed->referenceHigh);
    }

    #[Test]
    public function parsea_menor_que(): void
    {
        $parsed = $this->normalizer->parse('<4.00');

        $this->assertSame(4.0, $parsed->referenceHigh);
        $this->assertNull($parsed->referenceLow);
    }

    #[Test]
    public function parsea_mayor_de_verbal(): void
    {
        $parsed = $this->normalizer->parse('Mayor de 60');

        $this->assertSame(60.0, $parsed->referenceLow);
        $this->assertNull($parsed->referenceHigh);
    }

    #[Test]
    public function parsea_menor_de_verbal(): void
    {
        $parsed = $this->normalizer->parse('Menor de 30');

        $this->assertNull($parsed->referenceLow);
        $this->assertSame(30.0, $parsed->referenceHigh);
    }

    #[Test]
    public function compara_referencias_estructuralmente(): void
    {
        $outcome = $this->normalizer->compare('70-100', null, null, '70 – 100', null, null);

        $this->assertSame(LaboratoryReferenceComparisonOutcome::Match, $outcome);
    }

    #[Test]
    public function parsea_referencia_categorica_gda_deseable(): void
    {
        $parsed = $this->normalizer->parse('DESEABLE < 150');

        $this->assertSame('DESEABLE < 150', $parsed->referenceTextOriginal);
        $this->assertNull($parsed->referenceLow);
        $this->assertSame(150.0, $parsed->referenceHigh);
        $this->assertSame('lt', $parsed->kind);
    }

    #[Test]
    public function parsea_desigualdad_inclusiva(): void
    {
        $lte = $this->normalizer->parse('<=100');
        $gte = $this->normalizer->parse('>=10');

        $this->assertSame(100.0, $lte->referenceHigh);
        $this->assertSame(10.0, $gte->referenceLow);
    }

    #[Test]
    public function compara_deseable_vision_con_texto_normalizado(): void
    {
        $outcome = $this->normalizer->compare(
            textReferenceText: '<150',
            textReferenceLow: null,
            textReferenceHigh: 150.0,
            visionReferenceText: 'DESEABLE < 150',
            visionReferenceLow: null,
            visionReferenceHigh: null,
        );

        $this->assertSame(LaboratoryReferenceComparisonOutcome::Match, $outcome);
    }

    #[Test]
    public function no_parsea_texto_clinico_arbitrario_como_referencia(): void
    {
        $parsed = $this->normalizer->parse('Meta clinica deseable menor a 150');

        $this->assertNull($parsed->referenceLow);
        $this->assertNull($parsed->referenceHigh);
        $this->assertSame('unknown', $parsed->kind);
    }
}
