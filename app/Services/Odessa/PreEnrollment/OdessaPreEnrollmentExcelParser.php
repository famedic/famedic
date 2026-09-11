<?php

namespace App\Services\Odessa\PreEnrollment;

use App\Models\OdessaPreEnrollment;
use App\Services\Odessa\Reconciliation\OdessaReconciliationNormalizer;
use PhpOffice\PhpSpreadsheet\IOFactory;

class OdessaPreEnrollmentExcelParser
{
    /** @return list<OdessaPreEnrollmentSourceRow> */
    public function parse(string $path): array
    {
        $this->assertFileSizeIsAllowed($path);

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }

        $worksheetInfo = $reader->listWorksheetInfo($path);
        $expectedSheetInfo = $this->assertWorkbookStructureIsAllowed($worksheetInfo);
        $reader->setLoadSheetsOnly([$expectedSheetInfo['worksheetName']]);

        $spreadsheet = $reader->load($path);
        $worksheet = $spreadsheet->getSheetByName($this->expectedSheet());
        if (! $worksheet) {
            throw new \InvalidArgumentException('El archivo no contiene la hoja esperada para preafiliación ODESSA.');
        }

        $rows = [];

        $matrix = $worksheet->toArray(null, false, true, false);
        $headerIndex = $this->detectHeaderIndex($matrix);
        if ($headerIndex === null) {
            throw new \InvalidArgumentException('La hoja no contiene encabezados reconocibles para preafiliación ODESSA.');
        }

        $headers = array_map([OdessaReconciliationNormalizer::class, 'header'], $matrix[$headerIndex] ?? []);
        for ($i = $headerIndex + 1; $i < count($matrix); $i++) {
            $raw = $this->rowByHeader($headers, $matrix[$i] ?? []);
            if ($this->isEmptyRow($raw)) {
                continue;
            }

            $row = $this->mapRow($worksheet->getTitle(), $i + 1, $raw);
            if ($this->hasSignal($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @param list<array<int, mixed>> $matrix */
    private function detectHeaderIndex(array $matrix): ?int
    {
        foreach ($matrix as $index => $row) {
            $headers = array_map([OdessaReconciliationNormalizer::class, 'header'], $row);
            $score = 0;
            foreach (['de_empresa', 'empleado', 'apellido_paterno', 'apellido_materno', 'nombre', 'fecha_de_nacimiento', 'correos_electronicos', 'id_odessa', 'accion', 'estatus_revision'] as $needle) {
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

    /** @param array<int, string> $headers @param array<int, mixed> $row */
    private function rowByHeader(array $headers, array $row): array
    {
        $mapped = [];
        foreach ($headers as $index => $header) {
            if ($header !== '') {
                $mapped[$header] = $row[$index] ?? null;
            }
        }

        return $mapped;
    }

    private function mapRow(string $sheet, int $rowNumber, array $row): OdessaPreEnrollmentSourceRow
    {
        return new OdessaPreEnrollmentSourceRow(
            sourceSheet: $sheet,
            sourceRow: $rowNumber,
            companyExternalIdentifier: OdessaReconciliationNormalizer::identifier($this->first($row, ['de_empresa', 'empresa', 'no_de_empresa', 'numero_de_empresa'])),
            employeeIdentifier: OdessaReconciliationNormalizer::identifier($this->first($row, ['empleado', 'no_empleado', 'numero_empleado', 'de_empleado', 'socio'])),
            firstName: OdessaReconciliationNormalizer::text($this->first($row, ['nombre', 'nombres'])),
            paternalLastName: OdessaReconciliationNormalizer::text($this->first($row, ['apellido_paterno', 'paterno'])),
            maternalLastName: OdessaReconciliationNormalizer::text($this->first($row, ['apellido_materno', 'materno'])),
            birthDate: OdessaReconciliationNormalizer::date($this->first($row, ['fecha_de_nacimiento', 'fecha_nacimiento', 'nacimiento'])),
            sourceEmail: OdessaReconciliationNormalizer::email($this->first($row, ['correos_electronicos', 'correo_electronico', 'email', 'correo'])),
            odessaIdentifier: OdessaReconciliationNormalizer::identifier($this->first($row, ['id_odessa', 'id_odessa_', 'odessa_id'])),
            sourceAction: $this->sourceAction($row),
            raw: $row,
        );
    }

    private function sourceAction(array $row): string
    {
        $value = OdessaReconciliationNormalizer::header($this->first($row, ['accion', 'action', 'source_action', 'estatus', 'estado']));

        return match ($value) {
            'alta' => OdessaPreEnrollment::ACTION_ALTA,
            'historico', 'histórico' => OdessaPreEnrollment::ACTION_HISTORICO,
            'baja' => OdessaPreEnrollment::ACTION_BAJA,
            default => OdessaPreEnrollment::ACTION_NONE,
        };
    }

    /** @param list<string> $keys */
    private function first(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }

        return null;
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (OdessaReconciliationNormalizer::text($value) !== null) {
                return false;
            }
        }

        return true;
    }

    private function hasSignal(OdessaPreEnrollmentSourceRow $row): bool
    {
        return (bool) array_filter([
            $row->companyExternalIdentifier,
            $row->employeeIdentifier,
            $row->firstName,
            $row->paternalLastName,
            $row->sourceEmail,
            $row->odessaIdentifier,
        ]);
    }

    private function expectedSheet(): string
    {
        return (string) config('famedic.odessa_pre_enrollments.import_expected_sheet', 'Sin Registro');
    }

    private function assertFileSizeIsAllowed(string $path): void
    {
        $size = filesize($path);
        if ($size !== false && $size > $this->maxFileBytes()) {
            throw new \InvalidArgumentException('El archivo excede el tamaño máximo permitido.');
        }
    }

    private function assertWorkbookStructureIsAllowed(array $worksheetInfo): array
    {
        if (count($worksheetInfo) > $this->maxSheets()) {
            throw new \InvalidArgumentException('El archivo excede el número máximo de hojas permitido.');
        }

        $expectedSheet = $this->expectedSheet();
        foreach ($worksheetInfo as $sheetInfo) {
            if (($sheetInfo['worksheetName'] ?? null) !== $expectedSheet) {
                continue;
            }

            if (($sheetInfo['totalRows'] ?? 0) > $this->maxRows()) {
                throw new \InvalidArgumentException('La hoja excede el número máximo de filas permitido.');
            }
            if (($sheetInfo['totalColumns'] ?? 0) > $this->maxColumns()) {
                throw new \InvalidArgumentException('La hoja excede el número máximo de columnas permitido.');
            }

            return $sheetInfo;
        }

        throw new \InvalidArgumentException('El archivo no contiene la hoja esperada para preafiliación ODESSA.');
    }

    private function maxSheets(): int
    {
        return max(1, (int) config('famedic.odessa_pre_enrollments.import_max_sheets', 5));
    }

    private function maxRows(): int
    {
        return max(1, (int) config('famedic.odessa_pre_enrollments.import_max_rows', 1000));
    }

    private function maxColumns(): int
    {
        return max(1, (int) config('famedic.odessa_pre_enrollments.import_max_columns', 30));
    }

    private function maxFileBytes(): int
    {
        return max(1, (int) config('famedic.odessa_pre_enrollments.import_max_file_kb', 20480)) * 1024;
    }
}
