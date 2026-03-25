<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ProcessMurguiaExcelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        public string $disk,
        public string $relativePath
    ) {
        $this->onQueue(config('services.murguia.queue', 'default'));
    }

    public function handle(): void
    {
        $fullPath = Storage::disk($this->disk)->path($this->relativePath);

        if (! is_file($fullPath)) {
            Log::error('ProcessMurguiaExcelJob: archivo no existe', ['path' => $fullPath]);

            return;
        }

        if (! is_readable($fullPath)) {
            Log::error('ProcessMurguiaExcelJob: archivo no legible', ['path' => $fullPath]);

            return;
        }

        $spreadsheet = IOFactory::load($fullPath);
        $rows = $spreadsheet->getActiveSheet()->toArray();

        if ($rows === [] || $rows === [[]]) {
            Log::warning('ProcessMurguiaExcelJob: Excel vacío');

            return;
        }

        $headerRow = array_shift($rows);
        $headers = $this->normalizeHeaderRow($headerRow);

        foreach ($rows as $index => $row) {
            if ($this->rowIsEmpty($row)) {
                continue;
            }

            $assoc = [];
            foreach ($headers as $colIndex => $key) {
                if ($key === '') {
                    continue;
                }
                $assoc[$key] = $row[$colIndex] ?? null;
            }

            $rowNumber = $index + 2;

            ProcessMurguiaRowJob::dispatch($assoc, $rowNumber);
        }
    }

    /**
     * @param  list<string|null>  $headerRow
     * @return array<int, string>
     */
    private function normalizeHeaderRow(array $headerRow): array
    {
        $out = [];

        foreach ($headerRow as $cell) {
            $out[] = $this->mapHeaderToKey($cell);
        }

        return $out;
    }

    private function mapHeaderToKey(null|string $cell): string
    {
        if ($cell === null) {
            return '';
        }

        $h = mb_strtolower(trim((string) $cell));

        return match ($h) {
            'email', 'correo', 'correo electronico', 'correo electrónico' => 'email',
            'medical_attention_identifier', 'nocredito', 'no_credito', 'no credito', 'identificador', 'id_medico' => 'medical_attention_identifier',
            'accion', 'acción', 'action' => 'accion',
            default => $h,
        };
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
