<?php

namespace App\Exports\Sheets;

use App\Models\OnlinePharmacyPurchaseItem;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class OnlinePharmacyPurchaseItemsSheet implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithTitle
{
    public function __construct(
        private array $filters = []
    ) {}

    public function query(): Builder
    {
        return OnlinePharmacyPurchaseItem::withTrashed()
            ->with([
                'onlinePharmacyPurchase' => function ($query) {
                    $query->withTrashed();
                },
                'onlinePharmacyPurchase.customer.user',
                'onlinePharmacyPurchase.transactions',
            ])
            ->join('online_pharmacy_purchases', 'online_pharmacy_purchase_items.online_pharmacy_purchase_id', '=', 'online_pharmacy_purchases.id')
            ->whereHas('onlinePharmacyPurchase', function ($query) {
                $query->filter($this->filters);
            })
            ->orderByDesc('online_pharmacy_purchases.created_at')
            ->select('online_pharmacy_purchase_items.*');
    }

    public function headings(): array
    {
        return [
            'Folio Vitau',
            'Código Vitau',
            'Nombre del Producto',
            'Cantidad',
            'Precio Unitario',
            'Precio Total',
        ];
    }

    public function map($item): array
    {
        return [
            $item->onlinePharmacyPurchase->vitau_order_id,
            $item->vitau_product_id,
            $item->name,
            $item->quantity,
            numberCents($item->price_cents),
            numberCents($item->price_cents * $item->quantity),
        ];
    }

    public function title(): string
    {
        return 'Detalle de Productos';
    }

    public function columnFormats(): array
    {
        return [
            'E' => NumberFormat::FORMAT_CURRENCY_USD, // Precio Unitario
            'F' => NumberFormat::FORMAT_CURRENCY_USD, // Precio Total
        ];
    }
}
