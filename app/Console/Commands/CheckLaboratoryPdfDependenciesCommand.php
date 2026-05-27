<?php

namespace App\Console\Commands;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;

class CheckLaboratoryPdfDependenciesCommand extends Command
{
    protected $signature = 'laboratory:check-pdf-deps';

    protected $description = 'Comprueba que DomPDF puede generar PDFs de órdenes de laboratorio (solo PHP, sin Chromium)';

    public function handle(): int
    {
        $this->info('Diagnóstico PDF (DomPDF)');

        try {
            $html = '<html><body><p>Prueba Famedic DomPDF</p></body></html>';
            $output = Pdf::loadHTML($html)->setPaper('a4')->output();

            if ($output === '' || ! str_starts_with($output, '%PDF')) {
                $this->error('DomPDF no generó un PDF válido.');

                return self::FAILURE;
            }

            $this->info('DomPDF OK — motor listo para comprobantes de laboratorio.');
            $this->line('Vista previa local: /debug/laboratory-purchase-pdf/{id}?format=pdf');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('DomPDF falló: '.$e->getMessage());
            $this->line('Ejecuta: composer install');

            return self::FAILURE;
        }
    }
}
