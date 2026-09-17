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
}
