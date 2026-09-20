<?php

namespace Tests\Unit\LaboratoryResults;

use App\Enums\LaboratoryResultObservationValueType;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionResponseParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryResultVisionResponseParserTest extends TestCase
{
    private LaboratoryResultVisionResponseParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new LaboratoryResultVisionResponseParser;
    }

    #[Test]
    public function parsea_json_valido(): void
    {
        $candidates = $this->parser->parse([
            'reported_at' => null,
            'observations' => [
                [
                    'analyte_name_raw' => 'Glucosa',
                    'value' => '95',
                    'value_type' => 'numeric',
                    'unit' => 'mg/dL',
                    'reference_text' => '70-100',
                    'panel_name_raw' => null,
                    'source_page' => 1,
                    'confidence' => 0.98,
                ],
            ],
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame('Glucosa', $candidates[0]->analyteNameRaw);
        $this->assertSame(LaboratoryResultObservationValueType::Numeric, $candidates[0]->valueType);
        $this->assertSame(95.0, $candidates[0]->numericValue);
        $this->assertSame('vision_v3', $candidates[0]->parseRule);
    }

    #[Test]
    public function rechaza_json_sin_observations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->parser->parse(['reported_at' => null]);
    }

    #[Test]
    public function omite_observation_incompleta(): void
    {
        $candidates = $this->parser->parse([
            'reported_at' => null,
            'observations' => [
                [
                    'analyte_name_raw' => '',
                    'value' => '95',
                    'value_type' => 'numeric',
                    'unit' => 'mg/dL',
                    'reference_text' => null,
                    'panel_name_raw' => null,
                    'source_page' => 1,
                    'confidence' => 0.5,
                ],
            ],
        ]);

        $this->assertSame([], $candidates);
    }

    #[Test]
    public function retorna_vacio_sin_observations(): void
    {
        $candidates = $this->parser->parse([
            'reported_at' => null,
            'observations' => [],
        ]);

        $this->assertSame([], $candidates);
    }

    #[Test]
    public function hgm_conserva_pg(): void
    {
        $candidates = $this->parser->parse([
            'reported_at' => null,
            'observations' => [$this->observation('HGM', '29', 'pg', '26.8-33.2')],
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame(29.0, $candidates[0]->numericValue);
        $this->assertSame('pg', $candidates[0]->unit);
    }

    #[Test]
    public function rdw_conserva_valor_correcto(): void
    {
        $candidates = $this->parser->parse([
            'reported_at' => null,
            'observations' => [$this->observation('RDW', '13.1', '%', '12.0-17.7')],
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame(13.1, $candidates[0]->numericValue);
        $this->assertSame('%', $candidates[0]->unit);
    }

    #[Test]
    public function eritrocitos_conserva_unidad(): void
    {
        $candidates = $this->parser->parse([
            'reported_at' => null,
            'observations' => [$this->observation('Eritrocitos', '4.86', 'mill/µL', '3.87-5.44')],
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame('mill/µL', $candidates[0]->unit);
        $this->assertSame('mill/µL', $candidates[0]->unitRaw);
    }

    #[Test]
    public function eritrocitos_conserva_reference_text(): void
    {
        $candidates = $this->parser->parse([
            'reported_at' => null,
            'observations' => [$this->observation('Eritrocitos', '4.86', 'mill/µL', '3.87-5.44')],
        ]);

        $this->assertSame('3.87-5.44', $candidates[0]->referenceText);
        $this->assertSame(3.87, $candidates[0]->referenceLow);
        $this->assertSame(5.44, $candidates[0]->referenceHigh);
    }

    #[Test]
    public function dos_columnas_producen_dos_observations(): void
    {
        $candidates = $this->parser->parse([
            'reported_at' => null,
            'observations' => [
                $this->observation('Neutrófilos segmentados', '50.3', '%', '39.6 - 76.1'),
                $this->observation('Neutrófilos segmentados', '4.0', 'miles/uL', '1.7 - 6.5'),
            ],
        ]);

        $this->assertCount(2, $candidates);
        $this->assertSame(50.3, $candidates[0]->numericValue);
        $this->assertSame('%', $candidates[0]->unit);
        $this->assertSame('39.6 - 76.1', $candidates[0]->referenceText);
        $this->assertSame(4.0, $candidates[1]->numericValue);
        $this->assertSame('miles/uL', $candidates[1]->unit);
        $this->assertSame('1.7 - 6.5', $candidates[1]->referenceText);
    }

    #[Test]
    public function no_acepta_unidad_mezclada_porcentaje_y_volumen(): void
    {
        $candidates = $this->parser->parse([
            'reported_at' => null,
            'observations' => [
                $this->observation('Neutrófilos segmentados', '50.3', '% miles/uL', '39.6 - 76.1'),
            ],
        ]);

        $this->assertSame([], $candidates);
    }

    #[Test]
    public function no_confunde_referencia_como_valor(): void
    {
        $candidates = $this->parser->parse([
            'reported_at' => null,
            'observations' => [
                $this->observation('RDW', '12.0-17.7', '%', null),
            ],
        ]);

        $this->assertSame([], $candidates);
    }

    #[Test]
    public function flag_documental_no_forma_parte_del_valor(): void
    {
        $candidates = $this->parser->parse([
            'reported_at' => null,
            'observations' => [
                array_merge($this->observation('GLUCOSA', '84(A)', 'mg/dL', '70-99'), [
                    'confidence' => 0.98,
                ]),
            ],
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame(84.0, $candidates[0]->numericValue);
        $this->assertLessThanOrEqual(0.85, $candidates[0]->confidence);
    }

    #[Test]
    public function unidad_ausente_queda_null(): void
    {
        $candidates = $this->parser->parse([
            'reported_at' => null,
            'observations' => [
                $this->observation('Creatinina', '0.9', null, '0.55-1.02'),
            ],
        ]);

        $this->assertCount(1, $candidates);
        $this->assertNull($candidates[0]->unit);
    }

    #[Test]
    public function referencia_ausente_queda_null(): void
    {
        $candidates = $this->parser->parse([
            'reported_at' => null,
            'observations' => [
                $this->observation('Creatinina', '0.9', 'mg/dL', null),
            ],
        ]);

        $this->assertCount(1, $candidates);
        $this->assertNull($candidates[0]->referenceText);
        $this->assertNull($candidates[0]->referenceLow);
        $this->assertNull($candidates[0]->referenceHigh);
    }

    #[Test]
    public function normaliza_referencia_categorica_gda_deseable(): void
    {
        $payload = [
            'reported_at' => null,
            'observations' => [
                $this->observation('TRIGLICERIDOS', '75.00', 'mg/dL', 'DESEABLE < 150', 'PERFIL BIOQUIMICO'),
            ],
        ];

        $candidates = $this->parser->parse($payload);

        $this->assertCount(1, $candidates);
        $this->assertSame('TRIGLICERIDOS', $candidates[0]->analyteNameRaw);
        $this->assertSame(75.0, $candidates[0]->numericValue);
        $this->assertSame('<150', $candidates[0]->referenceText);
        $this->assertNull($candidates[0]->referenceLow);
        $this->assertSame(150.0, $candidates[0]->referenceHigh);
        $this->assertSame('DESEABLE < 150', $payload['observations'][0]['reference_text']);
    }

    #[Test]
    public function no_normaliza_texto_clinico_arbitrario_como_referencia(): void
    {
        $candidates = $this->parser->parse([
            'reported_at' => null,
            'observations' => [
                $this->observation('TRIGLICERIDOS', '75.00', 'mg/dL', 'Meta clinica deseable menor a 150'),
            ],
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame('Meta clinica deseable menor a 150', $candidates[0]->referenceText);
        $this->assertNull($candidates[0]->referenceLow);
        $this->assertNull($candidates[0]->referenceHigh);
    }

    #[Test]
    public function tabla_ambigua_no_inventa_observation(): void
    {
        $candidates = $this->parser->parse([
            'reported_at' => null,
            'observations' => [
                [
                    'analyte_name_raw' => 'Neutrófilos segmentados',
                    'value' => null,
                    'value_type' => 'numeric',
                    'unit' => 'miles/uL',
                    'reference_text' => '1.7 - 6.5',
                    'panel_name_raw' => null,
                    'source_page' => 1,
                    'confidence' => 0.95,
                ],
            ],
        ]);

        $this->assertSame([], $candidates);
    }

    #[Test]
    public function encabezado_de_panel_no_genera_candidato(): void
    {
        $candidates = $this->parser->parse([
            'reported_at' => null,
            'observations' => [
                [
                    'analyte_name_raw' => 'QUIMICA SANGUINEA',
                    'value' => '50',
                    'value_type' => 'numeric',
                    'unit' => null,
                    'reference_text' => 'ELEMENTOS',
                    'panel_name_raw' => 'QUIMICA SANGUINEA',
                    'source_page' => 2,
                    'confidence' => 0.9,
                ],
                $this->observation('GLUCOSA', '84', 'mg/dL', '70-99', 'QUIMICA SANGUINEA'),
            ],
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame('GLUCOSA', $candidates[0]->analyteNameRaw);
    }

    #[Test]
    public function value_type_text_mapea_a_comment(): void
    {
        $candidates = $this->parser->parse([
            'reported_at' => null,
            'observations' => [[
                'analyte_name_raw' => 'Observacion',
                'value' => 'Negativo',
                'value_type' => 'text',
                'unit' => null,
                'reference_text' => null,
                'panel_name_raw' => null,
                'source_page' => 1,
                'confidence' => 0.9,
            ]],
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame(LaboratoryResultObservationValueType::Comment, $candidates[0]->valueType);
        $this->assertSame('Negativo', $candidates[0]->textValue);
    }

    #[Test]
    public function hgm_con_g_dl_es_rechazado_por_confusion_de_fila(): void
    {
        $candidates = $this->parser->parse([
            'reported_at' => null,
            'observations' => [$this->observation('HGM', '29', 'g/dL', '26.0-33.2')],
        ]);

        $this->assertSame([], $candidates);
    }

    #[Test]
    public function plaquetas_96_en_payload_pasa_sin_alteracion(): void
    {
        $candidates = $this->parser->parse([
            'reported_at' => null,
            'observations' => [$this->observation('PLAQUETAS', '96', 'miles/uL', '167-431')],
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame(96.0, $candidates[0]->numericValue);
        $this->assertSame('miles/uL', $candidates[0]->unit);
    }

    #[Test]
    public function plaquetas_con_valor_de_diferencial_es_rechazado(): void
    {
        $candidates = $this->parser->parse([
            'reported_at' => null,
            'observations' => [$this->observation('PLAQUETAS', '2.37', 'miles/uL', '167-431')],
        ]);

        $this->assertSame([], $candidates);
    }

    #[Test]
    public function plaquetas_con_valor_valido_se_conserva(): void
    {
        $candidates = $this->parser->parse([
            'reported_at' => null,
            'observations' => [$this->observation('Plaquetas', '365', 'miles/µL', '167-431')],
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame(365.0, $candidates[0]->numericValue);
    }

    #[Test]
    public function confidence_no_es_alta_cuando_unidad_es_incierta(): void
    {
        $candidates = $this->parser->parse([
            'reported_at' => null,
            'observations' => [
                array_merge($this->observation('HGM', '29', null, '26.8-33.2'), [
                    'confidence' => 0.99,
                ]),
            ],
        ]);

        $this->assertCount(1, $candidates);
        $this->assertLessThanOrEqual(0.55, $candidates[0]->confidence);
    }

    /**
     * @return array<string, mixed>
     */
    private function observation(
        string $analyte,
        ?string $value,
        ?string $unit,
        ?string $reference,
        ?string $panel = null,
    ): array {
        return [
            'analyte_name_raw' => $analyte,
            'value' => $value,
            'value_type' => 'numeric',
            'unit' => $unit,
            'reference_text' => $reference,
            'panel_name_raw' => $panel,
            'source_page' => 1,
            'confidence' => 0.95,
        ];
    }
}
