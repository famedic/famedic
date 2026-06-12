<?php

namespace App\Support\Api\V1;

use App\Actions\Laboratories\ResolveGdaResultsPdfAction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryNotification;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class OrderDocumentDownloadSupport
{
    public function __construct(
        private readonly ResolveGdaResultsPdfAction $resolveGdaResultsPdfAction,
    ) {}

    public function findCustomerOrder(Customer $customer, int $orderId): ?LaboratoryPurchase
    {
        return $customer->laboratoryPurchases()
            ->withTrashed()
            ->find($orderId);
    }

    public function resultBearerDownloadUrl(LaboratoryPurchase $order): string
    {
        return route('api.v1.orders.results.download', [
            'order_id' => $order->id,
        ], absolute: true);
    }

    public function invoiceBearerDownloadUrl(LaboratoryPurchase $order, Invoice $invoice): string
    {
        return route('api.v1.orders.invoices.download', [
            'order_id' => $order->id,
            'invoice_id' => $invoice->id,
        ], absolute: true);
    }

    /**
     * @return array{content: string, filename: string}|array{error: string}
     */
    public function resolveResultPdf(LaboratoryPurchase $order): array
    {
        if (! empty($order->results)) {
            return $this->readStoragePdf($order->results, "resultado-{$order->id}.pdf")
                ?? ['error' => 'RESULT_NOT_READY'];
        }

        $notification = LaboratoryOrderResults::latestResultsNotification($order);

        if ($notification === null || $notification->results_received_at === null) {
            return ['error' => 'RESULT_NOT_READY'];
        }

        if (! empty($notification->results_pdf_base64)) {
            $content = base64_decode($notification->results_pdf_base64, true);

            if ($content !== false && $this->isPdfContent($content)) {
                return [
                    'content' => $content,
                    'filename' => "resultado-{$order->id}.pdf",
                ];
            }

            return ['error' => 'RESULT_NOT_READY'];
        }

        try {
            $result = ($this->resolveGdaResultsPdfAction)($notification);
            $content = base64_decode($result['pdf_base64'], true);

            if ($content !== false && $this->isPdfContent($content)) {
                return [
                    'content' => $content,
                    'filename' => "resultado-{$order->id}.pdf",
                ];
            }
        } catch (\Throwable) {
            // Sin PDF disponible aún.
        }

        return ['error' => 'RESULT_NOT_READY'];
    }

    /**
     * @return array{content: string, filename: string}|array{error: string}
     */
    public function resolveInvoicePdf(LaboratoryPurchase $order, int $invoiceId): array
    {
        $invoice = Invoice::query()
            ->withTrashed()
            ->find($invoiceId);

        if ($invoice === null
            || $invoice->invoiceable_type !== LaboratoryPurchase::class
            || (int) $invoice->invoiceable_id !== (int) $order->id
        ) {
            return ['error' => 'INVOICE_NOT_FOUND'];
        }

        if (empty($invoice->invoice)) {
            return ['error' => 'INVOICE_NOT_READY'];
        }

        return $this->readStoragePdf($invoice->invoice, "factura-{$invoice->id}.pdf")
            ?? ['error' => 'INVOICE_NOT_READY'];
    }

    public function pdfResponse(string $content, string $filename): Response
    {
        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * @return array{content: string, filename: string}|null
     */
    private function readStoragePdf(string $path, string $filename): ?array
    {
        if (! Storage::exists($path)) {
            return null;
        }

        $content = Storage::get($path);

        if ($content === null || ! $this->isPdfContent($content)) {
            return null;
        }

        return [
            'content' => $content,
            'filename' => $filename,
        ];
    }

    private function isPdfContent(string $content): bool
    {
        return str_starts_with($content, '%PDF');
    }
}
