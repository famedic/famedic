<?php

namespace App\Exports;

use App\Models\Customer;
use App\Services\Murguia\MurguiaReportService;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class MurguiaInsuredReportExport implements FromQuery, ShouldAutoSize, WithChunkReading, WithHeadings, WithMapping
{
    /** @var array<string, mixed> */
    protected array $filters;

    protected MurguiaReportService $reportService;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(array $filters, MurguiaReportService $reportService)
    {
        $this->filters = $filters;
        $this->reportService = $reportService;
    }

    public function query()
    {
        return $this->reportService->buildQuery($this->filters);
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function headings(): array
    {
        return $this->reportService->columnHeadings();
    }

    /**
     * @param  Customer  $customer
     */
    public function map($customer): array
    {
        return $this->reportService->mapCustomerToExportRow($customer);
    }
}
