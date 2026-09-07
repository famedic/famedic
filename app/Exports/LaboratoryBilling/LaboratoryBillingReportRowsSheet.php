<?php

namespace App\Exports\LaboratoryBilling;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LaboratoryBillingReportRowsSheet implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStyles, WithTitle
{
    public function __construct(
        private iterable $rows,
        private string $title,
    ) {}

    public function headings(): array
    {
        return [
            'Folio',
            'Fecha de solicitud',
            'Fecha límite',
            'Estado',
            'Antigüedad',
            'Cliente/Razón social',
            'Correo',
            'RFC',
            'Marca',
            'Sucursal',
            'Monto',
            'PDF cargado',
            'XML cargado',
            'Archivos faltantes',
            'Fecha de finalización',
            'Tiempo de atención (h)',
            'Enlace administrativo',
        ];
    }

    public function collection(): Collection
    {
        return collect($this->rows)->map(fn (array $row) => [
            data_get($row, 'purchase.folio', ''),
            data_get($row, 'formatted_requested_at', ''),
            data_get($row, 'billing.formatted_due_at', ''),
            data_get($row, 'billing.status_label', ''),
            data_get($row, 'billing.days_elapsed', ''),
            data_get($row, 'snapshot.name', data_get($row, 'patient_name', '')),
            data_get($row, 'customer_email', ''),
            data_get($row, 'snapshot.rfc', ''),
            data_get($row, 'purchase.brand_label', data_get($row, 'purchase.brand', '')),
            data_get($row, 'purchase.store.name', ''),
            data_get($row, 'purchase.total_cents') !== null ? ((int) data_get($row, 'purchase.total_cents') / 100) : null,
            data_get($row, 'billing.has_pdf') ? 'Sí' : 'No',
            data_get($row, 'billing.has_xml') ? 'Sí' : 'No',
            data_get($row, 'missing_files', ''),
            data_get($row, 'invoice.formatted_completed_at', ''),
            data_get($row, 'billing.response_time_hours', ''),
            data_get($row, 'detail_url', ''),
        ]);
    }

    public function columnFormats(): array
    {
        return [
            'K' => NumberFormat::FORMAT_CURRENCY_USD,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());

        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    public function title(): string
    {
        return mb_substr($this->title, 0, 31);
    }
}
