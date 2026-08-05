<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ActiveCampaignOperationsExport implements FromArray, WithHeadings, ShouldAutoSize
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     */
    public function __construct(
        protected array $headers,
        protected array $rows,
    ) {}

    public function headings(): array
    {
        return $this->headers;
    }

    public function array(): array
    {
        return $this->rows;
    }
}
