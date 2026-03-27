<?php

namespace App\Http\Controllers;

use App\Models\LaboratoryPurchase;
use App\Models\OtpAccessLog;
use App\Models\OtpCode;
use App\Notifications\LaboratoryResultsOtpNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class LaboratoryResultsOtpController extends Controller
{
    private const SESSION_MINUTES = 15;

    private const RESEND_SECONDS = 30;

    private const MAX_ATTEMPTS = 5;

    public function status(Request $request, LaboratoryPurchase $laboratoryPurchase): JsonResponse
    {
        $userId = (int) $request->user()->id;

        if (! $this->ownsPurchase($laboratoryPurchase, $userId)) {
            abort(403);
        }

        $verifiedAt = $request->session()->get($this->sessionKey($laboratoryPurchase->id));
        if (! $verifiedAt) {
            return response()->json(['verified' => false, 'expires_in' => 0]);
        }

        $verifiedAtTs = is_numeric($verifiedAt) ? (int) $verifiedAt : strtotime((string) $verifiedAt);
        if (! $verifiedAtTs) {
            return response()->json(['verified' => false, 'expires_in' => 0]);
        }

        $expiresAtTs = $verifiedAtTs + (self::SESSION_MINUTES * 60);
        $expiresIn = max(0, $expiresAtTs - time());

        return response()->json([
            'verified' => $expiresIn > 0,
            'expires_in' => $expiresIn,
        ]);
    }

    public function send(Request $request, LaboratoryPurchase $laboratoryPurchase): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['required', 'in:sms,email'],
        ]);

        $user = $request->user();
        $userId = (int) $user->id;

        if (! $this->ownsPurchase($laboratoryPurchase, $userId)) {
            abort(403);
        }

        if ($validated['channel'] === OtpCode::CHANNEL_EMAIL && empty($user->email)) {
            return response()->json(['message' => 'No hay un correo registrado para enviarte el código.'], 422);
        }

        if ($validated['channel'] === OtpCode::CHANNEL_SMS && empty($user->phone)) {
            return response()->json(['message' => 'No hay un teléfono registrado para enviarte el código.'], 422);
        }

        $otp = $this->issueOtp($userId, $laboratoryPurchase->id, $validated['channel']);
        $user->notify(new LaboratoryResultsOtpNotification($otp['plain_code'], $validated['channel']));

        $this->persistAccessLog('otp_requested_modal', $userId, $laboratoryPurchase->id, $validated['channel'], [
            'otp_id' => $otp['otp_id'],
        ]);

        Log::info('lab_results_otp_requested_modal', [
            'user_id' => $userId,
            'purchase_id' => $laboratoryPurchase->id,
            'channel' => $validated['channel'],
            'otp_id' => $otp['otp_id'],
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'sent' => true,
            'channel' => $validated['channel'],
            'expires_in' => max(0, now()->diffInSeconds($otp['expires_at'], false)),
            'resend_in' => self::RESEND_SECONDS,
            'max_attempts' => self::MAX_ATTEMPTS,
        ]);
    }

    public function resend(Request $request, LaboratoryPurchase $laboratoryPurchase): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['required', 'in:sms,email'],
        ]);

        $user = $request->user();
        $userId = (int) $user->id;

        if (! $this->ownsPurchase($laboratoryPurchase, $userId)) {
            abort(403);
        }

        $latestOtp = OtpCode::query()
            ->where('user_id', $userId)
            ->where('laboratory_purchase_id', $laboratoryPurchase->id)
            ->latest('id')
            ->first();

        if ($latestOtp && now()->diffInSeconds($latestOtp->created_at) < self::RESEND_SECONDS) {
            $remaining = self::RESEND_SECONDS - (int) now()->diffInSeconds($latestOtp->created_at);

            return response()->json([
                'sent' => false,
                'message' => 'Aún no puedes reenviar el código.',
                'resend_in' => max(1, $remaining),
            ], 429);
        }

        return $this->send($request, $laboratoryPurchase);
    }

    public function verify(Request $request, LaboratoryPurchase $laboratoryPurchase): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $userId = (int) $request->user()->id;

        if (! $this->ownsPurchase($laboratoryPurchase, $userId)) {
            abort(403);
        }

        $otp = OtpCode::query()
            ->where('user_id', $userId)
            ->where('laboratory_purchase_id', $laboratoryPurchase->id)
            ->where('status', OtpCode::STATUS_PENDING)
            ->latest('id')
            ->first();

        if (! $otp) {
            $this->persistAccessLog('otp_verify_failed_modal', $userId, $laboratoryPurchase->id, null, [
                'reason' => 'no_active_code',
            ]);

            return response()->json(['message' => 'No hay un código activo. Pide que te envíen uno nuevo.'], 422);
        }

        if ($otp->expires_at && $otp->expires_at->isPast()) {
            $otp->update(['status' => OtpCode::STATUS_EXPIRED]);
            $this->persistAccessLog('otp_verify_failed_modal', $userId, $laboratoryPurchase->id, $otp->channel, [
                'reason' => 'expired',
                'otp_id' => $otp->id,
            ]);

            return response()->json(['message' => 'El código expiró. Solicita uno nuevo.'], 422);
        }

        if ((int) $otp->attempts >= self::MAX_ATTEMPTS) {
            $otp->update(['status' => OtpCode::STATUS_FAILED]);
            $this->persistAccessLog('attempts_exhausted_modal', $userId, $laboratoryPurchase->id, $otp->channel, [
                'otp_id' => $otp->id,
            ]);

            return response()->json(['message' => 'Se agotaron los intentos. Solicita un código nuevo.'], 422);
        }

        $ok = Hash::check($validated['code'], (string) $otp->code);
        if (! $ok) {
            $otp->increment('attempts');

            $remaining = max(0, self::MAX_ATTEMPTS - ((int) $otp->attempts));
            $this->persistAccessLog('otp_verify_failed_modal', $userId, $laboratoryPurchase->id, $otp->channel, [
                'reason' => 'invalid_code',
                'otp_id' => $otp->id,
                'remaining_attempts' => $remaining,
            ]);

            return response()->json([
                'message' => $remaining > 0
                    ? "Código incorrecto. Te quedan {$remaining} intentos."
                    : 'Se agotaron los intentos. Solicita un código nuevo.',
                'remaining_attempts' => $remaining,
            ], 422);
        }

        $otp->update([
            'status' => OtpCode::STATUS_VERIFIED,
            'verified_at' => now(),
        ]);

        $request->session()->put($this->sessionKey($laboratoryPurchase->id), now()->timestamp);

        $this->persistAccessLog('otp_verified_modal', $userId, $laboratoryPurchase->id, $otp->channel, [
            'otp_id' => $otp->id,
        ]);

        return response()->json([
            'verified' => true,
            'expires_in' => self::SESSION_MINUTES * 60,
        ]);
    }

    private function issueOtp(int $userId, int $purchaseId, string $channel): array
    {
        return DB::transaction(function () use ($userId, $purchaseId, $channel) {
            OtpCode::query()
                ->where('user_id', $userId)
                ->where('laboratory_purchase_id', $purchaseId)
                ->where('status', OtpCode::STATUS_PENDING)
                ->update(['status' => OtpCode::STATUS_EXPIRED]);

            $plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiresAt = now()->addMinutes((int) config('otp.expiry', 10));

            $row = OtpCode::query()->create([
                'user_id' => $userId,
                'laboratory_purchase_id' => $purchaseId,
                'channel' => $channel,
                'code' => Hash::make($plainCode),
                'expires_at' => $expiresAt,
                'attempts' => 0,
                'status' => OtpCode::STATUS_PENDING,
            ]);

            return [
                'plain_code' => $plainCode,
                'expires_at' => $expiresAt,
                'otp_id' => $row->id,
            ];
        });
    }

    private function sessionKey(int $purchaseId): string
    {
        return "otp_verified_at:lab_results:purchase:{$purchaseId}";
    }

    private function ownsPurchase(LaboratoryPurchase $purchase, int $userId): bool
    {
        // Compatibilidad: algunas compras cuelgan de customer->user; preferimos el customer_id del usuario.
        $customerId = auth()->user()?->customer?->id;
        if ($customerId && (int) $purchase->customer_id === (int) $customerId) {
            return true;
        }

        return (int) ($purchase->customer?->user_id ?? 0) === $userId;
    }

    private function persistAccessLog(
        string $event,
        ?int $userId,
        ?int $purchaseId,
        ?string $channel,
        array $meta = []
    ): void {
        try {
            OtpAccessLog::query()->create([
                'user_id' => $userId,
                'laboratory_purchase_id' => $purchaseId,
                'event' => $event,
                'channel' => $channel,
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 2000),
                'meta' => $meta ?: null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('otp_access_log_write_failed', ['error' => $e->getMessage()]);
        }
    }
}
