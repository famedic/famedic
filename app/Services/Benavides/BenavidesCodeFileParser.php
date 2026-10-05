<?php

namespace App\Services\Benavides;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\ToArray;

class BenavidesCodeFileParser
{
    private const HEADER_ALIASES = ['code', 'codigo', 'código', 'folio'];

    /**
     * @return list<string|null>
     */
    public function parseStoragePath(string $path): array
    {
        $absolutePath = Storage::disk('local')->path($path);
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv' => $this->parseCsv($absolutePath),
            'xlsx' => $this->parseSpreadsheet($absolutePath),
            default => throw new InvalidArgumentException('Formato no soportado. Usa CSV o XLSX.'),
        };
    }

    public function validateUpload(UploadedFile $file): void
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'xlsx'], true)) {
            throw new InvalidArgumentException('Formato no soportado. Usa CSV o XLSX.');
        }

        if ($file->getSize() === 0) {
            throw new InvalidArgumentException('El archivo está vacío.');
        }
    }

    /**
     * @return list<string|null>
     */
    private function parseCsv(string $absolutePath): array
    {
        $handle = fopen($absolutePath, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException('No se pudo leer el archivo CSV.');
        }

        $rows = [];
        $first = true;
        $codeIndex = 0;

        try {
            while (($columns = fgetcsv($handle)) !== false) {
                if ($columns === [null] || $columns === false) {
                    $rows[] = null;
                    continue;
                }

                if ($first) {
                    $first = false;
                    $headers = array_map(fn ($value) => $this->normalizeHeader($value), $columns);
                    $matchedIndex = collect($headers)->search(fn ($header) => in_array($header, self::HEADER_ALIASES, true));
                    if ($matchedIndex !== false) {
                        $codeIndex = (int) $matchedIndex;
                        continue;
                    }

                    $this->assertUnambiguousSingleColumn($columns);
                }

                $rows[] = array_key_exists($codeIndex, $columns)
                    ? $this->normalizeCell($columns[$codeIndex])
                    : null;
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * @return list<string|null>
     */
    private function parseSpreadsheet(string $absolutePath): array
    {
        $sheets = Excel::toArray(new class implements ToArray
        {
            public function array(array $array): array
            {
                return $array;
            }
        }, $absolutePath);
        $sheet = $sheets[0] ?? [];
        $rows = [];
        $first = true;
        $codeIndex = 0;

        foreach ($sheet as $columns) {
            $columns = array_values((array) $columns);

            if ($first) {
                $first = false;
                $headers = array_map(fn ($value) => $this->normalizeHeader($value), $columns);
                $matchedIndex = collect($headers)->search(fn ($header) => in_array($header, self::HEADER_ALIASES, true));
                if ($matchedIndex !== false) {
                    $codeIndex = (int) $matchedIndex;
                    continue;
                }

                $this->assertUnambiguousSingleColumn($columns);
            }

            $rows[] = array_key_exists($codeIndex, $columns)
                ? $this->normalizeCell($columns[$codeIndex])
                : null;
        }

        return $rows;
    }

    private function normalizeCell(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $code = trim((string) $value);

        return $code === '' ? null : $code;
    }

    private function normalizeHeader(mixed $value): string
    {
        return mb_strtolower(trim((string) $value, " \t\n\r\0\x0B\xEF\xBB\xBF"));
    }

    private function assertUnambiguousSingleColumn(array $columns): void
    {
        $nonEmptyColumns = array_filter(
            $columns,
            fn ($value) => $this->normalizeCell($value) !== null,
        );

        if (count($nonEmptyColumns) > 1) {
            throw new InvalidArgumentException('No se encontró una columna code, codigo, código o folio. Para archivos con varias columnas, agrega un encabezado reconocido.');
        }
    }
}
