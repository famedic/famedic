<?php

namespace Tests\Unit\LaboratoryResults;

use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPromptDefinition;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryResultVisionPromptDefinitionTest extends TestCase
{
    #[Test]
    public function prompt_v2_contiene_reglas_de_integridad_de_fila(): void
    {
        $record = LaboratoryResultVisionPromptDefinition::record(
            LaboratoryResultVisionPromptDefinition::VERSION_V2
        );

        $this->assertSame(2, $record['version']);
        $this->assertStringContainsString('INTEGRIDAD DE FILA', $record['system_prompt']);
        $this->assertStringContainsString('Nunca combines 50.3 con miles/uL', $record['system_prompt']);
        $this->assertStringContainsString('NO conviertas ni normalices unidades equivalentes', $record['system_prompt']);
        $this->assertStringContainsString('(A)', $record['system_prompt']);
        $this->assertStringContainsString('{{page_numbers}}', $record['user_prompt']);
    }

    #[Test]
    public function prompt_v3_reforza_hemograma_gda(): void
    {
        $record = LaboratoryResultVisionPromptDefinition::record(
            LaboratoryResultVisionPromptDefinition::VERSION_V3
        );

        $this->assertSame(3, $record['version']);
        $this->assertStringContainsString('HGM (hemoglobina corpuscular media) → unidad pg', $record['system_prompt']);
        $this->assertStringContainsString('PLAQUETAS', $record['system_prompt']);
        $this->assertStringContainsString('VPM', $record['system_prompt']);
        $this->assertStringContainsString('Atención especial al hemograma GDA', $record['user_prompt']);
    }

    #[Test]
    public function schema_es_valido_y_contiene_campos_requeridos(): void
    {
        $schema = LaboratoryResultVisionPromptDefinition::responseSchema();

        $this->assertSame('object', $schema['type']);
        $this->assertContains('reported_at', $schema['required']);
        $this->assertContains('observations', $schema['required']);

        $item = $schema['properties']['observations']['items'];
        $required = $item['required'];

        $this->assertContains('analyte_name_raw', $required);
        $this->assertContains('value', $required);
        $this->assertContains('unit', $required);
        $this->assertContains('reference_text', $required);
        $this->assertContains('panel_name_raw', $required);
        $this->assertContains('confidence', $required);
    }
}
