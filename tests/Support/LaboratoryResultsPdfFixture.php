<?php

namespace Tests\Support;

use Dompdf\Dompdf;

final class LaboratoryResultsPdfFixture
{
    public static function binary(array $lines, ?array $secondPageLines = null, bool $includeHeader = true): string
    {
        $body = '<html><body style="font-family: DejaVu Sans, sans-serif; font-size: 12px;">';

        if ($includeHeader) {
            $body .= '<p>Resultado final</p>';
        }

        if ($lines !== []) {
            $body .= '<p>'.implode('<br/>', array_map('e', $lines)).'</p>';
        }

        if ($secondPageLines !== null) {
            $body .= '<div style="page-break-before: always;"></div>';
            $body .= '<p>'.implode('<br/>', array_map('e', $secondPageLines)).'</p>';
        }

        $body .= '</body></html>';

        $dompdf = new Dompdf;
        $dompdf->loadHtml($body);
        $dompdf->render();

        return $dompdf->output();
    }

    public static function standardResultsLines(): array
    {
        return [
            'Glucosa        95       mg/dL       70-100',
            'Hemoglobina    14.2     g/dL        12 - 16',
            'Urea           38       mg/dL       < 50',
            'Creatinina     1.1      mg/dL       > 0.6',
            'Proteina C     Negativo',
        ];
    }
}
