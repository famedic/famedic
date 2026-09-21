<?php

namespace App\Services\LaboratoryResults;

use App\Enums\LaboratoryResultPdfClassification;
use Smalot\PdfParser\Parser;
use Throwable;

class LaboratoryResultPdfClassifier
{
    public const CLASSIFIER_NAME = 'deterministic_pdf_v1';

    public const GDA_LABORATORY_RESULTS_TABLE_RULE = 'gda_laboratory_results_table_v1';

    private const MIN_GDA_LABORATORY_RESULT_ROWS = 2;

    private Parser $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? new Parser;
    }

    public function classifyBinary(string $pdfBinary): LaboratoryResultPdfClassificationResult
    {
        try {
            $pdf = $this->parser->parseContent($pdfBinary);
            $text = (string) $pdf->getText();
        } catch (Throwable) {
            return new LaboratoryResultPdfClassificationResult(
                classification: LaboratoryResultPdfClassification::Unknown,
                reason: 'pdf_parse_failed',
                matchedRule: null,
                confidence: 0.0,
            );
        }

        return $this->classifyText($text);
    }

    public function classifyText(string $text): LaboratoryResultPdfClassificationResult
    {
        $normalized = $this->normalizeText($text);

        if ($normalized === '') {
            return new LaboratoryResultPdfClassificationResult(
                classification: LaboratoryResultPdfClassification::Unknown,
                reason: 'unknown_document',
                matchedRule: null,
                confidence: 0.0,
            );
        }

        if ($this->hasPendingInterpretationPhrase($normalized)) {
            return new LaboratoryResultPdfClassificationResult(
                classification: LaboratoryResultPdfClassification::PendingInterpretation,
                reason: 'pending_interpretation_phrase',
                matchedRule: 'gda_interpretation_not_performed_v1',
                confidence: 1.0,
            );
        }

        if ($this->hasGdaLaboratoryResultsTable($normalized)) {
            return new LaboratoryResultPdfClassificationResult(
                classification: LaboratoryResultPdfClassification::Complete,
                reason: self::GDA_LABORATORY_RESULTS_TABLE_RULE,
                matchedRule: self::GDA_LABORATORY_RESULTS_TABLE_RULE,
                confidence: 1.0,
            );
        }

        if ($this->hasExplicitCompleteSignal($normalized)) {
            return new LaboratoryResultPdfClassificationResult(
                classification: LaboratoryResultPdfClassification::Complete,
                reason: 'explicit_complete_signal',
                matchedRule: 'gda_explicit_complete_signal_v1',
                confidence: 1.0,
            );
        }

        return new LaboratoryResultPdfClassificationResult(
            classification: LaboratoryResultPdfClassification::Unknown,
            reason: 'unknown_document',
            matchedRule: null,
            confidence: 0.0,
        );
    }

    public function normalizeText(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, [
            'á' => 'a',
            'à' => 'a',
            'ä' => 'a',
            'â' => 'a',
            'é' => 'e',
            'è' => 'e',
            'ë' => 'e',
            'ê' => 'e',
            'í' => 'i',
            'ì' => 'i',
            'ï' => 'i',
            'î' => 'i',
            'ó' => 'o',
            'ò' => 'o',
            'ö' => 'o',
            'ô' => 'o',
            'ú' => 'u',
            'ù' => 'u',
            'ü' => 'u',
            'û' => 'u',
            'ñ' => 'n',
        ]);
        $text = preg_replace('/[^\p{L}\p{N}\s.]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function hasPendingInterpretationPhrase(string $normalizedText): bool
    {
        if (str_contains($normalizedText, 'la interpretacion de este estudio aun no se ha realizado')) {
            return true;
        }

        return (bool) preg_match(
            '/interpretacion\s+de\s+este\s+estudio\s+aun\s+no\s+se\s+ha\s+realizado/u',
            $normalizedText
        );
    }

    /**
     * Tabla SwissLab/GDA: exige (A) encabezado de columnas y (B) filas analíticas reales.
     * El header solo no basta (evita falsos positivos); filas sueltas sin header tampoco.
     */
    private function hasGdaLaboratoryResultsTable(string $normalizedText): bool
    {
        if (! preg_match(
            '/estudio\s+resultado\s+unidades\s+valores(?:\s+de)?\s+referencia/u',
            $normalizedText
        )) {
            return false;
        }

        $rowCount = preg_match_all(
            '/\b\d+(?:\.\d+)?\s+a\s+[a-z]+(?:\s+[a-z]+){0,8}\s+(?:mg|g|mmol|ui|iu|meq|pg|ng|ug|mcg)(?:\s+[a-z]{1,4}){0,2}\b/u',
            $normalizedText
        );

        return $rowCount >= self::MIN_GDA_LABORATORY_RESULT_ROWS;
    }

    private function hasExplicitCompleteSignal(string $normalizedText): bool
    {
        if (preg_match('/resultado\s+final\s+(pendiente|parcial|preliminar|provisional)/u', $normalizedText)) {
            return false;
        }

        if (preg_match('/interpretacion\s+realizada\s+(parcial|parcialmente|pendiente|preliminar|provisional)/u', $normalizedText)) {
            return false;
        }

        if (preg_match('/estudio\s+interpretado\s+(parcial|parcialmente|pendiente|preliminar|provisional)/u', $normalizedText)) {
            return false;
        }

        return (bool) preg_match('/\bresultado\s+final\b/u', $normalizedText)
            || (bool) preg_match('/\binterpretacion\s+realizada\b/u', $normalizedText)
            || (bool) preg_match('/\bestudio\s+interpretado\b/u', $normalizedText);
    }
}
