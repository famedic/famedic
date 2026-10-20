<?php

namespace App\Services\LaboratoryResults\Extraction;

/**
 * Aplica cajas negras sobre tokens PII residuales en imágenes rasterizadas.
 */
class LaboratoryResultVisionPiiVisualRedactor
{
    public function __construct(
        private readonly LaboratoryResultVisionPiiPatternMatcher $piiPatternMatcher,
    ) {}

    /**
     * @param  resource|\GdImage  $image
     * @param  list<array{text: string, top: float, left: float, width: float, height: float}>  $words
     * @return array{image: \GdImage, redaction_box_count: int, residual_pii: bool}
     */
    public function redactCropRegion($image, array $words, float $cropTopPt, int $dpi): array
    {
        if (! extension_loaded('gd')) {
            return [
                'image' => $image,
                'redaction_box_count' => 0,
                'residual_pii' => $this->piiPatternMatcher->findPiiWords($words, $cropTopPt) !== [],
            ];
        }

        $piiWords = $this->piiPatternMatcher->findPiiWords($words, $cropTopPt);
        $scale = $dpi / 72.0;
        $black = imagecolorallocate($image, 0, 0, 0);
        $boxCount = 0;

        foreach ($piiWords as $word) {
            $x = (int) floor($word['left'] * $scale);
            $y = (int) floor(($word['top'] - $cropTopPt) * $scale);
            $width = max(1, (int) ceil($word['width'] * $scale) + 2);
            $height = max(1, (int) ceil($word['height'] * $scale) + 2);

            imagefilledrectangle($image, $x, $y, $x + $width, $y + $height, $black);
            $boxCount++;
        }

        return [
            'image' => $image,
            'redaction_box_count' => $boxCount,
            'residual_pii' => $piiWords !== [] && $boxCount === 0,
        ];
    }
}
