<?php

namespace App\Services\LaboratoryResults;

use App\Enums\LaboratoryResultPdfClassification;
use Smalot\PdfParser\Parser;
use Throwable;

class LaboratoryResultPdfClassifier
{
    public const CLASSIFIER_NAME = 'deterministic_pdf_v1';

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
