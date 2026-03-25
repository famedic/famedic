<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\GetGDAResultsAction;
use App\Models\LaboratoryNotification;
use App\Models\LabResultAccessToken;
use App\Models\OtpAccessLog;
use App\Models\OtpCode;
use App\Notifications\LaboratoryResultsOtpNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class LabResultsAccessController extends Controller
{
    private const MAX_ATTEMPTS = 5;

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

        $sessionHours = (int) config('laboratory-results.pdf_session_hours', 24);

        $verifiedOtp = OtpCode::query()
            ->where('user_id', $user->id)
            ->where('laboratory_purchase_id', $purchase->id)
            ->where('status', OtpCode::STATUS_VERIFIED)
            ->where('verified_at', '>=', now()->subHours($sessionHours))
            ->latest('id')
            ->first();

        if ($verifiedOtp) {
            if (! $this->getResultsPdfBase64($purchase->id)) {
                return $this->renderError('No se pudo cargar el PDF de resultados en este momento.');
            }

            return Inertia::render('LaboratoryResults/OtpAccess', $this->buildPageProps(
                $token,
                $user,
                $purchase,
                true,
                null
            ));
        }

        return Inertia::render('LaboratoryResults/OtpAccess', $this->buildPageProps(
            $token,
            $user,
            $purchase,
            false,
            null
        ));
    }

    public function showShared(Request $request, string $token): Response|RedirectResponse
    {
        $context = $this->resolveTokenContext($token);
        if (! $context) {
            return $this->renderError('La liga de resultados no es válida o expiró.');
        }

        [, $purchase, $user] = $context;

        if (! $this->orderHasResults($purchase->id)) {
            return $this->renderError('Los resultados aún no están disponibles para esta orden.');
        }

        if (! $this->getResultsPdfBase64($purchase->id)) {
            return $this->renderError('No se pudo cargar el PDF de resultados en este momento.');
        }

        $props = $this->buildPageProps($token, $user, $purchase, true, null);
        $sharedByName = $request->query('sharedByName', $user->name);
        $expiresAt = $request->query('expiresAt', now()->addHours((int) config('laboratory-results.share_link_hours', 12))->toIso8601String());

        $props['isSharedView'] = true;
        $props['sharedByName'] = $sharedByName;
        $props['expiresAt'] = $expiresAt;
        $props['pdfUrl'] = URL::temporarySignedRoute(
            'lab-results.shared-pdf',
            now()->addHours((int) config('laboratory-results.share_link_hours', 12)),
            ['token' => $token]
        );
        $props['pdfDownloadUrl'] = null;
        $props['shareUrl'] = null;
        $props['currentStep'] = 0;

        return Inertia::render('LaboratoryResults/OtpAccess', $props);
    }

    public function streamSharedPdf(Request $request, string $token)
    {
        $context = $this->resolveTokenContext($token);
        if (! $context) {
            abort(403);
        }

        [, $purchase, $user] = $context;

        if (! $this->orderHasResults($purchase->id)) {
            abort(404);
        }

        $b64 = $this->getResultsPdfBase64($purchase->id);
        if (! $b64) {
            abort(503);
        }

        $binary = base64_decode($b64, true);
        if ($binary === false) {
            abort(503);
        }

        $this->persistAccessLog('pdf_viewed_shared', $user->id, $purchase->id, null, []);

        $filename = 'resultados-laboratorio.pdf';

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    public function sendOtp(Request $request): Response|RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'channel' => ['required', 'in:sms,email'],
        ]);

        $context = $this->resolveTokenContext($validated['token']);
        if (! $context) {
            return redirect()->route('lab-results.show', ['token' => $validated['token']])
                ->withErrors(['channel' => 'La liga no es válida o expiró.']);
        }

        [, $purchase, $user] = $context;

        if ($validated['channel'] === OtpCode::CHANNEL_EMAIL && empty($user->email)) {
            return redirect()->route('lab-results.show', ['token' => $validated['token']])
                ->withErrors(['channel' => 'No hay un correo registrado para enviarte el código.']);
        }

        if ($validated['channel'] === OtpCode::CHANNEL_SMS && empty($user->phone)) {
            return redirect()->route('lab-results.show', ['token' => $validated['token']])
                ->withErrors(['channel' => 'No hay un teléfono registrado para enviarte el código.']);
        }

        $otp = $this->issueOtp($user->id, $purchase->id, $validated['channel']);
        $user->notify(new LaboratoryResultsOtpNotification($otp['plain_code'], $validated['channel']));

        $this->persistAccessLog('otp_requested', $user->id, $purchase->id, $validated['channel'], [
            'otp_id' => $otp['otp_id'],
        ]);

        Log::info('lab_results_otp_requested', [
            'user_id' => $user->id,
            'purchase_id' => $purchase->id,
            'channel' => $validated['channel'],
            'otp_id' => $otp['otp_id'],
            'ip' => $request->ip(),
        ]);

        return Inertia::render('LaboratoryResults/OtpAccess', $this->buildPageProps(
            $validated['token'],
            $user,
            $purchase,
            false,
            $validated['channel']
        ));
    }

    public function verify(Request $request): Response|RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $context = $this->resolveTokenContext($validated['token']);
        if (! $context) {
            $this->persistAccessLog('otp_verify_failed', null, null, null, ['reason' => 'invalid_token']);

            return redirect()->route('lab-results.show', ['token' => $validated['token']])
                ->withErrors(['otp' => 'La liga no es válida o expiró.']);
        }

        [, $purchase, $user] = $context;

        $otp = OtpCode::query()
            ->where('user_id', $user->id)
            ->where('laboratory_purchase_id', $purchase->id)
            ->where('status', OtpCode::STATUS_PENDING)
            ->latest('id')
            ->first();

        if (! $otp) {
            $this->persistAccessLog('otp_verify_failed', $user->id, $purchase->id, null, ['reason' => 'no_active_code']);

            return redirect()->route('lab-results.show', ['token' => $validated['token']])
                ->withErrors(['otp' => 'No hay un código activo. Pide que te envíen uno nuevo.']);
        }

        if ($otp->expires_at->isPast()) {
            $otp->update(['status' => OtpCode::STATUS_EXPIRED]);
            $this->persistAccessLog('otp_verify_failed', $user->id, $purchase->id, $otp->channel, ['reason' => 'expired']);

            return redirect()->route('lab-results.show', ['token' => $validated['token']])
                ->withErrors(['otp' => 'El código expiró. Pide uno nuevo.']);
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            $otp->update(['status' => OtpCode::STATUS_FAILED]);
            $this->persistAccessLog('attempts_exhausted', $user->id, $purchase->id, $otp->channel, ['otp_id' => $otp->id]);
            Log::info('lab_results_attempts_exhausted', [
                'user_id' => $user->id,
                'purchase_id' => $purchase->id,
                'otp_id' => $otp->id,
            ]);

            return redirect()->route('lab-results.show', ['token' => $validated['token']])
                ->withErrors(['otp' => 'Ya no quedan intentos. Pide un código nuevo.']);
        }

        if (! Hash::check($validated['code'], $otp->code)) {
            $otp->increment('attempts');
            $otp->refresh();

            $this->persistAccessLog('otp_verify_failed', $user->id, $purchase->id, $otp->channel, [
                'otp_id' => $otp->id,
                'attempts' => $otp->attempts,
            ]);

            if ($otp->attempts >= self::MAX_ATTEMPTS) {
                $otp->update(['status' => OtpCode::STATUS_FAILED]);
                $this->persistAccessLog('attempts_exhausted', $user->id, $purchase->id, $otp->channel, ['otp_id' => $otp->id]);
                Log::info('lab_results_attempts_exhausted', [
                    'user_id' => $user->id,
                    'purchase_id' => $purchase->id,
                    'otp_id' => $otp->id,
                ]);

                return redirect()->route('lab-results.show', ['token' => $validated['token']])
                    ->withErrors(['otp' => 'Código incorrecto. Ya no quedan intentos. Pide un código nuevo.']);
            }

            return redirect()->route('lab-results.show', ['token' => $validated['token']])
                ->withErrors(['otp' => 'El código no coincide.']);
        }

        if (! $this->getResultsPdfBase64($purchase->id)) {
            $this->persistAccessLog('otp_verify_failed', $user->id, $purchase->id, $otp->channel, ['reason' => 'pdf_unavailable']);

            return redirect()->route('lab-results.show', ['token' => $validated['token']])
                ->withErrors(['otp' => 'El código es correcto, pero no pudimos cargar el archivo. Intenta de nuevo.']);
        }

        $otp->update([
            'status' => OtpCode::STATUS_VERIFIED,
            'verified_at' => now(),
            'expires_at' => now(),
        ]);

        $this->persistAccessLog('otp_verified', $user->id, $purchase->id, $otp->channel, ['otp_id' => $otp->id]);

        Log::info('lab_results_otp_verified', [
            'user_id' => $user->id,
            'purchase_id' => $purchase->id,
            'otp_id' => $otp->id,
            'channel' => $otp->channel,
            'ip' => $request->ip(),
        ]);

        return Inertia::render('LaboratoryResults/OtpAccess', $this->buildPageProps(
            $validated['token'],
            $user,
            $purchase,
            true,
            $otp->channel
        ));
    }

    public function resend(Request $request): Response|RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'channel' => ['required', 'in:sms,email'],
        ]);

        $context = $this->resolveTokenContext($validated['token']);
        if (! $context) {
            return redirect()->route('lab-results.show', ['token' => $validated['token']])
                ->withErrors(['otp' => 'La liga no es válida o expiró.']);
        }

        [, $purchase, $user] = $context;

        $sessionHours = (int) config('laboratory-results.pdf_session_hours', 24);
        $alreadyVerified = OtpCode::query()
            ->where('user_id', $user->id)
            ->where('laboratory_purchase_id', $purchase->id)
            ->where('status', OtpCode::STATUS_VERIFIED)
            ->where('verified_at', '>=', now()->subHours($sessionHours))
            ->exists();

        if ($alreadyVerified) {
            return redirect()->route('lab-results.show', ['token' => $validated['token']])
                ->withErrors(['otp' => 'Ya validaste el acceso.']);
        }

        $latestOtp = OtpCode::query()
            ->where('user_id', $user->id)
            ->where('laboratory_purchase_id', $purchase->id)
            ->latest('id')
            ->first();

        $resendSeconds = (int) config('laboratory-results.resend_seconds', 30);

        if ($latestOtp && now()->diffInSeconds($latestOtp->created_at) < $resendSeconds) {
            $remaining = $resendSeconds - (int) now()->diffInSeconds($latestOtp->created_at);

            return redirect()->route('lab-results.show', ['token' => $validated['token']])
                ->withErrors(['otp' => 'Espera '.$remaining.' segundos para pedir otro código.']);
        }

        if ($validated['channel'] === OtpCode::CHANNEL_EMAIL && empty($user->email)) {
            return redirect()->route('lab-results.show', ['token' => $validated['token']])
                ->withErrors(['otp' => 'No hay un correo registrado.']);
        }

        if ($validated['channel'] === OtpCode::CHANNEL_SMS && empty($user->phone)) {
            return redirect()->route('lab-results.show', ['token' => $validated['token']])
                ->withErrors(['otp' => 'No hay un teléfono registrado.']);
        }

        $otp = $this->issueOtp($user->id, $purchase->id, $validated['channel']);
        $user->notify(new LaboratoryResultsOtpNotification($otp['plain_code'], $validated['channel']));

        $this->persistAccessLog('otp_resent', $user->id, $purchase->id, $validated['channel'], ['otp_id' => $otp['otp_id']]);

        Log::info('lab_results_otp_resent', [
            'user_id' => $user->id,
            'purchase_id' => $purchase->id,
            'channel' => $validated['channel'],
            'otp_id' => $otp['otp_id'],
            'ip' => $request->ip(),
        ]);

        return Inertia::render('LaboratoryResults/OtpAccess', $this->buildPageProps(
            $validated['token'],
            $user,
            $purchase,
            false,
            $validated['channel']
        ));
    }

    public function streamPdf(Request $request, string $token)
    {
        $disposition = $request->query('disposition', 'inline');
        if (! in_array($disposition, ['inline', 'attachment'], true)) {
            abort(400);
        }

        $context = $this->resolveTokenContext($token);
        if (! $context) {
            abort(403);
        }

        [, $purchase, $user] = $context;

        $sessionHours = (int) config('laboratory-results.pdf_session_hours', 24);

        $verifiedOtp = OtpCode::query()
            ->where('user_id', $user->id)
            ->where('laboratory_purchase_id', $purchase->id)
            ->where('status', OtpCode::STATUS_VERIFIED)
            ->where('verified_at', '>=', now()->subHours($sessionHours))
            ->latest('id')
            ->first();

        if (! $verifiedOtp) {
            abort(403);
        }

        $b64 = $this->getResultsPdfBase64($purchase->id);
        if (! $b64) {
            abort(503);
        }

        $binary = base64_decode($b64, true);
        if ($binary === false) {
            abort(503);
        }

        $event = $disposition === 'attachment' ? 'pdf_downloaded' : 'pdf_viewed';
        $this->persistAccessLog($event, $user->id, $purchase->id, null, [
            'disposition' => $disposition,
        ]);

        Log::info('lab_results_'.$event, [
            'user_id' => $user->id,
            'purchase_id' => $purchase->id,
            'ip' => $request->ip(),
        ]);

        $filename = 'resultados-laboratorio.pdf';

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition === 'attachment'
                ? 'attachment; filename="'.$filename.'"'
                : 'inline; filename="'.$filename.'"',
        ]);
    }

    private function buildPageProps(
        string $plainToken,
        $user,
        $purchase,
        bool $alreadyVerified,
        ?string $otpChannelHint
    ): array {
        $sessionHours = (int) config('laboratory-results.pdf_session_hours', 24);
        $availabilityHours = (int) config('laboratory-results.availability_hours', $sessionHours);
        $resendSeconds = (int) config('laboratory-results.resend_seconds', 30);

        $phoneE164 = $user->phone?->formatE164();
        $maskedPhone = $this->maskPhone($phoneE164);
        $maskedEmail = $this->maskEmail($user->email);
        $phoneLast4 = $this->phoneLast4($phoneE164);

        $latestPending = null;
        if (! $alreadyVerified) {
            $latestPending = OtpCode::query()
                ->where('user_id', $user->id)
                ->where('laboratory_purchase_id', $purchase->id)
                ->where('status', OtpCode::STATUS_PENDING)
                ->latest('id')
                ->first();
        }

        $otpSent = ! $alreadyVerified && $latestPending !== null;

        $resendAvailableAt = $latestPending
            ? $latestPending->created_at->copy()->addSeconds($resendSeconds)->toIso8601String()
            : null;

        $attempts = $latestPending?->attempts ?? 0;
        $remainingAttempts = max(0, self::MAX_ATTEMPTS - $attempts);

        $pdfUrl = null;
        $pdfDownloadUrl = null;
        $shareUrl = route('lab-results.show', ['token' => $plainToken]);
        $isSharedView = false;
        $sharedByName = null;

        if ($alreadyVerified) {
            $expiresAt = now()->addHours($sessionHours)->toIso8601String();
            $shareLinkExpires = now()->addHours((int) config('laboratory-results.share_link_hours', 12));

            $shareUrl = URL::temporarySignedRoute('lab-results.show-shared', $shareLinkExpires, [
                'token' => $plainToken,
                'sharedByName' => $user->name,
                'expiresAt' => $shareLinkExpires->toIso8601String(),
            ]);

            $expiresSigned = now()->addHours($sessionHours);
            $pdfUrl = URL::temporarySignedRoute(
                'lab-results.pdf',
                $expiresSigned,
                ['token' => $plainToken, 'disposition' => 'inline']
            );
            $pdfDownloadUrl = URL::temporarySignedRoute(
                'lab-results.pdf',
                $expiresSigned,
                ['token' => $plainToken, 'disposition' => 'attachment']
            );
        } else {
            $expiresAt = $latestPending?->expires_at?->toIso8601String();
        }

        $currentStep = 1;
        if ($alreadyVerified) {
            $currentStep = 3;
        } elseif ($otpSent) {
            $currentStep = 2;
        }

        return [
            'token' => $plainToken,
            'currentStep' => $currentStep,
            'otpSent' => $otpSent,
            'otpChannel' => $otpChannelHint ?? $latestPending?->channel,
            'expiresAt' => $expiresAt,
            'resendAvailableAt' => $resendAvailableAt,
            'maxAttempts' => self::MAX_ATTEMPTS,
            'attempts' => $attempts,
            'remainingAttempts' => $remainingAttempts,
            'alreadyVerified' => $alreadyVerified,
            'pdfUrl' => $pdfUrl,
            'pdfDownloadUrl' => $pdfDownloadUrl,
            'maskedPhone' => $maskedPhone,
            'maskedEmail' => $maskedEmail,
            'phoneLast4' => $phoneLast4,
            'availabilityHours' => $availabilityHours,
            'resendSeconds' => $resendSeconds,
            'isSharedView' => $isSharedView,
            'sharedByName' => $sharedByName,
            'shareUrl' => $shareUrl,
            'canUseSms' => ! empty($user->phone),
            'canUseEmail' => ! empty($user->email),
            'errorMessage' => null,
        ];
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
            return 'no disponible';
        }

        $clean = preg_replace('/\D+/', '', $phone);
        if (strlen($clean) < 4) {
            return '***';
        }

        return '*** *** '.substr($clean, -4);
    }

    private function phoneLast4(?string $phone): string
    {
        $clean = preg_replace('/\D+/', '', $phone ?? '');

        return strlen($clean) >= 4 ? substr($clean, -4) : '****';
    }

    private function maskEmail(?string $email): string
    {
        if (! $email) {
            return 'no disponible';
        }

        $parts = explode('@', $email);
        $local = $parts[0] ?? '';
        $domain = $parts[1] ?? '';

        if ($local === '') {
            return '***@'.$domain;
        }

        $visible = mb_substr($local, 0, 1).'***';

        return $visible.'@'.$domain;
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

    private function renderError(string $message): Response
    {
        return Inertia::render('LaboratoryResults/OtpAccess', [
            'token' => null,
            'currentStep' => 0,
            'otpSent' => false,
            'otpChannel' => null,
            'expiresAt' => null,
            'resendAvailableAt' => null,
            'maxAttempts' => self::MAX_ATTEMPTS,
            'attempts' => 0,
            'remainingAttempts' => self::MAX_ATTEMPTS,
            'alreadyVerified' => false,
            'pdfUrl' => null,
            'pdfDownloadUrl' => null,
            'maskedPhone' => null,
            'maskedEmail' => null,
            'phoneLast4' => null,
            'availabilityHours' => (int) config('laboratory-results.availability_hours', 24),
            'resendSeconds' => (int) config('laboratory-results.resend_seconds', 30),
            'shareUrl' => null,
            'canUseSms' => false,
            'canUseEmail' => false,
            'errorMessage' => $message,
        ]);
    }
}
