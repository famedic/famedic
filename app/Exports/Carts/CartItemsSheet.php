<?php

namespace App\Exports\Carts;

use App\Enums\MonitoringCartType;
use App\Exports\Carts\Concerns\BuildsCartExportQuery;
use App\Exports\Carts\Concerns\FormatsCartExportSheet;
use App\Models\Cart;
use Generator;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class CartItemsSheet implements FromGenerator, ShouldAutoSize, WithColumnFormatting, WithEvents, WithHeadings, WithStyles, WithTitle
{
    use BuildsCartExportQuery;
    use FormatsCartExportSheet;

    private const CHUNK_SIZE = 50;

    public function __construct(
        private array $filters = []
    ) {}

    public function generator(): Generator
    {
        $page = 1;

        do {
            $carts = (clone $this->baseCartQuery())->forPage($page, self::CHUNK_SIZE)->get();

            foreach ($carts as $cart) {
                foreach ($cart->items as $item) {
                    yield [
                        $cart->id,
                        $cart->user?->full_name,
                        $cart->user?->email,
                        $this->brandLabel($cart),
                        $item->product_id,
                        $item->name,
                        (float) $item->price,
                        (int) $item->quantity,
                        $cart->displayStatusLabel(),
                        $cart->updated_at ? Date::dateTimeToExcel(localizedDate($cart->updated_at)) : null,
                    ];
                }
            }

            $page++;
        } while ($carts->count() === self::CHUNK_SIZE);
    }

    public function headings(): array
    {
        return [
            'ID carrito',
            'Usuario',
            'Correo',
            'Marca',
            'ID producto/estudio',
            'Estudio',
            'Precio',
            'Cantidad',
            'Estado carrito',
            'Fecha ultima actividad',
        ];
    }

    public function title(): string
    {
        return 'Estudios';
    }

    public function columnFormats(): array
    {
        return [
            'G' => NumberFormat::FORMAT_CURRENCY_USD,
            'J' => NumberFormat::FORMAT_DATE_XLSX22,
        ];
    }

    private function brandLabel(Cart $cart): ?string
    {
        if ($cart->type !== MonitoringCartType::Lab) {
            return null;
        }

        return collect($cart->labBrands())->pluck('label')->filter()->implode(', ') ?: null;
    }

    protected function wrappedColumns(): array
    {
        return [
            'F' => 45,
        ];
    }
}
