<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Lee correos desde Excel/CSV para asignación masiva a un cupón maestro (solo columna email/correo).
 */
class CouponCampaignEmailsImport implements ToCollection, WithHeadingRow
{
    /** @var list<string> */
    private array $emails = [];

    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            $email = $row->get('email') ?? $row->get('correo');
            if ($email === null || $email === '') {
                continue;
            }
            $normalized = strtolower(trim((string) $email));
            if ($normalized !== '') {
                $this->emails[] = $normalized;
            }
        }
    }

    /**
     * @return list<string>
     */
    public function getUniqueEmails(): array
    {
        return array_values(array_unique($this->emails));
    }
}
