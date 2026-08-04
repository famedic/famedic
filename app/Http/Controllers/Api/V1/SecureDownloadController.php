<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Otp\SecureDownloadLinkException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Api\V1\Audit\AuditOutcome;
use App\Services\Api\V1\Audit\DocumentAccessAuditRecorder;
use App\Services\Otp\StepUp\OtpSecureDownloadLinkService;
use App\Support\Api\V1\OrderDocumentDownloadSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SecureDownloadController extends Controller
{
    public function __construct(
        private readonly OtpSecureDownloadLinkService $secureLinkService,
        private readonly OrderDocumentDownloadSupport $downloadSupport,
        private readonly DocumentAccessAuditRecorder $documentAudit,
    ) {
    }

    public function show(Request $request, string $token): Response|JsonResponse
    {
        try {
            $resolved = $this->secureLinkService->consumeAndResolvePdf($token);
        } catch (SecureDownloadLinkException $e) {
            $response = ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus);
            $this->auditOpenFailure($request, $token, $response, $e->auditContext);

            return $response;
        }

        $audit = $resolved['audit'];
        $response = $this->downloadSupport->pdfResponse($resolved['content'], $resolved['filename']);

        $this->auditOpenSuccess($request, $token, $audit);

        return $response;
    }

    /**
     * @param  array<string, mixed>|null  $ctx
     */
    private function auditOpenFailure(
        Request $request,
        string $token,
        JsonResponse $response,
        ?array $ctx,
    ): void {
        // Unknown token: purpose cannot be determined without becoming an oracle.
        if ($ctx === null || ! is_string($ctx['purpose'] ?? null)) {
            return;
        }

        $classified = $this->documentAudit->classifyErrorResponse($response);
        $purpose = $this->documentAudit->auditPurposeFromLinkPurpose((string) $ctx['purpose']);

        if ($purpose === DocumentAccessAuditRecorder::PURPOSE_RESULTS) {
            $this->documentAudit->recordResultsSecureLinkOpened(
                request: $request,
                presentedToken: $token,
                outcome: $classified['outcome'],
                httpStatus: $classified['http_status'],
                errorCode: $classified['error_code'],
                resourceKey: isset($ctx['resource_id']) ? (string) $ctx['resource_id'] : null,
                secureLinkRowId: isset($ctx['secure_link_row_id']) ? (int) $ctx['secure_link_row_id'] : null,
                stepUpRowId: isset($ctx['step_up_row_id']) ? (int) $ctx['step_up_row_id'] : null,
                laboratoryPurchaseRowId: isset($ctx['laboratory_purchase_row_id'])
                    ? (int) $ctx['laboratory_purchase_row_id']
                    : null,
                orderRowId: isset($ctx['order_row_id']) ? (int) $ctx['order_row_id'] : null,
                maxOpens: isset($ctx['max_opens']) ? (int) $ctx['max_opens'] : null,
            );

            return;
        }

        if ($purpose === DocumentAccessAuditRecorder::PURPOSE_INVOICES) {
            $this->documentAudit->recordInvoicesSecureLinkOpened(
                request: $request,
                presentedToken: $token,
                outcome: $classified['outcome'],
                httpStatus: $classified['http_status'],
                errorCode: $classified['error_code'],
                resourceKey: isset($ctx['resource_id']) ? (string) $ctx['resource_id'] : null,
                secureLinkRowId: isset($ctx['secure_link_row_id']) ? (int) $ctx['secure_link_row_id'] : null,
                stepUpRowId: isset($ctx['step_up_row_id']) ? (int) $ctx['step_up_row_id'] : null,
                laboratoryPurchaseRowId: isset($ctx['laboratory_purchase_row_id'])
                    ? (int) $ctx['laboratory_purchase_row_id']
                    : null,
                orderRowId: isset($ctx['order_row_id']) ? (int) $ctx['order_row_id'] : null,
                invoiceRowId: isset($ctx['invoice_row_id']) ? (int) $ctx['invoice_row_id'] : null,
                maxOpens: isset($ctx['max_opens']) ? (int) $ctx['max_opens'] : null,
            );
        }
    }

    /**
     * @param  array{
     *     purpose: string,
     *     secure_link_row_id: int,
     *     step_up_row_id: int,
     *     resource_type: string,
     *     resource_id: int,
     *     laboratory_purchase_row_id: int|null,
     *     order_row_id: int|null,
     *     invoice_row_id: int|null,
     *     open_number: int,
     *     max_opens: int
     * }  $audit
     */
    private function auditOpenSuccess(Request $request, string $token, array $audit): void
    {
        $purpose = $this->documentAudit->auditPurposeFromLinkPurpose($audit['purpose']);

        if ($purpose === DocumentAccessAuditRecorder::PURPOSE_RESULTS) {
            $this->documentAudit->recordResultsSecureLinkOpened(
                request: $request,
                presentedToken: $token,
                outcome: AuditOutcome::SUCCEEDED,
                httpStatus: 200,
                resourceKey: (string) $audit['resource_id'],
                secureLinkRowId: $audit['secure_link_row_id'],
                stepUpRowId: $audit['step_up_row_id'],
                laboratoryPurchaseRowId: $audit['laboratory_purchase_row_id'],
                orderRowId: $audit['order_row_id'],
                openNumber: $audit['open_number'],
                maxOpens: $audit['max_opens'],
            );

            return;
        }

        if ($purpose === DocumentAccessAuditRecorder::PURPOSE_INVOICES) {
            $this->documentAudit->recordInvoicesSecureLinkOpened(
                request: $request,
                presentedToken: $token,
                outcome: AuditOutcome::SUCCEEDED,
                httpStatus: 200,
                resourceKey: (string) $audit['resource_id'],
                secureLinkRowId: $audit['secure_link_row_id'],
                stepUpRowId: $audit['step_up_row_id'],
                laboratoryPurchaseRowId: $audit['laboratory_purchase_row_id'],
                orderRowId: $audit['order_row_id'],
                invoiceRowId: $audit['invoice_row_id'],
                openNumber: $audit['open_number'],
                maxOpens: $audit['max_opens'],
            );
        }
    }
}
