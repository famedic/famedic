<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\OtpStepUpGrant;
use App\Services\Api\V1\Audit\AuditOutcome;
use App\Services\Api\V1\Audit\DocumentAccessAuditRecorder;
use App\Services\Otp\StepUp\BearerStepUpEnforcement;
use App\Support\Api\V1\LaboratoryOrderStatus;
use App\Support\Api\V1\OrderDocumentDownloadSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OrderDocumentDownloadController extends Controller
{
    public function __construct(
        private readonly DocumentAccessAuditRecorder $documentAudit,
    ) {
    }

    public function downloadResult(
        Request $request,
        int $orderId,
        OrderDocumentDownloadSupport $downloadSupport,
        BearerStepUpEnforcement $stepUpEnforcement,
    ): Response|JsonResponse {
        $order = $downloadSupport->findCustomerOrder($request->user()->customer, $orderId);

        if ($order === null) {
            $response = ApiResponse::error(
                'ORDER_NOT_FOUND',
                'Pedido no encontrado.',
                404,
            );
            // Cross-user / missing: no foreign resource ids in audit.
            $this->documentAudit->recordResultsDownloaded(
                request: $request,
                outcome: AuditOutcome::REJECTED,
                httpStatus: 404,
                errorCode: 'ORDER_NOT_FOUND',
            );

            return $response;
        }

        if ($denied = $stepUpEnforcement->assertResultsGrant($request, (int) $order->id)) {
            $classified = $this->documentAudit->classifyErrorResponse($denied);
            $this->documentAudit->recordResultsDownloaded(
                request: $request,
                outcome: $classified['outcome'],
                httpStatus: $classified['http_status'],
                errorCode: $classified['error_code'],
                resourceKey: (string) $order->id,
                laboratoryPurchaseRowId: (int) $order->id,
                orderRowId: (int) $order->id,
            );

            return $denied;
        }

        if (! LaboratoryOrderStatus::hasResults($order)) {
            $response = ApiResponse::error(
                'RESULT_NOT_READY',
                'El resultado aún no está disponible.',
                409,
            );
            $this->documentAudit->recordResultsDownloaded(
                request: $request,
                outcome: AuditOutcome::REJECTED,
                httpStatus: 409,
                errorCode: 'RESULT_NOT_READY',
                resourceKey: (string) $order->id,
                stepUpRowId: $this->resolveStepUpRowIdFromHeader($request),
                laboratoryPurchaseRowId: (int) $order->id,
                orderRowId: (int) $order->id,
            );

            return $response;
        }

        $resolved = $downloadSupport->resolveResultPdf($order);

        if (isset($resolved['error'])) {
            $response = ApiResponse::error(
                $resolved['error'],
                'El resultado aún no está disponible.',
                409,
            );
            $this->documentAudit->recordResultsDownloaded(
                request: $request,
                outcome: AuditOutcome::REJECTED,
                httpStatus: 409,
                errorCode: $resolved['error'],
                resourceKey: (string) $order->id,
                stepUpRowId: $this->resolveStepUpRowIdFromHeader($request),
                laboratoryPurchaseRowId: (int) $order->id,
                orderRowId: (int) $order->id,
            );

            return $response;
        }

        $response = $downloadSupport->pdfResponse($resolved['content'], $resolved['filename']);

        $this->documentAudit->recordResultsDownloaded(
            request: $request,
            outcome: AuditOutcome::SUCCEEDED,
            httpStatus: 200,
            resourceKey: (string) $order->id,
            stepUpRowId: $this->resolveStepUpRowIdFromHeader($request),
            laboratoryPurchaseRowId: (int) $order->id,
            orderRowId: (int) $order->id,
        );

        return $response;
    }

    public function downloadInvoice(
        Request $request,
        int $orderId,
        int $invoiceId,
        OrderDocumentDownloadSupport $downloadSupport,
        BearerStepUpEnforcement $stepUpEnforcement,
    ): Response|JsonResponse {
        $order = $downloadSupport->findCustomerOrder($request->user()->customer, $orderId);

        if ($order === null) {
            $response = ApiResponse::error(
                'ORDER_NOT_FOUND',
                'Pedido no encontrado.',
                404,
            );
            $this->documentAudit->recordInvoicesDownloaded(
                request: $request,
                outcome: AuditOutcome::REJECTED,
                httpStatus: 404,
                errorCode: 'ORDER_NOT_FOUND',
            );

            return $response;
        }

        $invoice = $downloadSupport->findOwnedInvoice($order, $invoiceId);
        if ($invoice === null) {
            $response = ApiResponse::error(
                'INVOICE_NOT_FOUND',
                'Factura no encontrada.',
                404,
            );
            $this->documentAudit->recordInvoicesDownloaded(
                request: $request,
                outcome: AuditOutcome::REJECTED,
                httpStatus: 404,
                errorCode: 'INVOICE_NOT_FOUND',
                laboratoryPurchaseRowId: (int) $order->id,
                orderRowId: (int) $order->id,
            );

            return $response;
        }

        if ($denied = $stepUpEnforcement->assertInvoicesGrant($request, (int) $invoice->id)) {
            $classified = $this->documentAudit->classifyErrorResponse($denied);
            $this->documentAudit->recordInvoicesDownloaded(
                request: $request,
                outcome: $classified['outcome'],
                httpStatus: $classified['http_status'],
                errorCode: $classified['error_code'],
                resourceKey: (string) $invoice->id,
                laboratoryPurchaseRowId: (int) $order->id,
                orderRowId: (int) $order->id,
                invoiceRowId: (int) $invoice->id,
            );

            return $denied;
        }

        $resolved = $downloadSupport->resolveInvoicePdf($order, $invoiceId);

        if (isset($resolved['error'])) {
            $response = match ($resolved['error']) {
                'INVOICE_NOT_FOUND' => ApiResponse::error(
                    'INVOICE_NOT_FOUND',
                    'Factura no encontrada.',
                    404,
                ),
                default => ApiResponse::error(
                    'INVOICE_NOT_READY',
                    'La factura aún no está disponible.',
                    409,
                ),
            };
            $classified = $this->documentAudit->classifyErrorResponse($response);
            $this->documentAudit->recordInvoicesDownloaded(
                request: $request,
                outcome: $classified['outcome'],
                httpStatus: $classified['http_status'],
                errorCode: $classified['error_code'],
                resourceKey: (string) $invoice->id,
                stepUpRowId: $this->resolveStepUpRowIdFromHeader($request),
                laboratoryPurchaseRowId: (int) $order->id,
                orderRowId: (int) $order->id,
                invoiceRowId: (int) $invoice->id,
            );

            return $response;
        }

        $response = $downloadSupport->pdfResponse($resolved['content'], $resolved['filename']);

        $this->documentAudit->recordInvoicesDownloaded(
            request: $request,
            outcome: AuditOutcome::SUCCEEDED,
            httpStatus: 200,
            resourceKey: (string) $invoice->id,
            stepUpRowId: $this->resolveStepUpRowIdFromHeader($request),
            laboratoryPurchaseRowId: (int) $order->id,
            orderRowId: (int) $order->id,
            invoiceRowId: (int) $invoice->id,
        );

        return $response;
    }

    /**
     * Resolve internal grant row id from the presented header when present.
     * Never persists the header value itself.
     */
    private function resolveStepUpRowIdFromHeader(Request $request): ?int
    {
        $header = trim((string) $request->header(BearerStepUpEnforcement::HEADER, ''));
        if ($header === '') {
            return null;
        }

        $id = OtpStepUpGrant::query()->where('public_id', $header)->value('id');

        return $id !== null ? (int) $id : null;
    }
}
