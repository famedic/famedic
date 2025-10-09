<?php

namespace App\Exports;

use App\Exports\Sheets\OnlinePharmacyPurchaseItemsSheet;
use App\Exports\Sheets\OnlinePharmacyPurchasesSheet;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class OnlinePharmacyPurchasesExport implements WithMultipleSheets
{
    public function __construct(
        public array $filters = []
    ) {}

    public function sheets(): array
    {
        return [
            'Pedidos' => new OnlinePharmacyPurchasesSheet($this->filters),
            'Detalle de Productos' => new OnlinePharmacyPurchaseItemsSheet($this->filters),
        ];
    }
}
