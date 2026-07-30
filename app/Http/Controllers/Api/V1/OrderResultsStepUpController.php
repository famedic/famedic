<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Otp\OtpChallengeException;
use App\Exceptions\Otp\OtpConfigurationException;
use App\Exceptions\Otp\OtpIdentityNormalizationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Orders\ResultsStepUpRequestRequest;
use App\Http\Requests\Api\V1\Orders\ResultsStepUpVerifyRequest;
use App\Http\Responses\Api\V1\OtpExceptionHttpMapper;
use App\Http\Responses\ApiResponse;
use App\Services\Otp\StepUp\AkubicaStepUpOtpService;
use App\Services\Otp\StepUp\OtpStepUpGrantService;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\PersonalAccessToken;

class OrderResultsStepUpController extends Controller
{
    public function __construct(
        private readonly AkubicaStepUpOtpService $stepUpOtpService,
        private readonly OtpStepUpGrantService $grantService,
        private readonly OtpExceptionHttpMapper $otpExceptionHttpMapper,
    ) {
    }

    public function request(ResultsStepUpRequestRequest $request, int $orderId): JsonResponse
    {
        if (! AkubicaStepUpOtpService::isResultsEnabled()) {
            return ApiResponse::error(
                'FEATURE_DISABLED',
                'El step-up OTP de resultados no esta habilitado.',
                503,
            );
        }

        try {
            $this->stepUpOtpService->assertResultsConfigurationReady();

            $order = $this->stepUpOtpService->findOwnedOrder(
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
                    'OTP_CONFIGURATION_INVALID',
                    'El step-up OTP no esta disponible.',
                    503,
                );
            }

            $payload = $this->stepUpOtpService->requestResultsStepUp(
                $request->user(),
                $order,
                $tokenId,
                $request->ip(),
            );
        } catch (OtpIdentityNormalizationException) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Los datos de step-up no son validos.',
                422,
            );
        } catch (OtpConfigurationException|OtpChallengeException $e) {
            return $this->otpExceptionHttpMapper->toResponse($e);
        }

        return ApiResponse::success($payload, null, 202);
    }

    public function verify(ResultsStepUpVerifyRequest $request, int $orderId): JsonResponse
    {
        if (! AkubicaStepUpOtpService::isResultsEnabled()) {
            return ApiResponse::error(
                'FEATURE_DISABLED',
                'El step-up OTP de resultados no esta habilitado.',
                503,
            );
        }

        try {
            $this->stepUpOtpService->assertResultsConfigurationReady();

            $order = $this->stepUpOtpService->findOwnedOrder(
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

            $accessToken = $request->user()->currentAccessToken();
            $tokenId = $this->grantService->resolvePersonalAccessTokenId($accessToken);

            if ($this->grantService->bindToSanctumToken() && $tokenId === null) {
                return ApiResponse::error(
                    'OTP_CONFIGURATION_INVALID',
                    'El step-up OTP no esta disponible.',
                    503,
                );
            }

            // Ensure the token row still exists when binding is required.
            if ($tokenId !== null
                && ! PersonalAccessToken::query()->whereKey($tokenId)->exists()
            ) {
                return ApiResponse::error(
                    'UNAUTHENTICATED',
                    'No autenticado.',
                    401,
                );
            }

            $payload = $this->stepUpOtpService->verifyResultsStepUp(
                $request->user(),
                $order,
                $request->validated('challenge_id'),
                $request->validated('code'),
                $tokenId,
                $request->ip(),
            );
        } catch (OtpConfigurationException|OtpChallengeException $e) {
            return $this->otpExceptionHttpMapper->toResponse($e);
        }

        return ApiResponse::success($payload);
    }
}
