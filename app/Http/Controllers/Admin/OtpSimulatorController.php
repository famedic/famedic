<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LaboratoryPurchase;
use App\Models\OtpAccessLog;
use App\Models\OtpCode;
use App\Notifications\LaboratoryResultsOtpNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class OtpSimulatorController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function show(Request $request): Response
    {
        $this->ensureSimulatorAccess($request);

        $purchases = LaboratoryPurchase::query()
            ->with(['customer.user:id,name,paternal_lastname,maternal_lastname,email'])
            ->latest('id')
            ->limit(40)
            ->get(['id', 'customer_id', 'gda_order_id', 'created_at'])
            ->map(function (LaboratoryPurchase $purchase) {
                $user = $purchase->customer?->user;
                $customerLabel = $user
                    ? trim("{$user->name} {$user->paternal_lastname} {$user->maternal_lastname}")
                    : ('Cliente #'.$purchase->customer_id);

                return [
                    'id' => $purchase->id,
                    'gda_order_id' => $purchase->gda_order_id,
                    'created_at' => $purchase->formatted_created_at ?? $purchase->created_at?->format('d/m/Y H:i'),
                    'customer_label' => $customerLabel !== '' ? $customerLabel : ($user?->email ?? 'Cliente #'.$purchase->customer_id),
                ];
            });

        return Inertia::render('Admin/Simulators/Otp', [
            'purchases' => $purchases,
            'labResultsOtpRequired' => (bool) config('laboratory-results.otp_required'),
            'resendSeconds' => max(1, (int) config('laboratory-results.resend_seconds', 60)),
            'trustMinutes' => max(1, (int) config('laboratory-results.otp_trust_session_minutes', 15)),
        ]);
    }

    public function status(Request $request, LaboratoryPurchase $laboratoryPurchase): JsonResponse
    {
        $this->ensureSimulatorAccess($request);

        return response()->json([
            'verified' => false,
            'expires_in' => 0,
            'trust_minutes' => (int) config('laboratory-results.otp_trust_session_minutes', 15),
            'simulator' => true,
            'purchase_id' => $laboratoryPurchase->id,
        ]);
    }

    public function send(Request $request, LaboratoryPurchase $laboratoryPurchase): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['required', 'in:sms,email'],
        ]);

        $user = $this->ensureSimulatorAccess($request);

        if ($validated['channel'] === OtpCode::CHANNEL_EMAIL && empty($user->email)) {
            return response()->json(['message' => 'Tu usuario no tiene correo registrado para la prueba.'], 422);
        }

        if ($validated['channel'] === OtpCode::CHANNEL_SMS && empty($user->phone)) {
            return response()->json(['message' => 'Tu usuario no tiene teléfono registrado para la prueba.'], 422);
        }

        $otp = $this->issueOtp((int) $user->id, $laboratoryPurchase->id, $validated['channel']);

        try {
            $user->notify(new LaboratoryResultsOtpNotification($otp['plain_code'], $validated['channel']));
        } catch (\Throwable $e) {
            Log::error('simulator_otp_send_failed', [
                'admin_user_id' => $user->id,
                'purchase_id' => $laboratoryPurchase->id,
                'channel' => $validated['channel'],
                'otp_id' => $otp['otp_id'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $validated['channel'] === OtpCode::CHANNEL_SMS
                    ? 'No se pudo enviar el SMS de prueba. Intenta por correo.'
                    : 'No se pudo enviar el correo de prueba. Intenta por SMS.',
            ], 503);
        }

        $this->persistAccessLog('simulator_otp_requested', (int) $user->id, $laboratoryPurchase->id, $validated['channel'], [
            'otp_id' => $otp['otp_id'],
        ]);

        $expiresIn = $this->otpExpiresInSeconds($otp['expires_at']);

        return response()->json([
            'sent' => true,
            'channel' => $validated['channel'],
            'expires_in' => $expiresIn,
            'resend_in' => $this->resendCooldownSeconds(),
            'max_attempts' => self::MAX_ATTEMPTS,
            'trust_minutes' => (int) config('laboratory-results.otp_trust_session_minutes', 15),
            'simulator' => true,
        ]);
    }

    public function resend(Request $request, LaboratoryPurchase $laboratoryPurchase): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['required', 'in:sms,email'],
        ]);

        $user = $this->ensureSimulatorAccess($request);

        $latestOtp = OtpCode::query()
            ->where('user_id', $user->id)
            ->where('laboratory_purchase_id', $laboratoryPurchase->id)
            ->latest('id')
            ->first();

        $cooldown = $this->resendCooldownSeconds();
        if ($latestOtp && $this->secondsSince($latestOtp->created_at) < $cooldown) {
            $remaining = $cooldown - $this->secondsSince($latestOtp->created_at);

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

        $user = $this->ensureSimulatorAccess($request);
        $userId = (int) $user->id;

        $otp = OtpCode::query()
            ->where('user_id', $userId)
            ->where('laboratory_purchase_id', $laboratoryPurchase->id)
            ->where('status', OtpCode::STATUS_PENDING)
            ->latest('id')
            ->first();

        if (! $otp) {
            $this->persistAccessLog('simulator_otp_verify_failed', $userId, $laboratoryPurchase->id, null, [
                'reason' => 'no_active_code',
            ]);

            return response()->json(['message' => 'No hay un código activo. Pide que te envíen uno nuevo.'], 422);
        }

        if ($otp->expires_at && $otp->expires_at->isPast()) {
            $otp->update(['status' => OtpCode::STATUS_EXPIRED]);
            $this->persistAccessLog('simulator_otp_verify_failed', $userId, $laboratoryPurchase->id, $otp->channel, [
                'reason' => 'expired',
                'otp_id' => $otp->id,
            ]);

            return response()->json(['message' => 'El código expiró. Solicita uno nuevo.'], 422);
        }

        if ((int) $otp->attempts >= self::MAX_ATTEMPTS) {
            $otp->update(['status' => OtpCode::STATUS_FAILED]);
            $this->persistAccessLog('simulator_otp_attempts_exhausted', $userId, $laboratoryPurchase->id, $otp->channel, [
                'otp_id' => $otp->id,
            ]);

            return response()->json(['message' => 'Se agotaron los intentos. Solicita un código nuevo.'], 422);
        }

        $ok = Hash::check($validated['code'], (string) $otp->code);
        if (! $ok) {
            $otp->increment('attempts');
            $remaining = max(0, self::MAX_ATTEMPTS - ((int) $otp->attempts));
            $this->persistAccessLog('simulator_otp_verify_failed', $userId, $laboratoryPurchase->id, $otp->channel, [
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

        $this->persistAccessLog('simulator_otp_verified', $userId, $laboratoryPurchase->id, $otp->channel, [
            'otp_id' => $otp->id,
        ]);

        return response()->json([
            'verified' => true,
            'simulator' => true,
            'message' => 'Código verificado correctamente en el simulador. No se otorgó acceso a resultados del paciente.',
            'trust_minutes' => (int) config('laboratory-results.otp_trust_session_minutes', 15),
        ]);
    }

    private function ensureSimulatorAccess(Request $request)
    {
        $request->user()->administrator->hasPermissionTo('simulators.manage') || abort(403);

        return $request->user();
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
            $expiryMinutes = max(1, (int) config('otp.expiry', 10));
            $expiresAt = now()->addMinutes($expiryMinutes);

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

    private function resendCooldownSeconds(): int
    {
        return max(1, (int) config('laboratory-results.resend_seconds', 60));
    }

    private function otpExpiresInSeconds(\DateTimeInterface $expiresAt): int
    {
        return (int) max(0, $expiresAt->getTimestamp() - now()->getTimestamp());
    }

    private function secondsSince(\DateTimeInterface $moment): int
    {
        return (int) max(0, now()->getTimestamp() - $moment->getTimestamp());
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
                'meta' => array_merge(['simulator' => true], $meta) ?: null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('simulator_otp_access_log_write_failed', ['error' => $e->getMessage()]);
        }
    }
}
