<?php

namespace App\Services\Odessa\Reconciliation;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class OdessaCollaboratorExcelParser
{
    public const ACTION_ALTA = 'ALTA';

    public const ACTION_BAJA = 'BAJA';

    public const ACTION_NONE = 'NONE';

    public const ACTION_UNKNOWN = 'UNKNOWN';

    /** @return list<OdessaCollaboratorSourceRow> */
    public function parse(string $path, bool $includeFormatting = true): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(! $includeFormatting);
        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }
        if (method_exists($reader, 'setReadFilter')) {
            $reader->setReadFilter(new OdessaCollaboratorReadFilter);
        }
        $spreadsheet = $reader->load($path);
        $rows = [];

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $sheetName = $worksheet->getTitle();
            $matrix = $worksheet->toArray(null, true, true, false);
            $headerIndex = $this->detectHeaderIndex($matrix);

            if ($headerIndex === null) {
                continue;
            }

            $headers = $this->headers($matrix[$headerIndex] ?? []);

            for ($i = $headerIndex + 1; $i < count($matrix); $i++) {
                $raw = $this->rowByHeader($headers, $matrix[$i] ?? []);
                if ($this->isEmptyRow($raw)) {
                    continue;
                }

                $sourceRowNumber = $i + 1;
                $sourceActionColor = $includeFormatting
                    ? $this->sourceActionColor($worksheet, $sourceRowNumber, $headers)
                    : null;
                $sourceAction = $includeFormatting
                    ? $this->sourceActionFromColor($sourceActionColor)
                    : self::ACTION_NONE;

                $row = $this->mapRow($sheetName, $sourceRowNumber, $raw, $sourceAction, $sourceActionColor);
                if ($this->hasAnyCollaboratorSignal($row)) {
                    $rows[] = $row;
                }
            }
        }

        $this->markDuplicates($rows);

        return $rows;
    }

    /** @param list<array<int, mixed>> $matrix */
    private function detectHeaderIndex(array $matrix): ?int
    {
        foreach ($matrix as $index => $row) {
            $headers = array_map([OdessaReconciliationNormalizer::class, 'header'], $row);
            $score = 0;

            foreach (['de_empresa', 'empleado', 'apellido_paterno', 'apellido_materno', 'nombre', 'fecha_de_nacimiento', 'correos_electronicos', 'id_odessa'] as $needle) {
                if (in_array($needle, $headers, true)) {
                    $score++;
                }
            }

            if ($score >= 3) {
                return $index;
            }
        }

        return null;
    }

    /** @param array<int, mixed> $row @return array<int, string> */
    private function headers(array $row): array
    {
        return array_map([OdessaReconciliationNormalizer::class, 'header'], $row);
    }

    /** @param array<int, string> $headers @param array<int, mixed> $row @return array<string, mixed> */
    private function rowByHeader(array $headers, array $row): array
    {
        $mapped = [];
        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $mapped[$header] = $row[$index] ?? null;
        }

        return $mapped;
    }

    /** @param array<string, mixed> $row */
    private function mapRow(
        string $sheetName,
        int $sourceRow,
        array $row,
        string $sourceAction,
        ?string $sourceActionColor,
    ): OdessaCollaboratorSourceRow
    {
        return new OdessaCollaboratorSourceRow(
            sourceSheet: $sheetName,
            sourceRow: $sourceRow,
            companyExternalId: OdessaReconciliationNormalizer::identifier($this->first($row, ['de_empresa', 'empresa', 'no_de_empresa', 'numero_de_empresa'])),
            employeeNumber: OdessaReconciliationNormalizer::identifier($this->first($row, ['empleado', 'no_empleado', 'numero_empleado', 'de_empleado', 'socio'])),
            firstName: OdessaReconciliationNormalizer::text($this->first($row, ['nombre', 'nombres'])),
            paternalLastname: OdessaReconciliationNormalizer::text($this->first($row, ['apellido_paterno', 'paterno'])),
            maternalLastname: OdessaReconciliationNormalizer::text($this->first($row, ['apellido_materno', 'materno'])),
            birthDate: OdessaReconciliationNormalizer::date($this->first($row, ['fecha_de_nacimiento', 'fecha_nacimiento', 'nacimiento'])),
            email: OdessaReconciliationNormalizer::email($this->first($row, ['correos_electronicos', 'correo_electronico', 'email', 'correo'])),
            odessaId: OdessaReconciliationNormalizer::identifier($this->first($row, ['id_odessa', 'id_odessa_', 'odessa_id'])),
            membershipIdentifier: OdessaReconciliationNormalizer::identifier($this->first($row, ['no_credito', 'no_membresia_nocredito', 'no_membresia_no_credito', 'credito', 'membresia', 'numero_de_membresia'])),
            sourceAction: $sourceAction,
            sourceActionColor: $sourceActionColor,
            raw: $row,
        );
    }

    /** @param array<int, string> $headers */
    private function sourceActionColor(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet, int $row, array $headers): ?string
    {
        $colors = [];
        $highestColumn = count($headers);

        for ($column = 1; $column <= $highestColumn; $column++) {
            if (($headers[$column - 1] ?? '') === '') {
                continue;
            }

            $fill = $worksheet->getStyleByColumnAndRow($column, $row)->getFill();
            if ($fill->getFillType() === Fill::FILL_NONE) {
                continue;
            }

            $argb = strtoupper((string) $fill->getStartColor()->getARGB());
            if (in_array($argb, ['00000000', 'FFFFFFFF'], true)) {
                continue;
            }

            $rgb = substr($argb, -6);
            $colors[$rgb] = ($colors[$rgb] ?? 0) + 1;
        }

        if ($colors === []) {
            return null;
        }

        arsort($colors);

        return array_key_first($colors);
    }

    private function sourceActionFromColor(?string $rgb): string
    {
        if ($rgb === null) {
            return self::ACTION_NONE;
        }

        if (in_array($rgb, ['00B050', 'E2F0D9'], true)) {
            return self::ACTION_ALTA;
        }

        if ($rgb === 'FFC000') {
            return self::ACTION_BAJA;
        }

        [$red, $green, $blue] = [
            hexdec(substr($rgb, 0, 2)),
            hexdec(substr($rgb, 2, 2)),
            hexdec(substr($rgb, 4, 2)),
        ];

        if ($green >= 145 && $red <= 160 && $blue <= 170) {
            return self::ACTION_ALTA;
        }

        if ($red >= 180 && $green >= 140 && $blue <= 140) {
            return self::ACTION_BAJA;
        }

        return self::ACTION_UNKNOWN;
    }

    /** @param array<string, mixed> $row @param list<string> $keys */
    private function first(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (OdessaReconciliationNormalizer::text($value) !== null) {
                return false;
            }
        }

        return true;
    }

    private function hasAnyCollaboratorSignal(OdessaCollaboratorSourceRow $row): bool
    {
        return (bool) array_filter([
            $row->companyExternalId,
            $row->employeeNumber,
            $row->firstName,
            $row->paternalLastname,
            $row->maternalLastname,
            $row->email,
            $row->odessaId,
        ]);
    }

    /** @param list<OdessaCollaboratorSourceRow> $rows */
    private function markDuplicates(array $rows): void
    {
        $groups = [];

        foreach ($rows as $row) {
            [$reason, $key] = $this->dedupeKey($row);
            if ($key === null) {
                $reason = 'DUPLICATE_POSSIBLE_IDENTITY';
                $key = 'row:'.$row->sourceSheet.':'.$row->sourceRow;
            }

            $groups[$reason.':'.$key][] = $row;
        }

        $sequence = 1;
        foreach ($groups as $groupKey => $duplicates) {
            [$reason, $key] = explode(':', $groupKey, 2);
            $canonical = $duplicates[0];
            $groupId = 'G'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
            $sequence++;

            foreach ($duplicates as $duplicate) {
                $duplicate->duplicateGroupId = $groupId;
                $duplicate->canonicalRow = $canonical->sourceRow;
                $duplicate->canonicalId = $groupId.'-'.$canonical->sourceSheet.'-'.$canonical->sourceRow;
                $duplicate->duplicateCount = count($duplicates);

                if (count($duplicates) <= 1) {
                    continue;
                }

                $duplicate->isDuplicate = $duplicate !== $canonical;
                $duplicate->duplicateReason = $reason === 'DUPLICATE_POSSIBLE_IDENTITY' && $this->exactDuplicate($duplicate, $canonical)
                    ? 'DUPLICATE_EXACT'
                    : $reason;
                $duplicate->duplicateNotes[] = "Duplicado en Excel por {$duplicate->duplicateReason}: {$key}";
            }
        }
    }

    /** @return array{0: string, 1: ?string} */
    private function dedupeKey(OdessaCollaboratorSourceRow $row): array
    {
        if ($row->odessaId) {
            return ['DUPLICATE_ODESSA_ID', $row->odessaId];
        }

        if ($row->companyExternalId && $row->employeeNumber) {
            return ['DUPLICATE_COMPANY_PARTNER', $row->companyExternalId.'|'.$row->employeeNumber];
        }

        if ($row->email) {
            return ['DUPLICATE_EMAIL', $row->email];
        }

        if ($row->identityKey()) {
            return ['DUPLICATE_POSSIBLE_IDENTITY', $row->identityKey()];
        }

        return ['DUPLICATE_POSSIBLE_IDENTITY', $row->looseIdentityKey()];
    }

    private function exactDuplicate(OdessaCollaboratorSourceRow $left, OdessaCollaboratorSourceRow $right): bool
    {
        return $left->companyExternalId === $right->companyExternalId
            && $left->employeeNumber === $right->employeeNumber
            && $left->odessaId === $right->odessaId
            && $left->email === $right->email
            && $left->identityKey() === $right->identityKey();
    }
}

class OdessaCollaboratorReadFilter implements IReadFilter
{
    public function readCell($columnAddress, $row, $worksheetName = '')
    {
        if ($row > 20000) {
            return false;
        }

        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress) <= 50;
    }
}
