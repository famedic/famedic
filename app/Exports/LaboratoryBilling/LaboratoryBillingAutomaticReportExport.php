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
            ...($include('activity') ? ['Actividad del periodo' => new LaboratoryBillingReportRowsSheet($this->reportData['rows']['received'] ?? [], 'Actividad del periodo')] : []),
            ...($include('backlog') ? ['Pendientes del periodo' => new LaboratoryBillingReportRowsSheet($this->reportData['rows']['backlog'] ?? [], 'Pendientes del periodo')] : []),
            ...($include('overdue') ? ['Atrasadas del periodo' => new LaboratoryBillingReportRowsSheet($this->reportData['rows']['overdue'] ?? [], 'Atrasadas del periodo')] : []),
            ...($include('completed') ? ['Completadas del periodo' => new LaboratoryBillingReportRowsSheet($this->reportData['rows']['completed'] ?? [], 'Completadas del periodo')] : []),
        ];
    }
}
