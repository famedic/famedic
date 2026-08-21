<?php

namespace App\Exports\Carts;

use App\Enums\MonitoringCartStatus;
use App\Exports\Carts\Concerns\BuildsCartExportQuery;
use App\Exports\Carts\Concerns\FormatsCartExportSheet;
use App\Models\Cart;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat as SpreadsheetNumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CartsSummarySheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithEvents, WithHeadings, WithStyles, WithTitle
{
    use BuildsCartExportQuery;
    use FormatsCartExportSheet;

    public function __construct(
        private array $filters = []
    ) {}

    public function array(): array
    {
        $base = $this->baseCartQuery();
        $abandoned = (clone $base)->displayStatusFilter('abandoned');

        return [
            ['Fecha generacion', Date::dateTimeToExcel(localizedDate(now()))],
            ['Periodo utilizado', $this->periodLabel()],
            ['Filtros activos', $this->filtersLabel()],
            ['Total carritos', (clone $base)->count()],
            ['Activos', (clone $base)->displayStatusFilter('active')->count()],
            ['Abandonados', (clone $base)->displayStatusFilter('abandoned')->count()],
            ['Comprados', (clone $base)->where('status', MonitoringCartStatus::Completed)->count()],
            ['Atencion requerida', (clone $base)->operationalBucket('attention')->count()],
            ['Pagos rechazados', (clone $base)->relatedPaymentAttemptStatus('declined')->count()],
            ['Errores tecnicos', (clone $base)->relatedPaymentAttemptStatus('error')->count()],
            ['Citas pendientes', (clone $base)->appointmentPendingConfirmation()->count()],
            ['Citas confirmadas sin pago', (clone $base)->appointmentConfirmedPendingPayment()->count()],
            ['Solicitaron llamada', (clone $base)->contactFilter('callback_requested')->count()],
            ['Monto total carritos', (float) (clone $base)->sum('total')],
            ['Monto abandonado', (float) $abandoned->sum('total')],
        ];
    }

    public function headings(): array
    {
        return [
            'Metrica',
            'Valor',
        ];
    }

    public function title(): string
    {
        return 'Resumen';
    }

    public function columnFormats(): array
    {
        return [];
    }

    private function periodLabel(): string
    {
        $prefix = ($this->filters['_using_default_period'] ?? false) ? 'Ultimos 7 dias: ' : '';

        return $prefix.($this->filters['start_date'] ?? 'sin inicio').' a '.($this->filters['end_date'] ?? 'sin fin');
    }

    private function filtersLabel(): string
    {
        $filters = collect($this->filters)
            ->reject(fn ($value, string $key) => str_starts_with($key, '_'))
            ->map(fn ($value, string $key) => $key.'='.$value)
            ->values();

        return $filters->isEmpty() ? 'Sin filtros' : $filters->implode('; ');
    }

    protected function afterCartExportSheet(Worksheet $sheet): void
    {
        $sheet->getStyle('B2')->getNumberFormat()->setFormatCode(SpreadsheetNumberFormat::FORMAT_DATE_XLSX22);
        $sheet->getStyle('B15:B16')->getNumberFormat()->setFormatCode(SpreadsheetNumberFormat::FORMAT_CURRENCY_USD);
    }
}
