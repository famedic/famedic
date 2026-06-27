<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\CouponAssignOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CouponCreationOtpController extends Controller
{
    public function __construct(
        private CouponAssignOtpService $couponAssignOtpService,
    ) {}

    public function send(Request $request): JsonResponse
    {
        $this->authorize('create', Coupon::class);

        if (! $this->couponAssignOtpService->isRequired()) {
            return response()->json(['required' => false]);
        }

        $validated = $request->validate([
            'channel' => ['required', 'in:sms,email'],
            'assign_payload' => ['required', 'array'],
        ]);

        $this->assertValidCreationPayload($validated['assign_payload']);

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

        try {
            $result = $this->couponAssignOtpService->send(
                $user,
                $validated['channel'],
                $validated['assign_payload'],
            );
        } catch (\Throwable $e) {
            Log::error('coupon_creation_otp_send_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo enviar el código de verificación. Intenta de nuevo.',
            ], 503);
        }

        return response()->json([
            'required' => true,
            'sent' => true,
            ...$result,
            'max_attempts' => \App\Services\AdminOtpService::MAX_ATTEMPTS,
        ]);
    }

    public function resend(Request $request): JsonResponse
    {
        $this->authorize('create', Coupon::class);

        $validated = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'channel' => ['required', 'in:sms,email'],
            'assign_payload' => ['required', 'array'],
        ]);

        $this->assertValidCreationPayload($validated['assign_payload']);

        /** @var User $user */
        $user = $request->user();
        $challengeId = $validated['challenge_id'];

        $latest = $this->couponAssignOtpService->latestOtpForChallenge((int) $user->id, $challengeId);
        $remaining = $this->couponAssignOtpService->resendCooldownSeconds();
        if ($latest !== null) {
            $remaining = app(\App\Services\AdminOtpService::class)
                ->resendCooldownRemaining($latest, $this->couponAssignOtpService->resendCooldownSeconds());
        }

        if ($remaining > 0) {
            return response()->json([
                'message' => "Espera {$remaining} segundos antes de reenviar el código.",
                'resend_in' => $remaining,
            ], 429);
        }

        try {
            $result = $this->couponAssignOtpService->resend(
                $user,
                $validated['channel'],
                $validated['assign_payload'],
                $challengeId,
            );
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'No se pudo reenviar el código.',
            ], 503);
        }

        return response()->json([
            'sent' => true,
            ...$result,
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $this->authorize('create', Coupon::class);

        $validated = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $result = $this->couponAssignOtpService->verify(
                $user,
                $validated['challenge_id'],
                $validated['code'],
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertValidCreationPayload(array $payload): void
    {
        $isCouponCreation = ($payload['coupon_mode'] ?? '') === 'new';
        $isPromoCreation = filter_var($payload['promo_creation'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $isCouponCreation && ! $isPromoCreation) {
            throw ValidationException::withMessages([
                'assign_payload' => 'Payload de creación no válido.',
            ]);
        }
    }
}
