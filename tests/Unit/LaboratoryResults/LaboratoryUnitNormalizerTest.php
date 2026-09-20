<?php

namespace Tests\Unit\LaboratoryResults;

use App\Services\LaboratoryResults\Extraction\LaboratoryUnitNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryUnitNormalizerTest extends TestCase
{
    private LaboratoryUnitNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new LaboratoryUnitNormalizer;
    }

    #[Test]
    public function ul_y_microL_son_equivalentes(): void
    {
        $this->assertTrue($this->normalizer->areEquivalent('µL', 'uL'));
    }

    #[Test]
    public function mill_ul_y_10_6_ul_son_equivalentes(): void
    {
        $this->assertTrue($this->normalizer->areEquivalent('mill/µL', '10^6/uL'));
        $this->assertTrue($this->normalizer->areEquivalent('mill/uL', 'x10^6/uL'));
    }

    #[Test]
    public function mill_mm3_y_mill_ul_son_equivalentes_para_eritrocitos_gda(): void
    {
        $this->assertSame('1e6_per_ul', $this->normalizer->equivalenceKey('mill/mm3'));
        $this->assertSame('1e6_per_ul', $this->normalizer->equivalenceKey('mill/µL'));
        $this->assertTrue($this->normalizer->areEquivalent('mill/mm3', 'mill/µL'));
        $this->assertTrue($this->normalizer->areCompatible('mill/mm3', 'mill/µL'));
    }

    #[Test]
    public function mill_mm3_no_es_equivalente_a_miles_ul(): void
    {
        $this->assertFalse($this->normalizer->areEquivalent('mill/mm3', 'miles/uL'));
        $this->assertFalse($this->normalizer->areCompatible('mill/mm3', 'miles/uL'));
    }

    #[Test]
    public function miles_ul_y_10_3_ul_son_equivalentes(): void
    {
        $this->assertTrue($this->normalizer->areEquivalent('miles/uL', '10^3/uL'));
    }

    #[Test]
    public function pg_y_g_dl_no_son_equivalentes(): void
    {
        $this->assertFalse($this->normalizer->areEquivalent('pg', 'g/dL'));
        $this->assertFalse($this->normalizer->areCompatible('pg', 'g/dL'));
    }

    #[Test]
    public function porcentaje_es_equivalente_consigo_mismo(): void
    {
        $this->assertTrue($this->normalizer->areEquivalent('%', '%'));
        $this->assertSame('percent', $this->normalizer->equivalenceKey('%'));
    }
}
