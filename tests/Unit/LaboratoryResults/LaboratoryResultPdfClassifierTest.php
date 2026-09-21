<?php

namespace Tests\Unit\LaboratoryResults;

use App\Enums\LaboratoryResultPdfClassification;
use App\Services\LaboratoryResults\LaboratoryResultPdfClassifier;
use Dompdf\Dompdf;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryResultPdfClassifierTest extends TestCase
{
    #[Test]
    public function detecta_frase_pendiente_en_pdf_gda(): void
    {
        $result = app(LaboratoryResultPdfClassifier::class)->classifyBinary(
            $this->pdfWithText('La interpretacion de este estudio aun no se ha realizado.')
        );

        $this->assertSame(LaboratoryResultPdfClassification::PendingInterpretation, $result->classification);
        $this->assertSame('gda_interpretation_not_performed_v1', $result->matchedRule);
    }

    #[Test]
    public function normaliza_mayusculas_espacios_y_acentos(): void
    {
        $result = app(LaboratoryResultPdfClassifier::class)->classifyText(
            "LA   INTERPRETACIÓN de este estudio\nAÚN no se ha realizado."
        );

        $this->assertSame(LaboratoryResultPdfClassification::PendingInterpretation, $result->classification);
    }

    #[Test]
    public function no_asume_completo_cuando_no_hay_regla_deterministica(): void
    {
        $result = app(LaboratoryResultPdfClassifier::class)->classifyBinary(
            $this->pdfWithText('Resultado disponible para consulta medica.')
        );

        $this->assertSame(LaboratoryResultPdfClassification::Unknown, $result->classification);
        $this->assertSame('unknown_document', $result->reason);
    }

    #[Test]
    public function detecta_complete_solo_con_senal_explicita(): void
    {
        $result = app(LaboratoryResultPdfClassifier::class)->classifyBinary(
            $this->pdfWithText('Resultado final. Interpretacion realizada.')
        );

        $this->assertSame(LaboratoryResultPdfClassification::Complete, $result->classification);
        $this->assertSame('gda_explicit_complete_signal_v1', $result->matchedRule);
    }

    #[Test]
    public function clasifica_tabla_swisslab_gda_como_complete(): void
    {
        // Purchase #2009 / HD0L001354: PERFIL BIOQUIMICO 24 con tabla GDA real.
        $result = app(LaboratoryResultPdfClassifier::class)->classifyText($this->swissLabGdaLaboratoryResultsText());

        $this->assertSame(LaboratoryResultPdfClassification::Complete, $result->classification);
        $this->assertSame('gda_laboratory_results_table_v1', $result->reason);
        $this->assertSame('gda_laboratory_results_table_v1', $result->matchedRule);
    }

    #[Test]
    public function contrato_objetivo_tabla_swisslab_debe_clasificarse_como_complete(): void
    {
        $result = app(LaboratoryResultPdfClassifier::class)->classifyText($this->swissLabGdaLaboratoryResultsText());

        $this->assertSame(LaboratoryResultPdfClassification::Complete, $result->classification);
        $this->assertSame('gda_laboratory_results_table_v1', $result->reason);
        $this->assertSame('gda_laboratory_results_table_v1', $result->matchedRule);
    }

    #[Test]
    public function header_gda_sin_filas_analiticas_suficientes_permanece_unknown(): void
    {
        $result = app(LaboratoryResultPdfClassifier::class)->classifyText(
            'ESTUDIO RESULTADO UNIDADES VALORES DE REFERENCIA 78(A) GLUCOSA mg/dL 60-100'
        );

        $this->assertSame(LaboratoryResultPdfClassification::Unknown, $result->classification);
        $this->assertSame('unknown_document', $result->reason);
    }

    #[Test]
    public function pending_con_tabla_laboratorio_conserva_pending_interpretation(): void
    {
        $result = app(LaboratoryResultPdfClassifier::class)->classifyText(implode("\n", [
            $this->swissLabGdaLaboratoryResultsText(),
            'La interpretación de este estudio aún no se ha realizado.',
        ]));

        $this->assertSame(LaboratoryResultPdfClassification::PendingInterpretation, $result->classification);
        $this->assertSame('gda_interpretation_not_performed_v1', $result->matchedRule);
    }

    #[Test]
    public function conserva_pending_interpretation_para_documento_pendiente(): void
    {
        $result = app(LaboratoryResultPdfClassifier::class)->classifyText(
            'La interpretación de este estudio aún no se ha realizado.'
        );

        $this->assertSame(LaboratoryResultPdfClassification::PendingInterpretation, $result->classification);
    }

    #[Test]
    public function clasifica_texto_insuficiente_como_unknown(): void
    {
        $result = app(LaboratoryResultPdfClassifier::class)->classifyText('Documento disponible para consulta.');

        $this->assertSame(LaboratoryResultPdfClassification::Unknown, $result->classification);
        $this->assertSame('unknown_document', $result->reason);
    }

    #[Test]
    public function clasifica_texto_vacio_como_unknown(): void
    {
        $result = app(LaboratoryResultPdfClassifier::class)->classifyText('');

        $this->assertSame(LaboratoryResultPdfClassification::Unknown, $result->classification);
        $this->assertSame('unknown_document', $result->reason);
    }

    #[Test]
    public function clasifica_documento_mixto_con_tabla_laboratorio_como_complete(): void
    {
        // Purchase #2009: PERFIL BIOQUIMICO 24 + REPORTE RADIOLOGICO en el mismo PDF.
        $result = app(LaboratoryResultPdfClassifier::class)->classifyText($this->mixedSwissLabGdaDocumentText());

        $this->assertSame(LaboratoryResultPdfClassification::Complete, $result->classification);
        $this->assertSame('gda_laboratory_results_table_v1', $result->reason);
        $this->assertSame('gda_laboratory_results_table_v1', $result->matchedRule);
    }

    #[Test]
    public function contrato_objetivo_documento_mixto_debe_clasificarse_como_complete(): void
    {
        $result = app(LaboratoryResultPdfClassifier::class)->classifyText($this->mixedSwissLabGdaDocumentText());

        $this->assertSame(LaboratoryResultPdfClassification::Complete, $result->classification);
        $this->assertSame('gda_laboratory_results_table_v1', $result->reason);
        $this->assertSame('gda_laboratory_results_table_v1', $result->matchedRule);
    }

    #[Test]
    public function no_clasifica_complete_con_senales_positivas_ambiguas(): void
    {
        foreach ([
            'Resultado final pendiente de validacion.',
            'Interpretacion realizada parcialmente.',
            'Estudio interpretado preliminar.',
        ] as $text) {
            $result = app(LaboratoryResultPdfClassifier::class)->classifyBinary(
                $this->pdfWithText($text)
            );

            $this->assertSame(LaboratoryResultPdfClassification::Unknown, $result->classification);
        }
    }

    private function pdfWithText(string $text): string
    {
        $dompdf = new Dompdf;
        $dompdf->loadHtml('<html><body>'.e($text).'</body></html>');
        $dompdf->render();

        return $dompdf->output();
    }

    private function swissLabGdaLaboratoryResultsText(): string
    {
        return implode("\n", [
            'SwissLab S.A. de C.V.',
            'Solicitud: HD0L001354',
            'ESTUDIO RESULTADO UNIDADES VALORES DE REFERENCIA',
            '(A) PERFIL BIOQUIMICO 24 (ELEC,CALCIO,FOS,TGP)',
            '78(A) GLUCOSA mg/dL 60-100',
            '5.9(A) NITROGENO UREICO mg/dL 6-20',
            '12.6(A) UREA SERICA mg/dL 10-50',
            '0.6(A) CREATININA mg/dL 0.55-1.02',
            '185.00(A) COLESTEROL TOTAL mg/dL DESEABLE < 200',
            '239.00(A) TRIGLICERIDOS mg/dL DESEABLE < 150',
            'Método: Química seca',
            'Muestra: SUERO',
            'Liberación: 2026-07-14 13:58',
        ]);
    }

    private function mixedSwissLabGdaDocumentText(): string
    {
        return implode("\n", [
            $this->swissLabGdaLaboratoryResultsText(),
            'REPORTE RADIOLOGICO',
            'US ABDOMINAL SUPERIOR',
            'ECOGRAFÍA DE ABDOMEN SUPERIOR',
            'IMPRESIÓN DIAGNÓSTICA',
            'Hígado de tamaño y ecogenicidad normal. Vesícula biliar sin litiasis.',
        ]);
    }
}
