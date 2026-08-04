<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Otp\SecureDownloadLinkException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Orders\InvoicesSecureLinkRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Api\V1\Audit\AuditOutcome;
use App\Services\Api\V1\Audit\DocumentAccessAuditRecorder;
use App\Services\Otp\StepUp\OtpSecureDownloadLinkService;
use App\Services\Otp\StepUp\OtpStepUpGrantService;
use Illuminate\Http\JsonResponse;

class OrderInvoicesSecureLinkController extends Controller
{
    public function __construct(
        private readonly OtpSecureDownloadLinkService $secureLinkService,
        private readonly OtpStepUpGrantService $grantService,
        private readonly DocumentAccessAuditRecorder $documentAudit,
    ) {
    }

    public function store(InvoicesSecureLinkRequest $request, int $orderId, int $invoiceId): JsonResponse
    {
        if (! OtpSecureDownloadLinkService::isInvoicesEnabled()) {
            return ApiResponse::error(
                'FEATURE_DISABLED',
                'Las ligas seguras de facturas no estan habilitadas.',
                503,
            );
        }

        $order = $this->secureLinkService->findOwnedOrder(
            $request->user()->customer,
            $orderId,
        );

        if ($order === null) {
            $response = ApiResponse::error(
                'ORDER_NOT_FOUND',
                'Pedido no encontrado.',
                404,
            );
            $this->documentAudit->recordInvoicesSecureLinkCreated(
                request: $request,
                outcome: AuditOutcome::REJECTED,
                httpStatus: 404,
                errorCode: 'ORDER_NOT_FOUND',
            );

            return $response;
        }

        $invoice = $this->secureLinkService->findOwnedInvoice($order, $invoiceId);
        if ($invoice === null) {
            $response = ApiResponse::error(
                'INVOICE_NOT_FOUND',
                'Factura no encontrada.',
                404,
            );
            // Owned order confirmed; invoice not owned/found — omit invoice ids.
            $this->documentAudit->recordInvoicesSecureLinkCreated(
                request: $request,
                outcome: AuditOutcome::REJECTED,
                httpStatus: 404,
                errorCode: 'INVOICE_NOT_FOUND',
                laboratoryPurchaseRowId: (int) $order->id,
                orderRowId: (int) $order->id,
            );

            return $response;
        }

        $tokenId = $this->grantService->resolvePersonalAccessTokenId(
            $request->user()->currentAccessToken(),
        );

        if ($this->grantService->bindToSanctumToken() && $tokenId === null) {
            $response = ApiResponse::error(
                'STEP_UP_GRANT_INVALID',
                'El grant de step-up no es valido.',
                422,
            );
            $this->documentAudit->recordInvoicesSecureLinkCreated(
                request: $request,
                outcome: AuditOutcome::REJECTED,
                httpStatus: 422,
                errorCode: 'STEP_UP_GRANT_INVALID',
                resourceKey: (string) $invoice->id,
                laboratoryPurchaseRowId: (int) $order->id,
                orderRowId: (int) $order->id,
                invoiceRowId: (int) $invoice->id,
            );

            return $response;
        }

        try {
            $issued = $this->secureLinkService->issueInvoicesLink(
                $request->user(),
                $order,
                $invoice,
                $request->validated('grant_id'),
                $tokenId,
            );
        } catch (SecureDownloadLinkException $e) {
            $response = ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus);
            $classified = $this->documentAudit->classifyErrorResponse($response);
            $this->documentAudit->recordInvoicesSecureLinkCreated(
                request: $request,
                outcome: $classified['outcome'],
                httpStatus: $classified['http_status'],
                errorCode: $classified['error_code'],
                resourceKey: (string) $invoice->id,
                laboratoryPurchaseRowId: (int) $order->id,
                orderRowId: (int) $order->id,
                invoiceRowId: (int) $invoice->id,
            );

            return $response;
        }

        $audit = $issued['audit'];
        unset($issued['audit']);

        $this->documentAudit->recordInvoicesSecureLinkCreated(
            request: $request,
            outcome: AuditOutcome::SUCCEEDED,
            httpStatus: 201,
            resourceKey: (string) $invoice->id,
            secureLinkRowId: $audit['secure_link_row_id'],
            stepUpRowId: $audit['step_up_row_id'],
            laboratoryPurchaseRowId: (int) $order->id,
            orderRowId: (int) $order->id,
            invoiceRowId: (int) $invoice->id,
            ttlMinutes: $audit['ttl_minutes'],
            maxOpens: $audit['max_opens'],
        );

        return ApiResponse::success($issued, null, 201);
    }
}
