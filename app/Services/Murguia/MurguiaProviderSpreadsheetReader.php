<?php

namespace App\Services\Murguia;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

class MurguiaProviderSpreadsheetReader
{
    public int $detectedHeaderRow = 1;

    private const RECOGNIZED_HEADER_KEYS = [
        'medical_attention_identifier',
        'full_name',
        'email',
        'provider_expires_at',
        'provider_membership_type',
        'provider_status',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function read(UploadedFile $file): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray();

        if ($rows === [] || $rows === [[]]) {
            return [];
        }

        $headerIndex = $this->findHeaderRowIndex($rows);
        $this->detectedHeaderRow = $headerIndex + 1;
        $headers = $this->normalizeHeaderRow($rows[$headerIndex]);
        $parsed = [];

        foreach ($rows as $index => $row) {
            if ($index <= $headerIndex) {
                continue;
            }

            if ($this->rowIsEmpty($row)) {
                continue;
            }

            $assoc = ['_row_number' => $index + 1];
            foreach ($headers as $colIndex => $key) {
                if ($key === '') {
                    continue;
                }
                $assoc[$key] = $row[$colIndex] ?? null;
            }

            $normalized = $this->normalizeProviderRow($assoc);

            if (! filled($normalized['medical_attention_identifier']) && ! filled($normalized['email'])) {
                continue;
            }

            $parsed[] = $normalized;
        }

        return $parsed;
    }

    /**
     * Busca la fila de encabezados reales (archivos Odessa/Murguía suelen traer metadata arriba).
     */
    private function findHeaderRowIndex(array $rows): int
    {
        $bestIndex = 0;
        $bestScore = 0;

        foreach (array_slice($rows, 0, 60, true) as $index => $row) {
            $headers = $this->normalizeHeaderRow($row);
            $score = count(array_filter(
                $headers,
                fn (string $key) => in_array($key, self::RECOGNIZED_HEADER_KEYS, true)
            ));

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
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

    private function mapHeaderToKey(null|string|int|float $cell): string
    {
        if ($cell === null || $cell === '') {
            return '';
        }

        $h = mb_strtolower(trim($this->cellToString($cell)));

        return match ($h) {
            'email', 'correo', 'correo electronico', 'correo electrónico', 'e-mail' => 'email',
            'medical_attention_identifier', 'nocredito', 'no_credito', 'no credito', 'no. credito',
            'identificador', 'id_medico', 'numero_credito', 'número crédito', 'credito', 'n° credito' => 'medical_attention_identifier',
            'nombre', 'name', 'nombre_completo', 'nombre completo', 'asegurado', 'titular' => 'full_name',
            'estatus', 'status', 'estado', 'situacion', 'situación' => 'provider_status',
            'tipo', 'tipo_membresia', 'tipo membresía', 'tipo_membresía', 'membresia', 'membresía',
            'plan', 'tipo_plan', 'producto', 'campaña', 'campana' => 'provider_membership_type',
            'subproducto' => 'provider_sub_product',
            'vigencia', 'fecha_vencimiento', 'fecha vencimiento', 'fecha_expiracion', 'fecha expiración',
            'expiracion', 'expiración', 'fin_vigencia', 'finvigencia', 'fin vigencia' => 'provider_expires_at',
            'iniciovigencia', 'inicio_vigencia', 'inicio vigencia' => 'provider_starts_at',
            'telefono', 'teléfono', 'phone', 'celular' => 'phone',
            default => $h,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeProviderRow(array $row): array
    {
        $credito = isset($row['medical_attention_identifier'])
            ? $this->normalizeCredito($row['medical_attention_identifier'])
            : null;

        $email = isset($row['email'])
            ? mb_strtolower(trim($this->cellToString($row['email'])))
            : null;
        if ($email === '') {
            $email = null;
        }

        $membershipType = null;
        if (isset($row['provider_membership_type'])) {
            $membershipType = trim($this->cellToString($row['provider_membership_type']));
        }

        return [
            'row_number' => (int) ($row['_row_number'] ?? 0),
            'medical_attention_identifier' => filled($credito) ? $credito : null,
            'email' => $email,
            'full_name' => isset($row['full_name'])
                ? trim($this->cellToString($row['full_name']))
                : null,
            'provider_status' => isset($row['provider_status'])
                ? trim($this->cellToString($row['provider_status']))
                : null,
            'provider_membership_type' => filled($membershipType) ? $membershipType : null,
            'provider_expires_at' => isset($row['provider_expires_at'])
                ? trim($this->cellToString($row['provider_expires_at']))
                : null,
            'phone' => isset($row['phone'])
                ? trim($this->cellToString($row['phone']))
                : null,
        ];
    }

    public function normalizeCredito(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return number_format($value, 0, '', '');
        }

        $value = trim($this->cellToString($value));

        if (is_numeric($value)) {
            return number_format((float) $value, 0, '', '');
        }

        return preg_replace('/\s+/', '', $value) ?? $value;
    }

    private function cellToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return number_format($value, 0, '', '');
        }

        return trim((string) $value);
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim($this->cellToString($cell)) !== '') {
                return false;
            }
        }

        return true;
    }
}
