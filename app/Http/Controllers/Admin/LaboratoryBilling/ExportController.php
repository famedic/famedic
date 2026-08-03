<?php

namespace App\Http\Controllers\Admin\LaboratoryBilling;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryBilling\IndexLaboratoryBillingInvoicesRequest;
use App\Http\Requests\Admin\LaboratoryBilling\IndexLaboratoryBillingRequestsRequest;
use App\Http\Requests\Admin\LaboratoryBilling\IndexLaboratoryBillingTaxProfilesRequest;
use App\Services\LaboratoryBilling\LaboratoryBillingDateRange;
use App\Services\LaboratoryBilling\LaboratoryBillingInvoicesQuery;
use App\Services\LaboratoryBilling\LaboratoryBillingRequestsQuery;
use App\Services\LaboratoryBilling\LaboratoryBillingTaxProfilesQuery;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function requests(
        IndexLaboratoryBillingRequestsRequest $request,
        LaboratoryBillingRequestsQuery $query,
    ): StreamedResponse {
        $range = LaboratoryBillingDateRange::fromInput($request->input('from'), $request->input('to'));
        $filters = collect($request->only([
            'search', 'status', 'overdue', 'document', 'tax_profile_id', 'customer_id', 'brand',
        ]))->filter(fn ($value) => $value !== null && $value !== '')->all();

        $rows = $query->exportRows($filters, $range);

        return $this->csv('laboratory-billing-requests.csv', [
            'ID', 'Paciente', 'Email', 'Pedido', 'Folio', 'Fecha solicitud', 'Estado',
            'Días transcurridos', 'Días atraso', 'Total', 'RFC', 'Razón social', 'PDF', 'XML',
        ], $rows->map(fn (array $row) => [
            $row['id'],
            $row['patient_name'],
            $row['customer_email'] ?? '',
            $row['purchase']['id'] ?? '',
            $row['purchase']['folio'] ?? '',
            $row['formatted_requested_at'] ?? '',
            $row['billing']['status_label'] ?? '',
            $row['billing']['days_elapsed'] ?? '',
            $row['billing']['days_overdue'] ?? '',
            $row['purchase']['formatted_total'] ?? '',
            $row['snapshot']['rfc'] ?? '',
            $row['snapshot']['name'] ?? '',
            ($row['billing']['has_pdf'] ?? false) ? 'Sí' : 'No',
            ($row['billing']['has_xml'] ?? false) ? 'Sí' : 'No',
        ]));
    }

    public function invoices(
        IndexLaboratoryBillingInvoicesRequest $request,
        LaboratoryBillingInvoicesQuery $query,
    ): StreamedResponse {
        $range = LaboratoryBillingDateRange::fromInput($request->input('from'), $request->input('to'));
        $filters = collect($request->only(['search', 'document']))
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();

        $rows = $query->exportRows($filters, $range);

        return $this->csv('laboratory-billing-invoices.csv', [
            'Pedido', 'Paciente', 'RFC', 'Razón social', 'Fecha solicitud',
            'Fecha finalización', 'Última actualización',
            'Tiempo respuesta (h)', 'Estado documental', 'PDF', 'XML',
        ], $rows->map(fn (array $row) => [
            $row['purchase']['folio'] ?? '',
            $row['patient_name'],
            $row['snapshot']['rfc'] ?? '',
            $row['snapshot']['name'] ?? '',
            $row['formatted_requested_at'] ?? '',
            $row['invoice']['formatted_completed_at'] ?? '',
            $row['invoice']['formatted_updated_at'] ?? '',
            $row['billing']['response_time_hours'] ?? '',
            $row['billing']['document_status_label'] ?? '',
            ($row['billing']['has_pdf'] ?? false) ? 'Sí' : 'No',
            ($row['billing']['has_xml'] ?? false) ? 'Sí' : 'No',
        ]));
    }

    public function taxProfiles(
        IndexLaboratoryBillingTaxProfilesRequest $request,
        LaboratoryBillingTaxProfilesQuery $query,
    ): StreamedResponse {
        $range = LaboratoryBillingDateRange::fromInput($request->input('from'), $request->input('to'));
        $filters = collect($request->only([
            'search', 'status', 'usage', 'is_default', 'tipo_persona', 'include_deleted', 'created_in_range',
        ]))->filter(fn ($value) => $value !== null && $value !== '')->all();

        $rows = $query->exportRows($filters, $range);

        return $this->csv('laboratory-billing-tax-profiles.csv', [
            'ID', 'Nombre', 'RFC', 'Tipo persona', 'Paciente', 'Email', 'Estado',
            'Predeterminado', 'Solicitudes', 'Creado',
        ], $rows->map(fn (array $row) => [
            $row['id'],
            $row['razon_social'] ?? $row['name'] ?? '',
            $row['rfc'] ?? '',
            $row['tipo_persona_label'] ?? '',
            $row['customer']['name'] ?? '',
            $row['customer']['email'] ?? '',
            ($row['is_active'] ?? false) ? 'Activo' : 'Eliminado',
            ($row['is_default'] ?? false) ? 'Sí' : 'No',
            $row['invoice_requests_count'] ?? 0,
            $row['formatted_created_at'] ?? '',
        ]));
    }

    private function csv(string $filename, array $headers, Collection $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
