<?php

namespace App\Exports\Sheets;

use App\Models\LaboratoryPurchaseItem;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class LaboratoryPurchaseItemsSheet implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithTitle
{
    public function __construct(
        private array $filters = []
    ) {}

    public function query(): Builder
    {
        return LaboratoryPurchaseItem::withTrashed()
            ->with([
                'laboratoryPurchase' => function ($query) {
                    $query->withTrashed();
                },
                'laboratoryPurchase.customer.user',
                'laboratoryPurchase.transactions',
            ])
            ->join('laboratory_purchases', 'laboratory_purchase_items.laboratory_purchase_id', '=', 'laboratory_purchases.id')
            ->whereHas('laboratoryPurchase', function ($query) {
                $query->filter($this->filters);
            })
            ->orderByDesc('laboratory_purchases.created_at')
            ->select('laboratory_purchase_items.*');
    }

    public function headings(): array
    {
        return [
            'Folio GDA',
            'Código GDA',
            'Nombre del estudio',
            'Precio',
            'Indicaciones',
        ];
    }

    public function map($item): array
    {
        return [
            $item->laboratoryPurchase->gda_order_id,
            $item->gda_id,
            $item->name,
            numberCents($item->price_cents),
            $item->indications,
        ];
    }

    public function title(): string
    {
        return 'Detalle de Estudios';
    }

    public function columnFormats(): array
    {
        return [
            'D' => NumberFormat::FORMAT_CURRENCY_USD,
        ];
    }
}
