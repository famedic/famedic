<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LogsGeneralController extends Controller
{
    /**
     * Ruta por defecto del archivo de log de Laravel.
     */
    protected function getLogPath(): string
    {
        return config('logging.channels.single.path')
            ?? storage_path('logs/laravel.log');
    }

    /**
     * Obtiene las últimas N líneas del archivo de log.
     */
    protected function readLastLines(string $path, int $lines = 500): array
    {
        if (!File::exists($path)) {
            return [];
        }

        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key() + 1;
        $file = null;

        $content = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        $startLine = max(1, $totalLines - $lines);
        $currentLine = 0;

        while (($line = fgets($handle)) !== false) {
            $currentLine++;
            if ($currentLine >= $startLine) {
                $content[] = [
                    'number' => $currentLine,
                    'text' => rtrim($line),
                ];
            }
        }

        fclose($handle);

        return $content;
    }

    /**
     * Muestra la página de gestión de logs generales.
     */
    public function index(Request $request)
    {
        $request->user()->administrator->hasPermissionTo('logs-general.manage') || abort(403);

        $path = $this->getLogPath();
        $lines = (int) $request->get('lines', 500);
        $lines = min(max(100, $lines), 5000);

        $logLines = $this->readLastLines($path, $lines);
        $exists = File::exists($path);
        $size = $exists ? File::size($path) : 0;

        return Inertia::render('Admin/LogsGeneral', [
            'logLines' => $logLines,
            'logExists' => $exists,
            'logSize' => $size,
            'logPath' => $path,
            'linesRequested' => $lines,
        ]);
    }

    /**
     * Descarga el archivo de log completo.
     */
    public function download(): StreamedResponse
    {
        request()->user()->administrator->hasPermissionTo('logs-general.manage') || abort(403);

        $path = $this->getLogPath();

        if (!File::exists($path)) {
            abort(404, 'No existe el archivo de log.');
        }

        return response()->streamDownload(
            function () use ($path) {
                $handle = fopen($path, 'r');
                if ($handle) {
                    while (($line = fgets($handle)) !== false) {
                        echo $line;
                    }
                    fclose($handle);
                }
            },
            'laravel-' . now()->format('Y-m-d') . '.log',
            [
                'Content-Type' => 'text/plain',
            ]
        );
    }
}
