<?php

namespace App\Exports\LaboratoryBilling;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LaboratoryBillingReportSummarySheet implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    public function __construct(private array $reportData) {}

    public function headings(): array
    {
        return ['Métrica', 'Valor'];
    }

    public function collection(): Collection
    {
        $metrics = $this->reportData['metrics'] ?? [];
        $aging = $metrics['aging'] ?? [];
        $missing = $metrics['missing_files'] ?? [];

        $rows = [
            ['Periodo', $this->reportData['period']['label'] ?? ''],
            ['Corte de backlog', $this->reportData['backlog_as_of'] ?? ''],
            ['Solicitudes recibidas en periodo', $metrics['received'] ?? 0],
            ['Facturas completadas en periodo', $metrics['completed'] ?? 0],
            ['Pendientes actuales', $metrics['pending_backlog'] ?? 0],
            ['Solicitudes atrasadas', $metrics['overdue_backlog'] ?? 0],
            ['Cumplimiento', ($metrics['compliance_percent'] ?? 0).'%'],
            ['Tiempo promedio de atención (h)', $metrics['average_response_hours'] ?? ''],
            ['Pendiente más antigua', data_get($metrics, 'oldest_pending.formatted_requested_at', '')],
            ['Dentro del plazo', $aging['within_sla'] ?? 0],
            ['Atrasadas 1 a 3 días', $aging['overdue_1_3'] ?? 0],
            ['Atrasadas 4 a 7 días', $aging['overdue_4_7'] ?? 0],
            ['Atrasadas más de 7 días', $aging['overdue_more_7'] ?? 0],
            ['Falta PDF', $missing['missing_pdf'] ?? 0],
            ['Falta XML', $missing['missing_xml'] ?? 0],
            ['Faltan ambos', $missing['missing_both'] ?? 0],
        ];

        if ($metrics['detail_truncated'] ?? false) {
            $rows[] = [
                'Detalle exportado',
                'Limitado a '.($metrics['detail_exported_rows'] ?? 0).' de '.($metrics['detail_total_rows'] ?? 0).' filas.',
            ];
        }

        return collect($rows);
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
        return 'Resumen';
    }
}
