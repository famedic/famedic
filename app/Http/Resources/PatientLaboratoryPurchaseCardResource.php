<?php

namespace App\Http\Resources;

use App\Models\LaboratoryPurchase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tarjeta de pedido de laboratorio para el panel del paciente (sin datos sensibles innecesarios).
 *
 * @mixin LaboratoryPurchase
 */
class PatientLaboratoryPurchaseCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var LaboratoryPurchase $p */
        $p = $this->resource;

        $manualResults = ! empty($p->results);
        $resultsNotif = $p->resultsNotification()->first();
        $apiResultsReady = $resultsNotif !== null
            && $resultsNotif->results_received_at !== null;

        $hasResults = $manualResults || $apiResultsReady;
        $cancelled = $p->trashed();

        $studyStatus = $this->resolveStudyStatus($cancelled, $hasResults, $p);

        $resultSource = match (true) {
            $manualResults => 'manual',
            $apiResultsReady => 'api',
            default => null,
        };

        $resultViewUrl = null;
        $resultDownloadUrl = null;

        if ($manualResults) {
            $resultViewUrl = route('laboratory-purchases.results', ['laboratory_purchase' => $p->id]);
            $resultDownloadUrl = $resultViewUrl;
        } elseif ($apiResultsReady) {
            $resultViewUrl = route('laboratory-results.view', ['type' => 'purchase', 'id' => $p->id]);
            $resultDownloadUrl = route('laboratory-results.download', ['type' => 'purchase', 'id' => $p->id]);
        }

        $invoice = $p->invoice;
        $invoiceUrl = $invoice ? route('invoice', ['invoice' => $invoice->id]) : null;

        $transaction = $p->transactions->first();

        $isNewResult = false;
        if ($resultsNotif && $resultsNotif->results_received_at && $resultsNotif->read_at === null) {
            $isNewResult = true;
        }

        $items = $p->laboratoryPurchaseItems ?? collect();
        $studyName = $this->formatStudyName($items);

        $showDetail = route('laboratory-purchases.show', ['laboratory_purchase' => $p->id]);

        $hasSampleNotification = (bool) ($p->getAttribute('has_sample_collected') ?? $p->hasSampleCollected());

        $resultsPdfBase64Available = $resultsNotif !== null
            && ! empty($resultsNotif->results_pdf_base64);

        $canDownloadPdf = match (true) {
            $manualResults => $resultViewUrl !== null,
            $apiResultsReady => $resultsPdfBase64Available,
            default => false,
        };

        return [
            'id' => $p->id,
            'patient_name' => trim($p->full_name ?? '') ?: 'Paciente',
            'study_name' => $studyName,
            'study_status' => $studyStatus,
            'study_status_label' => $this->statusLabel($studyStatus),
            'payment_method' => $transaction?->payment_method,
            'payment_method_label' => $this->paymentMethodLabel($transaction?->payment_method),
            'laboratory_name' => $p->brand?->label() ?? (string) $p->brand,
            'laboratory_brand_value' => $p->brand?->value ?? $p->brand,
            'purchased_at' => $p->created_at?->toIso8601String(),
            'purchased_at_formatted' => $p->formatted_created_at,
            'formatted_total' => $p->formatted_total,
            'gda_order_id' => $p->gda_order_id,
            'temporarly_hide_gda_order_id' => $p->temporarly_hide_gda_order_id,
            'gda_consecutivo' => $p->gda_consecutivo,
            'result_source' => $resultSource,
            'pdf_url' => $manualResults ? $resultViewUrl : null,
            'results_pdf_base64_available' => $resultsPdfBase64Available,
            'can_download_pdf' => $canDownloadPdf,
            'api_result_url' => $apiResultsReady ? $resultViewUrl : null,
            'result_view_url' => $resultViewUrl,
            'result_download_url' => $resultDownloadUrl,
            'invoice_url' => $invoiceUrl,
            'has_invoice' => $invoice !== null,
            'invoice_requested' => $p->invoiceRequest !== null,
            'has_results' => $hasResults,
            'is_pipeline_invoiced' => $invoice !== null
                && $p->invoiceRequest !== null,
            'has_sample_notification' => $hasSampleNotification,
            'is_new_result' => $isNewResult,
            'show_detail_url' => $showDetail,
            'invoice_request_url' => $showDetail.'?tab=facturas',
            'items_count' => $items->count(),
            'studies_count' => $items->count(),
        ];
    }

    private function formatStudyName($items): string
    {
        if ($items->isEmpty()) {
            return 'Estudio de laboratorio';
        }

        if ($items->count() === 1) {
            return $items->first()->name ?? 'Estudio';
        }

        $names = $items->take(2)->pluck('name')->filter()->values();
        $extra = $items->count() > 2 ? ' y otros' : '';

        return $names->join(', ').$extra;
    }

    private function resolveStudyStatus(bool $cancelled, bool $hasResults, LaboratoryPurchase $p): string
    {
        if ($cancelled) {
            return 'cancelled';
        }

        if ($hasResults) {
            return 'results_ready';
        }

        $hasSample = $p->hasSampleCollected();

        if ($hasSample) {
            return 'sample_taken';
        }

        return 'in_progress';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'in_progress' => 'En proceso',
            'sample_taken' => 'Muestra tomada',
            'results_ready' => 'Resultados listos',
            'cancelled' => 'Cancelado',
            default => $status,
        };
    }

    private function paymentMethodLabel(?string $method): string
    {
        return match ($method) {
            'stripe' => 'Tarjeta (Stripe)',
            'odessa' => 'Caja de ahorro (Odessa)',
            'efevoopay' => 'Efevoo',
            'paypal' => 'PayPal',
            null => '—',
            default => ucfirst(str_replace('_', ' ', (string) $method)),
        };
    }
}
