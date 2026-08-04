<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Lee filas de beneficiarios desde Excel/CSV para preview masiva B1.
 */
class CouponBeneficiariesRowsImport implements ToCollection, WithHeadingRow
{
    /** @var list<array{email: string, first_name: ?string, paternal_lastname: ?string, maternal_lastname: ?string}> */
    private array $rows = [];

    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            $email = $row->get('email') ?? $row->get('correo');
            if ($email === null || trim((string) $email) === '') {
                continue;
            }

            $this->rows[] = [
                'email' => trim((string) $email),
                'first_name' => $this->optionalString(
                    $row->get('nombre') ?? $row->get('name') ?? $row->get('first_name') ?? $row->get('nombres')
                ),
                'paternal_lastname' => $this->optionalString(
                    $row->get('apellido_paterno')
                        ?? $row->get('paternal_lastname')
                        ?? $row->get('apellido_paterno_')
                        ?? $row->get('paterno')
                ),
                'maternal_lastname' => $this->optionalString(
                    $row->get('apellido_materno')
                        ?? $row->get('maternal_lastname')
                        ?? $row->get('apellido_materno_')
                        ?? $row->get('materno')
                ),
            ];
        }
    }

    /**
     * @return list<array{email: string, first_name: ?string, paternal_lastname: ?string, maternal_lastname: ?string}>
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
