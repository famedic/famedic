<?php

namespace App\Services\LaboratoryStores\Gda;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class GdaExcelReader
{
    public function __construct(private readonly GdaStringNormalizer $normalizer) {}

    public function read(string $path): GdaParsedWorkbook
    {
        $workbook = IOFactory::load($path);

        $stores = [];
        $clinical = [];
        $optical = [];

        foreach ($workbook->getAllSheets() as $sheet) {
            $title = mb_strtoupper(trim($sheet->getTitle()));

            if ($title === 'DIRECTORIO') {
                $stores = array_merge($stores, $this->readDirectory($sheet));
            } elseif ($title === 'HISTORIA CLINICO') {
                $clinical = array_merge($clinical, $this->readServices($sheet, 'clinical_history'));
            } elseif ($title === 'OPTICAS') {
                $optical = array_merge($optical, $this->readServices($sheet, 'optical'));
            }
        }

        return new GdaParsedWorkbook($stores, $clinical, $optical);
    }

    private function readDirectory(Worksheet $sheet): array
    {
        [$headerRow, $headers] = $this->headers($sheet, ['MARCA', 'SUCURSAL']);
        $rows = [];

        for ($row = $headerRow + 1; $row <= $sheet->getHighestDataRow(); $row++) {
            $payload = $this->payload($sheet, $headers, $row);

            if ($this->isBlank($payload)) {
                continue;
            }

            $rows[] = new GdaStoreRow(
                $sheet->getTitle(),
                $row,
                $this->cell($payload, ['MARCA']),
                $this->cell($payload, ['SUCURSAL', 'Sucursal GDA']),
                $this->cell($payload, ['ESTADO']),
                $this->cell($payload, ['CALLE', 'DOMICILIO', 'DIRECCION', 'DIRECCIÓN']),
                $this->cell($payload, ['NO. EXT', 'NUMERO EXT', 'NÚMERO EXT', 'EXTERIOR']),
                $this->cell($payload, ['NO. INT', 'NUMERO INT', 'NÚMERO INT', 'INTERIOR']),
                $this->cell($payload, ['COLONIA']),
                $this->cell($payload, ['ALCALDIA/MUNICIPIO', 'MUNICIPIO', 'ALCALDIA']),
                $this->cell($payload, ['CIUDAD']),
                $this->cell($payload, ['CP', 'C.P.', 'CODIGO POSTAL', 'CÓDIGO POSTAL']),
                $this->cell($payload, ['TELEFONO', 'TELÉFONO', 'TELEFONOS', 'TELÉFONOS']),
                $this->cell($payload, ['LATITUD', 'LAT']),
                $this->cell($payload, ['LONGITUD', 'LON', 'LNG']),
                $this->cell($payload, ['HORARIOS', 'HORARIO']),
                $payload,
            );
        }

        return $rows;
    }

    private function readServices(Worksheet $sheet, string $serviceType): array
    {
        [$headerRow, $headers] = $this->headers($sheet, ['SUCURSAL']);
        $rows = [];
        $lastBrand = null;

        for ($row = $headerRow + 1; $row <= $sheet->getHighestDataRow(); $row++) {
            $payload = $this->payload($sheet, $headers, $row);

            if ($this->isBlank($payload)) {
                continue;
            }

            $brand = $this->cell($payload, ['MARCA']) ?: $lastBrand;
            $lastBrand = $brand ?: $lastBrand;

            $rows[] = new GdaSpecialServiceRow(
                $sheet->getTitle(),
                $row,
                $serviceType,
                $brand,
                $this->cell($payload, ['SUCURSAL', 'Sucursal GDA']),
                $this->cell($payload, ['MEDICO', 'MÉDICO', 'NOMBRE']),
                $this->cell($payload, ['HORARIOS', 'HORARIO']),
                $this->cell($payload, ['TELEFONO', 'TELÉFONO']),
                $this->cell($payload, ['DIRECCION', 'DIRECCIÓN', 'DOMICILIO']),
                $payload,
            );
        }

        return $rows;
    }

    private function headers(Worksheet $sheet, array $required): array
    {
        for ($row = 1; $row <= min(10, $sheet->getHighestDataRow()); $row++) {
            $headers = $this->payloadHeaders($sheet, $row);
            $normalizedHeaders = array_map(fn ($header) => mb_strtoupper($this->normalizer->normalize($header)), $headers);

            $found = array_filter($required, fn ($requiredHeader) => collect($normalizedHeaders)
                ->contains(fn ($header) => $header === $requiredHeader || str_starts_with($header, $requiredHeader.' ')));

            if (count($found) === count($required)) {
                return [$row, $headers];
            }
        }

        throw new \RuntimeException("Could not find headers in sheet {$sheet->getTitle()}");
    }

    private function payloadHeaders(Worksheet $sheet, int $row): array
    {
        $headers = [];

        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($column = 1; $column <= $highestColumn; $column++) {
            $headers[$column] = trim((string) $sheet->getCell(Coordinate::stringFromColumnIndex($column).$row)->getCalculatedValue());
        }

        return $headers;
    }

    private function payload(Worksheet $sheet, array $headers, int $row): array
    {
        $payload = [];

        foreach ($headers as $column => $header) {
            if ($header === '') {
                continue;
            }

            $payload[$header] = $sheet->getCell(Coordinate::stringFromColumnIndex($column).$row)->getCalculatedValue();
        }

        return $payload;
    }

    private function cell(array $payload, array $names): mixed
    {
        $lookup = [];

        foreach ($payload as $key => $value) {
            $lookup[mb_strtoupper($this->normalizer->normalize((string) $key))] = $value;
        }

        foreach ($names as $name) {
            $value = $lookup[mb_strtoupper($this->normalizer->normalize($name))] ?? null;

            if (trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function isBlank(array $payload): bool
    {
        foreach ($payload as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
