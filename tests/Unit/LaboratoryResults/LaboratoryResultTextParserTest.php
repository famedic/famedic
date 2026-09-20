<?php

namespace Tests\Unit\LaboratoryResults;

use App\Services\LaboratoryResults\Extraction\LaboratoryAnalyteNameNormalizer;
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
        $this->parser = new LaboratoryResultTextParser;
    }

    #[Test]
    public function parsea_quimica_sanguinea_simple_estilo_gda_valor_primero(): void
    {
        $candidates = $this->parseLines([
            '84(A) GLUCOSA mg/dL 70-99',
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame('GLUCOSA', $candidates[0]->analyteNameRaw);
        $this->assertSame(84.0, $candidates[0]->numericValue);
        $this->assertSame('mg/dL', $candidates[0]->unit);
        $this->assertSame('numeric_gda_value_first_v1', $candidates[0]->parseRule);
    }

    #[Test]
    public function parsea_multiples_analitos_de_quimica(): void
    {
        $candidates = $this->parseLines([
            '84(A) GLUCOSA mg/dL 70-99',
            '0.77(A) CREATININA mg/dL 0.55-1.10',
            '5.9(A) ACIDO URICO mg/dL 2.60-6.00',
        ]);

        $this->assertCount(3, $candidates);
        $keys = array_map(
            fn ($c) => LaboratoryAnalyteNameNormalizer::normalize($c->analyteNameRaw),
            $candidates,
        );
        $this->assertContains('glucosa', $keys);
        $this->assertContains('creatinina', $keys);
        $this->assertContains('acido urico', $keys);

        $creatinina = collect($candidates)->first(
            fn ($c) => LaboratoryAnalyteNameNormalizer::normalize($c->analyteNameRaw) === 'creatinina',
        );
        $this->assertSame(0.77, $creatinina->numericValue);
        $this->assertSame('0.55-1.10', $creatinina->referenceText);
        $this->assertSame(1.1, $creatinina->referenceHigh);
    }

    #[Test]
    public function parsea_unidades_diferentes(): void
    {
        $candidates = $this->parseLines([
            'Glucosa 95 mg/dL 70-100',
            '4.4(A) POTASIO mmol/L 3.5-5.0',
        ]);

        $this->assertCount(2, $candidates);
        $this->assertSame('mg/dL', $candidates[0]->unit);
        $this->assertSame('mmol/L', $candidates[1]->unit);
    }

    #[Test]
    public function parsea_referencia_low_high(): void
    {
        $candidates = $this->parseLines([
            'Hemoglobina 14.1 g/dL 11.7-16.3',
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame(11.7, $candidates[0]->referenceLow);
        $this->assertSame(16.3, $candidates[0]->referenceHigh);
        $this->assertSame('11.7-16.3', $candidates[0]->referenceText);
    }

    #[Test]
    public function parsea_referencia_menor_que(): void
    {
        $candidates = $this->parseLines([
            'Urea 38 mg/dL < 50',
        ]);

        $this->assertCount(1, $candidates);
        $this->assertNull($candidates[0]->referenceLow);
        $this->assertSame(50.0, $candidates[0]->referenceHigh);
        $this->assertSame('< 50', $candidates[0]->referenceText);
    }

    #[Test]
    public function parsea_referencia_mayor_que(): void
    {
        $candidates = $this->parseLines([
            'Creatinina 1.1 mg/dL > 0.6',
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame(0.6, $candidates[0]->referenceLow);
        $this->assertNull($candidates[0]->referenceHigh);
    }

    #[Test]
    public function ignora_encabezados_repetidos_de_quimica(): void
    {
        $candidates = $this->parseLines([
            'QUIMICA SANGUINEA 50 ELEMENTOS',
            '84(A) GLUCOSA mg/dL 70-99',
            'QUIMICA SANGUINEA 50 ELEMENTOS',
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame('GLUCOSA', $candidates[0]->analyteNameRaw);
    }

    #[Test]
    public function ignora_disclaimer_legal(): void
    {
        $candidates = $this->parseLines([
            'Cualquier aclaración solicitarla como máximo 6 días después de la emisión de su resultado.',
            '84(A) GLUCOSA mg/dL 70-99',
        ]);

        $this->assertCount(1, $candidates);
    }

    #[Test]
    public function rechaza_fila_invalida_sin_valor_numerico_claro(): void
    {
        $candidates = $this->parseLines([
            '(A) COLESTEROL TOTAL mg/dL',
            'Glucosa mg/dL 70-100',
        ]);

        $this->assertSame([], $candidates);
    }

    #[Test]
    public function multi_columna_relativa_y_absoluta_no_se_mezclan(): void
    {
        $candidates = $this->parseLines([
            'NEUTROFILOS SEGMENTADOS 50.3 % 40-70',
            '4.0 miles/uL',
            'NEUTROFILOS SEGMENTADOS 4.0 miles/uL 1.5-7.0',
        ]);

        $this->assertCount(2, $candidates);
        $this->assertSame('NEUTROFILOS SEGMENTADOS', $candidates[0]->analyteNameRaw);
        $this->assertSame('%', $candidates[0]->unit);
        $this->assertSame('NEUTROFILOS SEGMENTADOS', $candidates[1]->analyteNameRaw);
        $this->assertSame('miles/uL', $candidates[1]->unit);
    }

    #[Test]
    public function no_crea_candidatos_para_texto_narrativo(): void
    {
        $candidates = $this->parseLines([
            '2.62 Riesgo aterogénico bajo: Menor de 3.0RELACION LDL/HDL',
            'Indice aterogénico (col. total/col- HDL)',
        ]);

        $this->assertSame([], $candidates);
    }

    #[Test]
    public function no_crea_candidatos_para_encabezados_de_riesgo(): void
    {
        $candidates = $this->parseLines([
            'RIESGO MODERADO 200 - 240',
            'RIESGO ALTO 200 - 500',
            '84(A) GLUCOSA mg/dL 70-99',
        ]);

        $this->assertCount(1, $candidates);
    }

    #[Test]
    public function parsea_gda_flag_prefix_con_referencia_fusionada(): void
    {
        $candidates = $this->parseLines([
            '(A) GLUCOSA mg/dL 60-10093',
            '(A) CREATININA mg/dL 0.55-1.020.8',
        ]);

        $this->assertCount(2, $candidates);
        $this->assertSame(93.0, $candidates[0]->numericValue);
        $this->assertSame('60-100', $candidates[0]->referenceText);
        $this->assertSame(0.8, $candidates[1]->numericValue);
        $this->assertSame('0.55-1.02', $candidates[1]->referenceText);
    }

    #[Test]
    public function parsea_gda_unidad_fusionada_con_valor(): void
    {
        $candidates = $this->parseLines([
            '(A) TRIGLICERIDOS mg/dL63.00',
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame('TRIGLICERIDOS', $candidates[0]->analyteNameRaw);
        $this->assertSame(63.0, $candidates[0]->numericValue);
        $this->assertSame('mg/dL', $candidates[0]->unit);
    }

    #[Test]
    public function asocia_referencia_categorica_gda_real_a_trigliceridos(): void
    {
        $candidates = $this->parseLines([
            'DESEABLE                        < 150',
            'RIESGO MODERADO 150 - 199',
            'RIESGO ALTO 200 - 500',
            'RIESGO MUY ALTO > 500',
            '(A) TRIGLICERIDOS mg/dL63.00',
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame('TRIGLICERIDOS', $candidates[0]->analyteNameRaw);
        $this->assertSame(63.0, $candidates[0]->numericValue);
        $this->assertSame('mg/dL', $candidates[0]->unit);
        $this->assertSame('<150', $candidates[0]->referenceText);
        $this->assertNull($candidates[0]->referenceLow);
        $this->assertSame(150.0, $candidates[0]->referenceHigh);
        $this->assertSame('numeric_gda_categorical_reference_v1', $candidates[0]->parseRule);
    }

    #[Test]
    public function asocia_referencia_categorica_gda_multilinea_a_trigliceridos(): void
    {
        $candidates = $this->parseLines([
            'TRIGLICERIDOS',
            '120',
            'mg/dL',
            'DESEABLE',
            '<150',
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame('TRIGLICERIDOS', $candidates[0]->analyteNameRaw);
        $this->assertSame(120.0, $candidates[0]->numericValue);
        $this->assertSame('mg/dL', $candidates[0]->unit);
        $this->assertSame('<150', $candidates[0]->referenceText);
        $this->assertSame(150.0, $candidates[0]->referenceHigh);
        $this->assertSame('numeric_gda_multiline_categorical_reference_v1', $candidates[0]->parseRule);
    }

    #[Test]
    public function no_asocia_referencia_categorica_si_aparece_despues_de_otro_analito(): void
    {
        $candidates = $this->parseLines([
            '(A) TRIGLICERIDOS mg/dL120.00',
            '(A) COLESTEROL TOTAL mg/dL207.00',
            'DESEABLE                        < 150',
        ]);

        $trigliceridos = collect($candidates)->first(
            fn ($candidate) => $candidate->analyteNameRaw === 'TRIGLICERIDOS',
        );

        $this->assertNotNull($trigliceridos);
        $this->assertNull($trigliceridos->referenceText);
        $this->assertNull($trigliceridos->referenceHigh);
    }

    #[Test]
    public function parsea_trigliceridos_con_referencia_directa_menor_que_sin_regla_multilinea(): void
    {
        $candidates = $this->parseLines([
            'TRIGLICERIDOS 120 mg/dL <150',
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame('TRIGLICERIDOS', $candidates[0]->analyteNameRaw);
        $this->assertSame(120.0, $candidates[0]->numericValue);
        $this->assertSame('<150', $candidates[0]->referenceText);
        $this->assertSame(150.0, $candidates[0]->referenceHigh);
        $this->assertSame('numeric_with_unit_v1', $candidates[0]->parseRule);
    }

    #[Test]
    public function soporta_desigualdades_categoricas_con_operador_inclusivo(): void
    {
        $candidates = $this->parseLines([
            'DESEABLE                        <=100',
            '(A) TRIGLICERIDOS mg/dL90.00',
            'DESEABLE                        >=10',
            '(A) TRIGLICERIDOS mg/dL90.00',
        ]);

        $this->assertCount(2, $candidates);
        $this->assertSame('<=100', $candidates[0]->referenceText);
        $this->assertSame(100.0, $candidates[0]->referenceHigh);
        $this->assertSame('>=10', $candidates[1]->referenceText);
        $this->assertSame(10.0, $candidates[1]->referenceLow);
    }

    #[Test]
    public function parsea_tabs_como_separadores_de_columna(): void
    {
        $candidates = $this->parseLines([
            "84(A)\tGLUCOSA\tmg/dL\t70-99",
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame('GLUCOSA', $candidates[0]->analyteNameRaw);
    }

    #[Test]
    public function hemograma_estilo_nombre_primero_sigue_funcionando(): void
    {
        $candidates = $this->parseLines([
            'HEMOGLOBINA 14.1 g/dL 11.7-16.3',
            'HEMATOCRITO 41.1 % 35.4-49.4',
            'ERITROCITOS 4.86 mill/µL 3.87-5.44',
        ]);

        $this->assertCount(3, $candidates);
        $this->assertSame('numeric_with_unit_v1', $candidates[0]->parseRule);
    }

    #[Test]
    public function parsea_gda_fusion_acido_urico_caso_real_8c_13a(): void
    {
        $candidates = $this->parseLines([
            '(A) ACIDO URICO mg/dL 2.60-6.003.9',
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame('ACIDO URICO', $candidates[0]->analyteNameRaw);
        $this->assertSame(3.9, $candidates[0]->numericValue);
        $this->assertSame('mg/dL', $candidates[0]->unit);
        $this->assertSame('2.60-6.00', $candidates[0]->referenceText);
        $this->assertSame(2.6, $candidates[0]->referenceLow);
        $this->assertSame(6.0, $candidates[0]->referenceHigh);
    }

    #[Test]
    public function parsea_gda_referencia_decimal_completa_sin_truncar(): void
    {
        $candidates = $this->parseLines([
            '0.77(A) CREATININA mg/dL 0.55-1.10',
            '5.9(A) ACIDO URICO mg/dL 2.60-6.00',
        ]);

        $this->assertCount(2, $candidates);
        $this->assertSame('0.55-1.10', $candidates[0]->referenceText);
        $this->assertSame(1.1, $candidates[0]->referenceHigh);
        $this->assertSame('2.60-6.00', $candidates[1]->referenceText);
        $this->assertSame(6.0, $candidates[1]->referenceHigh);
    }

    #[Test]
    public function parsea_rangos_normales_sin_valor_fusionado(): void
    {
        $candidates = $this->parseLines([
            'Creatinina 0.92 mg/dL 0.55-1.02',
            'Acido urico 3.9 mg/dL 2.60-6.00',
        ]);

        $this->assertCount(2, $candidates);
        $this->assertSame(0.92, $candidates[0]->numericValue);
        $this->assertSame('0.55-1.02', $candidates[0]->referenceText);
        $this->assertSame(3.9, $candidates[1]->numericValue);
        $this->assertSame('2.60-6.00', $candidates[1]->referenceText);
    }

    #[Test]
    public function preserva_valores_decimales_normales(): void
    {
        $candidates = $this->parseLines([
            'Glucosa 0.8 mg/dL 70-99',
            'Glucosa 3.9 mg/dL 70-99',
            'Glucosa 10.25 mg/dL 70-99',
            'Glucosa 1.020 mg/dL 70-99',
            'Glucosa 100.00 mg/dL 70-99',
        ]);

        $this->assertCount(5, $candidates);
        $this->assertSame(0.8, $candidates[0]->numericValue);
        $this->assertSame(3.9, $candidates[1]->numericValue);
        $this->assertSame(10.25, $candidates[2]->numericValue);
        $this->assertSame(1.02, $candidates[3]->numericValue);
        $this->assertSame(100.0, $candidates[4]->numericValue);
    }

    #[Test]
    public function preserva_valores_enteros_y_desigualdades(): void
    {
        $candidates = $this->parseLines([
            'Plaquetas 365 miles/uL 150-450',
            'Leucocitos 105 miles/uL 4.5-11.0',
            'HGM 29 pg 27-34',
            'Trigliceridos 63 mg/dL < 150',
            'Glucosa 95 mg/dL > 60',
        ]);

        $this->assertCount(5, $candidates);
        $this->assertSame(365.0, $candidates[0]->numericValue);
        $this->assertSame(105.0, $candidates[1]->numericValue);
        $this->assertSame(29.0, $candidates[2]->numericValue);
        $this->assertSame('< 150', $candidates[3]->referenceText);
        $this->assertSame(60.0, $candidates[4]->referenceLow);
    }

    #[Test]
    public function no_infiere_fusion_en_caso_ambiguo(): void
    {
        $candidates = $this->parseLines([
            '3.1415',
            'Glucosa 3.1415 mg/dL 70-99',
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame(3.1415, $candidates[0]->numericValue);
    }

    /**
     * @param  list<string>  $lines
     * @return list<\App\Services\LaboratoryResults\Extraction\LaboratoryResultObservationCandidate>
     */
    private function parseLines(array $lines): array
    {
        $text = implode("\n", $lines);

        return $this->parser->parse(new LaboratoryResultTextExtractionResult(
            pages: [[
                'page' => 1,
                'text' => $text,
                'char_count' => mb_strlen($text, 'UTF-8'),
            ]],
            fullText: $text,
            pageCount: 1,
            success: true,
        ));
    }
}
