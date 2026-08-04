<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Otp\SecureDownloadLinkException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Orders\ResultsSecureLinkRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Api\V1\Audit\AuditOutcome;
use App\Services\Api\V1\Audit\DocumentAccessAuditRecorder;
use App\Services\Otp\StepUp\OtpSecureDownloadLinkService;
use App\Services\Otp\StepUp\OtpStepUpGrantService;
use Illuminate\Http\JsonResponse;

class OrderResultsSecureLinkController extends Controller
{
    public function __construct(
        private readonly OtpSecureDownloadLinkService $secureLinkService,
        private readonly OtpStepUpGrantService $grantService,
        private readonly DocumentAccessAuditRecorder $documentAudit,
    ) {
    }

    public function store(ResultsSecureLinkRequest $request, int $orderId): JsonResponse
    {
        if (! OtpSecureDownloadLinkService::isResultsEnabled()) {
            return ApiResponse::error(
                'FEATURE_DISABLED',
                'Las ligas seguras de resultados no estan habilitadas.',
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
            // Ownership reject: do not persist attempted foreign resource ids.
            $this->documentAudit->recordResultsSecureLinkCreated(
                request: $request,
                outcome: AuditOutcome::REJECTED,
                httpStatus: 404,
                errorCode: 'ORDER_NOT_FOUND',
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
            $this->documentAudit->recordResultsSecureLinkCreated(
                request: $request,
                outcome: AuditOutcome::REJECTED,
                httpStatus: 422,
                errorCode: 'STEP_UP_GRANT_INVALID',
                resourceKey: (string) $order->id,
                laboratoryPurchaseRowId: (int) $order->id,
                orderRowId: (int) $order->id,
            );

            return $response;
        }

        try {
            $issued = $this->secureLinkService->issueResultsLink(
                $request->user(),
                $order,
                $request->validated('grant_id'),
                $tokenId,
            );
        } catch (SecureDownloadLinkException $e) {
            $response = ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus);
            $classified = $this->documentAudit->classifyErrorResponse($response);
            $this->documentAudit->recordResultsSecureLinkCreated(
                request: $request,
                outcome: $classified['outcome'],
                httpStatus: $classified['http_status'],
                errorCode: $classified['error_code'],
                resourceKey: (string) $order->id,
                laboratoryPurchaseRowId: (int) $order->id,
                orderRowId: (int) $order->id,
            );

            return $response;
        }

        $audit = $issued['audit'];
        unset($issued['audit']);

        $this->documentAudit->recordResultsSecureLinkCreated(
            request: $request,
            outcome: AuditOutcome::SUCCEEDED,
            httpStatus: 201,
            resourceKey: (string) $order->id,
            secureLinkRowId: $audit['secure_link_row_id'],
            stepUpRowId: $audit['step_up_row_id'],
            laboratoryPurchaseRowId: (int) $order->id,
            orderRowId: (int) $order->id,
            ttlMinutes: $audit['ttl_minutes'],
            maxOpens: $audit['max_opens'],
        );

        return ApiResponse::success($issued, null, 201);
    }
}
