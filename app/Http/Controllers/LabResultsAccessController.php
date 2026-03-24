<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\GetGDAResultsAction;
use App\Models\LabResultAccessToken;
use App\Models\LaboratoryNotification;
use App\Models\OtpCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class LabResultsAccessController extends Controller
{
    private const MAX_ATTEMPTS = 5;
    private const RESEND_SECONDS = 60;

    public function show(string $token): Response|RedirectResponse
    {
        $context = $this->resolveTokenContext($token);
        if (! $context) {
            return $this->renderError('La liga de resultados no es válida o expiró.');
        }

        [$accessToken, $purchase, $user] = $context;

        if (! $this->orderHasResults($purchase->id)) {
            return $this->renderError('Los resultados aún no están disponibles para esta orden.');
        }

        $accessToken->update(['last_used_at' => now()]);

        // Si ya verificó OTP recientemente, permitir acceso directo.
        $verifiedOtp = OtpCode::query()
            ->where('user_id', $user->id)
            ->where('laboratory_purchase_id', $purchase->id)
            ->where('status', OtpCode::STATUS_VERIFIED)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($verifiedOtp) {
            $pdf = $this->getResultsPdfBase64($purchase->id);
            if (! $pdf) {
                return $this->renderError('No se pudo cargar el PDF de resultados en este momento.');
            }

            return Inertia::render('LaboratoryResults/OtpAccess', [
                'token' => $token,
                'expiresAt' => null,
                'resendAvailableAt' => now()->toIso8601String(),
                'maxAttempts' => self::MAX_ATTEMPTS,
                'attempts' => 0,
                'alreadyVerified' => true,
                'pdfBase64' => $pdf,
                'maskedPhone' => $this->maskPhone($user->phone?->formatE164()),
            ]);
        }

        $otp = $this->issueOtp($user->id, $purchase->id);
        $this->sendOtpSms($user, $otp['plain_code']);

        Log::info('Lab results OTP generated on token access', [
            'user_id' => $user->id,
            'purchase_id' => $purchase->id,
            'token_id' => $accessToken->id,
        ]);

        return Inertia::render('LaboratoryResults/OtpAccess', [
            'token' => $token,
            'expiresAt' => $otp['expires_at']->toIso8601String(),
            'resendAvailableAt' => now()->addSeconds(self::RESEND_SECONDS)->toIso8601String(),
            'maxAttempts' => self::MAX_ATTEMPTS,
            'attempts' => 0,
            'alreadyVerified' => false,
            'pdfBase64' => null,
            'maskedPhone' => $this->maskPhone($user->phone?->formatE164()),
        ]);
    }

    public function verify(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $context = $this->resolveTokenContext($validated['token']);
        if (! $context) {
            return back()->withErrors(['otp' => 'Token inválido o expirado.']);
        }

        [, $purchase, $user] = $context;

        $otp = OtpCode::query()
            ->where('user_id', $user->id)
            ->where('laboratory_purchase_id', $purchase->id)
            ->where('status', OtpCode::STATUS_PENDING)
            ->latest('id')
            ->first();

        if (! $otp) {
            return back()->withErrors(['otp' => 'No hay un código activo. Solicita reenviar código.']);
        }

        if ($otp->expires_at->isPast()) {
            $otp->update(['status' => OtpCode::STATUS_EXPIRED]);
            return back()->withErrors(['otp' => 'El código OTP expiró. Solicita uno nuevo.']);
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            $otp->update(['status' => OtpCode::STATUS_FAILED]);
            return back()->withErrors(['otp' => 'Se excedió el máximo de intentos. Solicita un nuevo código.']);
        }

        if (! Hash::check($validated['code'], $otp->code)) {
            $otp->increment('attempts');
            $otp->refresh();

            if ($otp->attempts >= self::MAX_ATTEMPTS) {
                $otp->update(['status' => OtpCode::STATUS_FAILED]);
                return back()->withErrors(['otp' => 'Código incorrecto. Máximo de intentos alcanzado.']);
            }

            return back()->withErrors([
                'otp' => 'Código incorrecto.',
                'attempts' => 'Intento ' . $otp->attempts . ' de ' . self::MAX_ATTEMPTS,
            ]);
        }

        $otp->update([
            'status' => OtpCode::STATUS_VERIFIED,
            'verified_at' => now(),
        ]);

        $pdf = $this->getResultsPdfBase64($purchase->id);
        if (! $pdf) {
            return back()->withErrors(['otp' => 'Código válido, pero no se pudo cargar el PDF. Intenta de nuevo.']);
        }

        Log::info('Lab results OTP verified', [
            'user_id' => $user->id,
            'purchase_id' => $purchase->id,
            'otp_id' => $otp->id,
        ]);

        return Inertia::render('LaboratoryResults/OtpAccess', [
            'token' => $validated['token'],
            'expiresAt' => null,
            'resendAvailableAt' => now()->toIso8601String(),
            'maxAttempts' => self::MAX_ATTEMPTS,
            'attempts' => $otp->attempts,
            'alreadyVerified' => true,
            'pdfBase64' => $pdf,
            'maskedPhone' => $this->maskPhone($user->phone?->formatE164()),
        ]);
    }

    public function resend(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $context = $this->resolveTokenContext($validated['token']);
        if (! $context) {
            return back()->withErrors(['otp' => 'Token inválido o expirado.']);
        }

        [, $purchase, $user] = $context;

        $latestOtp = OtpCode::query()
            ->where('user_id', $user->id)
            ->where('laboratory_purchase_id', $purchase->id)
            ->latest('id')
            ->first();

        if ($latestOtp && now()->diffInSeconds($latestOtp->created_at) < self::RESEND_SECONDS) {
            $remaining = self::RESEND_SECONDS - now()->diffInSeconds($latestOtp->created_at);
            return back()->withErrors(['otp' => 'Espera ' . $remaining . ' segundos para reenviar.']);
        }

        $otp = $this->issueOtp($user->id, $purchase->id);
        $this->sendOtpSms($user, $otp['plain_code']);

        Log::info('Lab results OTP resent', [
            'user_id' => $user->id,
            'purchase_id' => $purchase->id,
        ]);

        return back()->with('success', 'Se envió un nuevo código OTP por SMS.');
    }

    private function resolveTokenContext(string $plainToken): ?array
    {
        $token = LabResultAccessToken::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->with(['user', 'laboratoryPurchase'])
            ->first();

        if (! $token) {
            Log::warning('Lab results token not found', ['token_hash' => hash('sha256', $plainToken)]);
            return null;
        }

        if ($token->expires_at && $token->expires_at->isPast()) {
            Log::warning('Lab results token expired', ['token_id' => $token->id]);
            return null;
        }

        return [$token, $token->laboratoryPurchase, $token->user];
    }

    private function issueOtp(int $userId, int $purchaseId): array
    {
        return DB::transaction(function () use ($userId, $purchaseId) {
            OtpCode::query()
                ->where('user_id', $userId)
                ->where('laboratory_purchase_id', $purchaseId)
                ->where('status', OtpCode::STATUS_PENDING)
                ->update(['status' => OtpCode::STATUS_EXPIRED]);

            $plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiresAt = now()->addMinutes((int) config('otp.expiry', 10));

            OtpCode::query()->create([
                'user_id' => $userId,
                'laboratory_purchase_id' => $purchaseId,
                'code' => Hash::make($plainCode),
                'expires_at' => $expiresAt,
                'attempts' => 0,
                'status' => OtpCode::STATUS_PENDING,
            ]);

            return [
                'plain_code' => $plainCode,
                'expires_at' => $expiresAt,
            ];
        });
    }

    private function sendOtpSms($user, string $plainCode): void
    {
        Log::info('Dispatching OTP notification', [
            'user_id' => $user->id ?? null,
            'app_env' => app()->environment(),
            'phone_e164' => method_exists($user, 'routeNotificationForVonage')
                ? $user->routeNotificationForVonage(new \App\Notifications\SendPhoneVerificationCode($plainCode))
                : null,
            'email' => $user->email ?? null,
        ]);

        $user->notify(new \App\Notifications\SendPhoneVerificationCode($plainCode));
    }

    private function orderHasResults(int $purchaseId): bool
    {
        return LaboratoryNotification::query()
            ->where('laboratory_purchase_id', $purchaseId)
            ->where(function ($query) {
                $query->where('notification_type', LaboratoryNotification::TYPE_RESULTS)
                    ->orWhere('lineanegocio', LaboratoryNotification::LINEA_NEGOCIO_RESULTS);
            })
            ->whereNotNull('results_received_at')
            ->exists();
    }

    private function getResultsPdfBase64(int $purchaseId): ?string
    {
        $notification = LaboratoryNotification::query()
            ->where('laboratory_purchase_id', $purchaseId)
            ->where(function ($query) {
                $query->where('notification_type', LaboratoryNotification::TYPE_RESULTS)
                    ->orWhere('lineanegocio', LaboratoryNotification::LINEA_NEGOCIO_RESULTS);
            })
            ->latest('id')
            ->first();

        if (! $notification) {
            return null;
        }

        if (! empty($notification->results_pdf_base64)) {
            return $notification->results_pdf_base64;
        }

        if (! $notification->needsPdfFetch()) {
            return null;
        }

        try {
            $purchase = $notification->laboratoryPurchase;
            if (! $purchase || empty($purchase->gda_order_id)) {
                return null;
            }

            $response = app(GetGDAResultsAction::class)(
                $purchase->gda_order_id,
                $notification->payload
            );

            $pdf = $response['infogda_resultado_b64'] ?? null;
            if (! $pdf) {
                return null;
            }

            $notification->update(['results_pdf_base64' => $pdf]);
            return $pdf;
        } catch (\Throwable $e) {
            Log::error('Failed to fetch PDF while validating OTP', [
                'purchase_id' => $purchaseId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function maskPhone(?string $phone): string
    {
        if (! $phone) {
            return 'teléfono no disponible';
        }

        $clean = preg_replace('/\D+/', '', $phone);
        $suffix = substr($clean, -2);

        return '*** *** **' . $suffix;
    }

    private function renderError(string $message): Response
    {
        return Inertia::render('LaboratoryResults/OtpAccess', [
            'token' => null,
            'expiresAt' => null,
            'resendAvailableAt' => null,
            'maxAttempts' => self::MAX_ATTEMPTS,
            'attempts' => 0,
            'alreadyVerified' => false,
            'pdfBase64' => null,
            'maskedPhone' => null,
            'errorMessage' => $message,
        ]);
    }
}
