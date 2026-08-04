<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Otp\OtpChallengeException;
use App\Exceptions\Otp\OtpConfigurationException;
use App\Exceptions\Otp\OtpIdentityNormalizationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Orders\InvoicesStepUpRequestRequest;
use App\Http\Requests\Api\V1\Orders\InvoicesStepUpVerifyRequest;
use App\Http\Responses\Api\V1\OtpExceptionHttpMapper;
use App\Http\Responses\ApiResponse;
use App\Models\OtpStepUpGrant;
use App\Services\Api\V1\Audit\AuditOutcome;
use App\Services\Api\V1\Audit\AuthOtpAuditRecorder;
use App\Services\Otp\StepUp\AkubicaStepUpOtpService;
use App\Services\Otp\StepUp\OtpStepUpGrantService;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\PersonalAccessToken;

class OrderInvoicesStepUpController extends Controller
{
    public function __construct(
        private readonly AkubicaStepUpOtpService $stepUpOtpService,
        private readonly OtpStepUpGrantService $grantService,
        private readonly OtpExceptionHttpMapper $otpExceptionHttpMapper,
        private readonly AuthOtpAuditRecorder $authOtpAudit,
    ) {
    }

    public function request(InvoicesStepUpRequestRequest $request, int $orderId, int $invoiceId): JsonResponse
    {
        if (! AkubicaStepUpOtpService::isInvoicesEnabled()) {
            return ApiResponse::error(
                'FEATURE_DISABLED',
                'El step-up OTP de facturas no esta habilitado.',
                503,
            );
        }

        try {
            $this->stepUpOtpService->assertInvoicesConfigurationReady();

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
                $this->authOtpAudit->recordStepUpRequested(
                    request: $request,
                    stepUpPurpose: AuthOtpAuditRecorder::STEP_UP_PURPOSE_INVOICES,
                    outcome: AuditOutcome::REJECTED,
                    httpStatus: 404,
                    errorCode: 'ORDER_NOT_FOUND',
                    resourceType: OtpStepUpGrant::RESOURCE_INVOICE,
                    resourceKey: (string) $invoiceId,
                    orderRowId: $orderId,
                    laboratoryPurchaseRowId: $orderId,
                    invoiceRowId: $invoiceId,
                );

                return $response;
            }

            $invoice = $this->stepUpOtpService->findOwnedInvoice($order, $invoiceId);
            if ($invoice === null) {
                $response = ApiResponse::error(
                    'INVOICE_NOT_FOUND',
                    'Factura no encontrada.',
                    404,
                );
                $this->authOtpAudit->recordStepUpRequested(
                    request: $request,
                    stepUpPurpose: AuthOtpAuditRecorder::STEP_UP_PURPOSE_INVOICES,
                    outcome: AuditOutcome::REJECTED,
                    httpStatus: 404,
                    errorCode: 'INVOICE_NOT_FOUND',
                    resourceType: OtpStepUpGrant::RESOURCE_INVOICE,
                    resourceKey: (string) $invoiceId,
                    orderRowId: (int) $order->id,
                    laboratoryPurchaseRowId: (int) $order->id,
                    invoiceRowId: $invoiceId,
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

            $payload = $this->stepUpOtpService->requestInvoicesStepUp(
                $request->user(),
                $order,
                $invoice,
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
                stepUpPurpose: AuthOtpAuditRecorder::STEP_UP_PURPOSE_INVOICES,
                outcome: $classified['outcome'],
                httpStatus: $classified['http_status'],
                errorCode: $classified['error_code'],
                resourceType: OtpStepUpGrant::RESOURCE_INVOICE,
                resourceKey: (string) $invoiceId,
                orderRowId: $orderId,
                laboratoryPurchaseRowId: $orderId,
                invoiceRowId: $invoiceId,
            );

            return $response;
        }

        $this->authOtpAudit->recordStepUpRequested(
            request: $request,
            stepUpPurpose: AuthOtpAuditRecorder::STEP_UP_PURPOSE_INVOICES,
            outcome: AuditOutcome::SUCCEEDED,
            httpStatus: 202,
            resourceType: OtpStepUpGrant::RESOURCE_INVOICE,
            resourceKey: (string) $invoice->id,
            challengePublicId: is_string($payload['challenge_id'] ?? null) ? $payload['challenge_id'] : null,
            orderRowId: (int) $order->id,
            laboratoryPurchaseRowId: (int) $order->id,
            invoiceRowId: (int) $invoice->id,
        );

        return ApiResponse::success($payload, null, 202);
    }

    public function verify(InvoicesStepUpVerifyRequest $request, int $orderId, int $invoiceId): JsonResponse
    {
        if (! AkubicaStepUpOtpService::isInvoicesEnabled()) {
            return ApiResponse::error(
                'FEATURE_DISABLED',
                'El step-up OTP de facturas no esta habilitado.',
                503,
            );
        }

        $challengePublicId = (string) $request->validated('challenge_id');

        try {
            $this->stepUpOtpService->assertInvoicesConfigurationReady();

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
                    stepUpPurpose: AuthOtpAuditRecorder::STEP_UP_PURPOSE_INVOICES,
                    outcome: AuditOutcome::REJECTED,
                    httpStatus: 404,
                    errorCode: 'ORDER_NOT_FOUND',
                    resourceType: OtpStepUpGrant::RESOURCE_INVOICE,
                    resourceKey: (string) $invoiceId,
                    challengePublicId: $challengePublicId,
                    orderRowId: $orderId,
                    laboratoryPurchaseRowId: $orderId,
                    invoiceRowId: $invoiceId,
                );

                return $response;
            }

            $invoice = $this->stepUpOtpService->findOwnedInvoice($order, $invoiceId);
            if ($invoice === null) {
                $response = ApiResponse::error(
                    'INVOICE_NOT_FOUND',
                    'Factura no encontrada.',
                    404,
                );
                $this->authOtpAudit->recordStepUpVerified(
                    request: $request,
                    stepUpPurpose: AuthOtpAuditRecorder::STEP_UP_PURPOSE_INVOICES,
                    outcome: AuditOutcome::REJECTED,
                    httpStatus: 404,
                    errorCode: 'INVOICE_NOT_FOUND',
                    resourceType: OtpStepUpGrant::RESOURCE_INVOICE,
                    resourceKey: (string) $invoiceId,
                    challengePublicId: $challengePublicId,
                    orderRowId: (int) $order->id,
                    laboratoryPurchaseRowId: (int) $order->id,
                    invoiceRowId: $invoiceId,
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

            if ($tokenId !== null
                && ! PersonalAccessToken::query()->whereKey($tokenId)->exists()
            ) {
                return ApiResponse::error(
                    'UNAUTHENTICATED',
                    'No autenticado.',
                    401,
                );
            }

            $payload = $this->stepUpOtpService->verifyInvoicesStepUp(
                $request->user(),
                $order,
                $invoice,
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
                stepUpPurpose: AuthOtpAuditRecorder::STEP_UP_PURPOSE_INVOICES,
                outcome: $classified['outcome'],
                httpStatus: $classified['http_status'],
                errorCode: $classified['error_code'],
                resourceType: OtpStepUpGrant::RESOURCE_INVOICE,
                resourceKey: (string) $invoiceId,
                challengePublicId: $challengePublicId,
                orderRowId: $orderId,
                laboratoryPurchaseRowId: $orderId,
                invoiceRowId: $invoiceId,
            );

            return $response;
        }

        $this->authOtpAudit->recordStepUpVerified(
            request: $request,
            stepUpPurpose: AuthOtpAuditRecorder::STEP_UP_PURPOSE_INVOICES,
            outcome: AuditOutcome::SUCCEEDED,
            httpStatus: 200,
            resourceType: OtpStepUpGrant::RESOURCE_INVOICE,
            resourceKey: (string) $invoice->id,
            challengePublicId: $challengePublicId,
            grantPublicId: is_string($payload['grant_id'] ?? null) ? $payload['grant_id'] : null,
            orderRowId: (int) $order->id,
            laboratoryPurchaseRowId: (int) $order->id,
            invoiceRowId: (int) $invoice->id,
        );

        return ApiResponse::success($payload);
    }
}
