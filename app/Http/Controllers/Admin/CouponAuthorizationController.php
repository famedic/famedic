<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CouponApprovalRequest;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\CouponAuthorizationInboxService;
use App\Services\CouponAuthorizationOtpService;
use App\Services\CouponAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CouponAuthorizationController extends Controller
{
    public function __construct(
        private CouponAuthorizationInboxService $inboxService,
        private CouponAuthorizationService $authorizationService,
        private CouponAuthorizationOtpService $authorizationOtpService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $this->authorize('approveRequests', Coupon::class);
        $this->assertAuthorizerRole($request);

        return Inertia::render('Admin/Coupons/Authorizations/Index', [
            'items' => $this->inboxService->listItemsForAuthorizer($request->user()),
            'actionableCount' => $this->inboxService->actionableCountFor($request->user()),
        ]);
    }

    public function show(Request $request, Coupon $coupon): InertiaResponse|RedirectResponse
    {
        $this->authorize('approveRequests', Coupon::class);
        $this->assertAuthorizerRole($request);

        if ($coupon->parent_coupon_id !== null) {
            return redirect()->route('admin.coupons.authorizations.show', $coupon->parent_coupon_id);
        }

        $approvalRequestId = $request->filled('request') ? (int) $request->query('request') : null;
        $detail = $this->inboxService->detailForAuthorizer($request->user(), $coupon, $approvalRequestId);

        if ($detail === null) {
            abort(404);
        }

        return Inertia::render('Admin/Coupons/Authorizations/Show', [
            'authorization' => $detail,
            'otpConfig' => [
                'resend_seconds' => $this->authorizationOtpService->resendCooldownSeconds(),
                'max_attempts' => \App\Services\AdminOtpService::MAX_ATTEMPTS,
            ],
        ]);
    }

    public function sendApprovalOtp(Request $request, Coupon $coupon): JsonResponse
    {
        $this->authorize('approveRequests', Coupon::class);
        $this->assertAuthorizerRole($request);

        $validated = $request->validate([
            'channel' => ['required', 'in:sms,email'],
            'approval_request_id' => ['nullable', 'integer', 'exists:coupon_approval_requests,id'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if ($validated['channel'] === OtpCode::CHANNEL_EMAIL && empty($user->email)) {
            throw ValidationException::withMessages([
                'channel' => 'No hay un correo registrado para enviarte el código.',
            ]);
        }

        if ($validated['channel'] === OtpCode::CHANNEL_SMS && empty($user->phone)) {
            throw ValidationException::withMessages([
                'channel' => 'No hay un teléfono registrado para enviarte el código.',
            ]);
        }

        $approvalRequestId = isset($validated['approval_request_id'])
            ? (int) $validated['approval_request_id']
            : null;

        $detail = $this->inboxService->detailForAuthorizer($user, $coupon, $approvalRequestId);
        if ($detail === null || ! ($detail['i_can_approve'] ?? false)) {
            return response()->json(['message' => 'No puedes aprobar esta solicitud.'], 403);
        }

        try {
            $result = $this->authorizationOtpService->send(
                $user,
                $validated['channel'],
                (int) $coupon->id,
                $approvalRequestId,
            );
        } catch (\Throwable $e) {
            Log::error('coupon_authorization_otp_send_failed', [
                'user_id' => $user->id,
                'coupon_id' => $coupon->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo enviar el código de verificación. Intenta de nuevo.',
            ], 503);
        }

        return response()->json([
            'sent' => true,
            ...$result,
            'max_attempts' => \App\Services\AdminOtpService::MAX_ATTEMPTS,
        ]);
    }

    public function verifyApprovalOtp(Request $request, Coupon $coupon): JsonResponse
    {
        $this->authorize('approveRequests', Coupon::class);
        $this->assertAuthorizerRole($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
            'approval_request_id' => ['nullable', 'integer', 'exists:coupon_approval_requests,id'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $approvalRequestId = isset($validated['approval_request_id'])
            ? (int) $validated['approval_request_id']
            : null;

        try {
            $result = $this->authorizationOtpService->verify(
                $user,
                (int) $coupon->id,
                $approvalRequestId,
                $validated['code'],
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function approve(Request $request, Coupon $coupon): RedirectResponse
    {
        $this->authorize('approveRequests', Coupon::class);
        $this->assertAuthorizerRole($request);

        $validated = $request->validate([
            'otp_verification_token' => ['required', 'string'],
            'approval_request_id' => ['nullable', 'integer', 'exists:coupon_approval_requests,id'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            if (! empty($validated['approval_request_id'])) {
                $approvalRequest = CouponApprovalRequest::query()->findOrFail((int) $validated['approval_request_id']);
                $result = $this->authorizationService->approveAssignment(
                    $coupon,
                    $approvalRequest,
                    $user,
                    $validated['otp_verification_token'],
                );
            } else {
                $result = $this->authorizationService->approveMaster(
                    $coupon,
                    $user,
                    $validated['otp_verification_token'],
                );
            }
        } catch (\DomainException $e) {
            return redirect()->back()->flashMessage($e->getMessage(), 'error');
        }

        return redirect()
            ->route('admin.coupons.authorizations.index')
            ->flashMessage($result['message']);
    }

    public function reject(Request $request, Coupon $coupon): RedirectResponse
    {
        $this->authorize('approveRequests', Coupon::class);
        $this->assertAuthorizerRole($request);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'approval_request_id' => ['nullable', 'integer', 'exists:coupon_approval_requests,id'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            if (! empty($validated['approval_request_id'])) {
                $approvalRequest = CouponApprovalRequest::query()->findOrFail((int) $validated['approval_request_id']);
                $result = $this->authorizationService->rejectAssignment(
                    $coupon,
                    $approvalRequest,
                    $user,
                    $validated['reason'],
                );
            } else {
                $result = $this->authorizationService->rejectMaster(
                    $coupon,
                    $user,
                    $validated['reason'],
                );
            }
        } catch (\DomainException $e) {
            return redirect()->back()->flashMessage($e->getMessage(), 'error');
        }

        return redirect()
            ->route('admin.coupons.authorizations.index')
            ->flashMessage($result['message']);
    }

    private function assertAuthorizerRole(Request $request): void
    {
        $administrator = $request->user()->administrator;
        if (! $administrator || ! $administrator->hasRole('autorizador')) {
            abort(403);
        }
    }
}
