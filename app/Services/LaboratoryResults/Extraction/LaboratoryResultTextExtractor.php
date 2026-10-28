<?php

namespace App\Services\LaboratoryResults\Extraction;

use Smalot\PdfParser\Parser;
use Throwable;

class LaboratoryResultTextExtractor
{
    public function __construct(
        private ?Parser $parser = null,
    ) {
        $this->parser ??= new Parser;
    }

    public function extractFromBinary(string $pdfBinary): LaboratoryResultTextExtractionResult
    {
        if ($pdfBinary === '' || ! str_starts_with($pdfBinary, '%PDF')) {
            return new LaboratoryResultTextExtractionResult(
                pages: [],
                fullText: '',
                pageCount: 0,
                success: false,
                errorCode: 'invalid_pdf',
                errorMessage: 'Payload is not a valid PDF.',
            );
        }

        try {
            $pdf = $this->parser->parseContent($pdfBinary);
            $pages = [];
            $pageObjects = $pdf->getPages();
            $pageCount = count($pageObjects);

            if ($pageCount === 0) {
                $fullText = trim((string) $pdf->getText());

                return new LaboratoryResultTextExtractionResult(
                    pages: $fullText !== '' ? [[
                        'page' => 1,
                        'text' => $fullText,
                        'char_count' => mb_strlen($fullText, 'UTF-8'),
                    ]] : [],
                    fullText: $fullText,
                    pageCount: $fullText !== '' ? 1 : 0,
                    success: true,
                );
            }

            foreach ($pageObjects as $index => $page) {
                $text = trim((string) $page->getText());
                $pages[] = [
                    'page' => $index + 1,
                    'text' => $text,
                    'char_count' => mb_strlen($text, 'UTF-8'),
                ];
            }

            $fullText = trim(implode("\n\n", array_column($pages, 'text')));

            return new LaboratoryResultTextExtractionResult(
                pages: $pages,
                fullText: $fullText,
                pageCount: $pageCount,
                success: true,
            );
        } catch (Throwable $exception) {
            return new LaboratoryResultTextExtractionResult(
                pages: [],
                fullText: '',
                pageCount: 0,
                success: false,
                errorCode: 'pdf_parse_failed',
                errorMessage: $exception->getMessage(),
            );
        }
    }
}
