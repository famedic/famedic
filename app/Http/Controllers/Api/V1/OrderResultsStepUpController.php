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
use App\Models\OtpStepUpGrant;
use App\Services\Api\V1\Audit\AuditOutcome;
use App\Services\Api\V1\Audit\AuthOtpAuditRecorder;
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
        private readonly AuthOtpAuditRecorder $authOtpAudit,
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
                $response = ApiResponse::error(
                    'ORDER_NOT_FOUND',
                    'Pedido no encontrado.',
                    404,
                );
                // Attempted id only — does not assert existence of foreign resources.
                $this->authOtpAudit->recordStepUpRequested(
                    request: $request,
                    stepUpPurpose: AuthOtpAuditRecorder::STEP_UP_PURPOSE_RESULTS,
                    outcome: AuditOutcome::REJECTED,
                    httpStatus: 404,
                    errorCode: 'ORDER_NOT_FOUND',
                    resourceType: OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
                    resourceKey: (string) $orderId,
                    orderRowId: $orderId,
                    laboratoryPurchaseRowId: $orderId,
                );

                return $response;
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
            $response = $this->otpExceptionHttpMapper->toResponse($e);
            $classified = $this->authOtpAudit->classifyErrorResponse($response);
            $this->authOtpAudit->recordStepUpRequested(
                request: $request,
                stepUpPurpose: AuthOtpAuditRecorder::STEP_UP_PURPOSE_RESULTS,
                outcome: $classified['outcome'],
                httpStatus: $classified['http_status'],
                errorCode: $classified['error_code'],
                resourceType: OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
                resourceKey: (string) $orderId,
                orderRowId: $orderId,
                laboratoryPurchaseRowId: $orderId,
            );

            return $response;
        }

        $this->authOtpAudit->recordStepUpRequested(
            request: $request,
            stepUpPurpose: AuthOtpAuditRecorder::STEP_UP_PURPOSE_RESULTS,
            outcome: AuditOutcome::SUCCEEDED,
            httpStatus: 202,
            resourceType: OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
            resourceKey: (string) $order->id,
            challengePublicId: is_string($payload['challenge_id'] ?? null) ? $payload['challenge_id'] : null,
            orderRowId: (int) $order->id,
            laboratoryPurchaseRowId: (int) $order->id,
        );

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

        $challengePublicId = (string) $request->validated('challenge_id');

        try {
            $this->stepUpOtpService->assertResultsConfigurationReady();

            $order = $this->stepUpOtpService->findOwnedOrder(
                $request->user()->customer,
                $orderId,
            );

            if ($order === null) {
                $response = ApiResponse::error(
                    'ORDER_NOT_FOUND',
                    'Pedido no encontrado.',
                    404,
                );
                $this->authOtpAudit->recordStepUpVerified(
                    request: $request,
                    stepUpPurpose: AuthOtpAuditRecorder::STEP_UP_PURPOSE_RESULTS,
                    outcome: AuditOutcome::REJECTED,
                    httpStatus: 404,
                    errorCode: 'ORDER_NOT_FOUND',
                    resourceType: OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
                    resourceKey: (string) $orderId,
                    challengePublicId: $challengePublicId,
                    orderRowId: $orderId,
                    laboratoryPurchaseRowId: $orderId,
                );

                return $response;
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
                $challengePublicId,
                $request->validated('code'),
                $tokenId,
                $request->ip(),
            );
        } catch (OtpConfigurationException|OtpChallengeException $e) {
            $response = $this->otpExceptionHttpMapper->toResponse($e);
            $classified = $this->authOtpAudit->classifyErrorResponse($response);
            $this->authOtpAudit->recordStepUpVerified(
                request: $request,
                stepUpPurpose: AuthOtpAuditRecorder::STEP_UP_PURPOSE_RESULTS,
                outcome: $classified['outcome'],
                httpStatus: $classified['http_status'],
                errorCode: $classified['error_code'],
                resourceType: OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
                resourceKey: (string) $orderId,
                challengePublicId: $challengePublicId,
                orderRowId: $orderId,
                laboratoryPurchaseRowId: $orderId,
            );

            return $response;
        }

        $this->authOtpAudit->recordStepUpVerified(
            request: $request,
            stepUpPurpose: AuthOtpAuditRecorder::STEP_UP_PURPOSE_RESULTS,
            outcome: AuditOutcome::SUCCEEDED,
            httpStatus: 200,
            resourceType: OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
            resourceKey: (string) $order->id,
            challengePublicId: $challengePublicId,
            grantPublicId: is_string($payload['grant_id'] ?? null) ? $payload['grant_id'] : null,
            orderRowId: (int) $order->id,
            laboratoryPurchaseRowId: (int) $order->id,
        );

        return ApiResponse::success($payload);
    }
}
