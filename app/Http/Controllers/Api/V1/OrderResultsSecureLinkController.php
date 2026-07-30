<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Otp\SecureDownloadLinkException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Orders\ResultsSecureLinkRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Otp\StepUp\OtpSecureDownloadLinkService;
use App\Services\Otp\StepUp\OtpStepUpGrantService;
use Illuminate\Http\JsonResponse;

class OrderResultsSecureLinkController extends Controller
{
    public function __construct(
        private readonly OtpSecureDownloadLinkService $secureLinkService,
        private readonly OtpStepUpGrantService $grantService,
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
            return ApiResponse::error(
                'ORDER_NOT_FOUND',
                'Pedido no encontrado.',
                404,
            );
        }

        $tokenId = $this->grantService->resolvePersonalAccessTokenId(
            $request->user()->currentAccessToken(),
        );

        if ($this->grantService->bindToSanctumToken() && $tokenId === null) {
            return ApiResponse::error(
                'STEP_UP_GRANT_INVALID',
                'El grant de step-up no es valido.',
                422,
            );
        }

        try {
            $payload = $this->secureLinkService->issueResultsLink(
                $request->user(),
                $order,
                $request->validated('grant_id'),
                $tokenId,
            );
        } catch (SecureDownloadLinkException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return ApiResponse::success($payload, null, 201);
    }
}
