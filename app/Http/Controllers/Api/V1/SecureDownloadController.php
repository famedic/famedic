<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Otp\SecureDownloadLinkException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Otp\StepUp\OtpSecureDownloadLinkService;
use App\Support\Api\V1\OrderDocumentDownloadSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class SecureDownloadController extends Controller
{
    public function __construct(
        private readonly OtpSecureDownloadLinkService $secureLinkService,
        private readonly OrderDocumentDownloadSupport $downloadSupport,
    ) {
    }

    public function show(string $token): Response|JsonResponse
    {
        if (! OtpSecureDownloadLinkService::isResultsEnabled()) {
            return ApiResponse::error(
                'FEATURE_DISABLED',
                'Las ligas seguras de resultados no estan habilitadas.',
                503,
            );
        }

        try {
            $resolved = $this->secureLinkService->consumeAndResolvePdf($token);
        } catch (SecureDownloadLinkException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return $this->downloadSupport->pdfResponse($resolved['content'], $resolved['filename']);
    }
}
