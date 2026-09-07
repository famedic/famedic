<?php

namespace App\Exports\LaboratoryBilling;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class LaboratoryBillingAutomaticReportExport implements WithMultipleSheets
{
    public function __construct(
        private array $reportData,
    ) {}

    public function sheets(): array
    {
        $sections = $this->reportData['_included_sections'] ?? [];
        $include = fn (string $section): bool => $sections === [] || in_array($section, $sections, true);

        return [
            'Resumen' => new LaboratoryBillingReportSummarySheet($this->reportData),
            ...($include('backlog') ? ['Pendientes actuales' => new LaboratoryBillingReportRowsSheet($this->reportData['rows']['backlog'] ?? [], 'Pendientes actuales')] : []),
            ...($include('overdue') ? ['Solicitudes atrasadas' => new LaboratoryBillingReportRowsSheet($this->reportData['rows']['overdue'] ?? [], 'Solicitudes atrasadas')] : []),
            ...($include('completed') ? ['Completadas en periodo' => new LaboratoryBillingReportRowsSheet($this->reportData['rows']['completed'] ?? [], 'Completadas en periodo')] : []),
            ...($include('activity') ? ['Actividad del periodo' => new LaboratoryBillingReportRowsSheet($this->reportData['rows']['received'] ?? [], 'Actividad del periodo')] : []),
        ];
    }
}
