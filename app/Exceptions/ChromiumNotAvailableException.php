<?php

namespace App\Exceptions;

use RuntimeException;

class ChromiumNotAvailableException extends RuntimeException
{
    public static function forPdfGeneration(?string $searched = null): self
    {
        $hint = 'En el servidor (Forge/Ubuntu) instala Chromium del sistema: '
            .'sudo apt-get update && sudo apt-get install -y chromium-browser '
            .'libatk1.0-0 libatk-bridge2.0-0 libcups2 libdrm2 libgbm1 libnss3 libxcomposite1 libxdamage1 libxrandr2 '
            .'&& define BROWSERSHOT_CHROME_PATH=/usr/bin/chromium-browser en el .env del sitio.';

        $message = 'No se encontró un ejecutable de Chromium para generar el PDF.';
        if ($searched) {
            $message .= ' Ruta configurada: '.$searched.'.';
        }
        $message .= ' '.$hint;

        return new self($message);
    }
}
