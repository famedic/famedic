<?php

namespace App\Services\LaboratoryResults\Extraction;

/**
 * @deprecated FASE 8C-10D — reemplazado por raster PDF real + input híbrido.
 *             Conservado solo para referencia histórica / tests legacy.
 */
class LaboratoryResultVisionPageRenderer
{
    /**
     * Renderiza texto redactado como PNG base64 para Vision API.
     *
     * Si GD no está disponible, devuelve null (el extractor usará fallback textual).
     */
    public function renderBase64Png(string $text, int $pageNumber): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $lines = $this->wrapLines($text, 80);
        $lineHeight = 18;
        $padding = 16;
        $width = 900;
        $height = max(120, ($lineCount = count($lines)) * $lineHeight + ($padding * 2));

        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            return null;
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 20, 20, 20);
        imagefill($image, 0, 0, $white);

        $header = 'Pagina '.$pageNumber.' (contenido redactado)';
        imagestring($image, 3, $padding, 8, $header, $black);

        $y = $padding + 20;

        foreach ($lines as $line) {
            imagestring($image, 4, $padding, $y, $line, $black);
            $y += $lineHeight;
        }

        ob_start();
        imagepng($image);
        $binary = ob_get_clean() ?: '';
        imagedestroy($image);

        if ($binary === '') {
            return null;
        }

        return base64_encode($binary);
    }

    /**
     * @return list<string>
     */
    private function wrapLines(string $text, int $maxChars): array
    {
        $wrapped = [];
        $sourceLines = preg_split('/\R/u', $text) ?: [];

        foreach ($sourceLines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            while (mb_strlen($line, 'UTF-8') > $maxChars) {
                $wrapped[] = mb_substr($line, 0, $maxChars, 'UTF-8');
                $line = mb_substr($line, $maxChars, null, 'UTF-8');
            }

            $wrapped[] = $line;
        }

        return $wrapped;
    }
}
