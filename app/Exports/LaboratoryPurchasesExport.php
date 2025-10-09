<?php

namespace App\Exports;

use App\Exports\Sheets\LaboratoryPurchaseItemsSheet;
use App\Exports\Sheets\LaboratoryPurchasesSheet;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class LaboratoryPurchasesExport implements WithMultipleSheets
{
    public function __construct(
        public array $filters = []
    ) {}

    public function sheets(): array
    {
        return [
            'Pedidos' => new LaboratoryPurchasesSheet($this->filters),
            'Detalle de Estudios' => new LaboratoryPurchaseItemsSheet($this->filters),
        ];
    }
}
