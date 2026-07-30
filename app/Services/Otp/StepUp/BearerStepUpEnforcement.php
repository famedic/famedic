<?php

namespace App\Services\Otp\StepUp;

use App\Enums\P0aOtpPurpose;
use App\Http\Responses\ApiResponse;
use App\Models\OtpStepUpGrant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P0-B4 — Gradual step-up enforcement for Bearer PDF downloads.
 *
 * Grant is reusable within its TTL (not consumed per download).
 * Header: X-Step-Up-Grant: <grant_public_id>
 *
 * Effective flags:
 * - results: step_up_bearer_results_enabled OR master step_up_bearer_downloads_enabled
 * - invoices: step_up_bearer_invoices_enabled OR master step_up_bearer_downloads_enabled
 */
class BearerStepUpEnforcement
{
    public const HEADER = 'X-Step-Up-Grant';

    public function __construct(
        private readonly OtpStepUpGrantService $grantService,
    ) {
    }

    public static function isResultsEnforcementEnabled(): bool
    {
        return (bool) config('otp.p0a.flags.step_up_bearer_results_enabled', false)
            || (bool) config('otp.p0a.flags.step_up_bearer_downloads_enabled', false);
    }

    public static function isInvoicesEnforcementEnabled(): bool
    {
        return (bool) config('otp.p0a.flags.step_up_bearer_invoices_enabled', false)
            || (bool) config('otp.p0a.flags.step_up_bearer_downloads_enabled', false);
    }

    /**
     * @return JsonResponse|null Null when enforcement is off or grant is valid.
     */
    public function assertResultsGrant(Request $request, int $orderId): ?JsonResponse
    {
        if (! self::isResultsEnforcementEnabled()) {
            return null;
        }

        return $this->assertGrant(
            $request,
            P0aOtpPurpose::StepUpResults->value,
            OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
            $orderId,
        );
    }

    /**
     * @return JsonResponse|null Null when enforcement is off or grant is valid.
     */
    public function assertInvoicesGrant(Request $request, int $invoiceId): ?JsonResponse
    {
        if (! self::isInvoicesEnforcementEnabled()) {
            return null;
        }

        return $this->assertGrant(
            $request,
            P0aOtpPurpose::StepUpInvoices->value,
            OtpStepUpGrant::RESOURCE_INVOICE,
            $invoiceId,
        );
    }

    /**
     * @return JsonResponse|null
     */
    private function assertGrant(
        Request $request,
        string $purpose,
        string $resourceType,
        int $resourceId,
    ): ?JsonResponse {
        $header = trim((string) $request->header(self::HEADER, ''));

        if ($header === '') {
            return ApiResponse::error(
                'STEP_UP_REQUIRED',
                'Se requiere verificacion step-up para descargar este documento.',
                403,
            );
        }

        $tokenId = $this->grantService->resolvePersonalAccessTokenId(
            $request->user()?->currentAccessToken(),
        );

        if ($this->grantService->bindToSanctumToken() && $tokenId === null) {
            return ApiResponse::error(
                'STEP_UP_GRANT_INVALID',
                'El grant de step-up no es valido.',
                403,
            );
        }

        $grant = OtpStepUpGrant::query()->where('public_id', $header)->first();

        if ($grant === null
            || ! $this->grantService->matchesBinding(
                $grant,
                (int) $request->user()->id,
                $purpose,
                $resourceType,
                $resourceId,
                $tokenId,
            )
        ) {
            return ApiResponse::error(
                'STEP_UP_GRANT_INVALID',
                'El grant de step-up no es valido.',
                403,
            );
        }

        if ($grant->isRevoked()) {
            return ApiResponse::error(
                'STEP_UP_REVOKED',
                'El grant de step-up fue revocado.',
                403,
            );
        }

        if ($grant->isExpired()) {
            return ApiResponse::error(
                'STEP_UP_EXPIRED',
                'El grant de step-up ha expirado.',
                403,
            );
        }

        // Grant remains reusable for further Bearer downloads within TTL.
        return null;
    }
}
