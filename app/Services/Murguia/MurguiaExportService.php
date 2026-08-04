<?php

namespace App\Services\Murguia;

use App\Exports\MurguiaInsuredReportExport;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MurguiaExportService
{
    public function __construct(
        protected MurguiaReportService $reportService
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function export(array $filters, string $format): StreamedResponse|BinaryFileResponse
    {
        return match ($format) {
            'csv' => $this->exportCsv($filters),
            'xlsx' => $this->exportXlsx($filters),
            default => abort(422, 'Formato no soportado. Use csv o xlsx.'),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function exportCsv(array $filters): StreamedResponse
    {
        $filename = 'murguia-asegurados-' . now()->format('Y-m-d_His') . '.csv';
        $reportService = $this->reportService;

        return response()->streamDownload(function () use ($filters, $reportService) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $reportService->columnHeadings());

            foreach ($reportService->lazyForExport($filters) as $customer) {
                fputcsv($handle, $reportService->mapCustomerToExportRow($customer));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function exportXlsx(array $filters): BinaryFileResponse
    {
        $filename = 'murguia-asegurados-' . now()->format('Y-m-d_His') . '.xlsx';

        return Excel::download(
            new MurguiaInsuredReportExport($filters, $this->reportService),
            $filename
        );
    }
}
