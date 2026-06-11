<?php

namespace App\Actions\Api\V1\Auth;

use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\Api\V1\Auth\AkubicaOtpNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class IssueAuthOtpAction
{
    /**
     * @param  array<string, mixed>|null  $payload
     * @return array{verification_sent: bool, expires_in: int, channel: string}
     */
    public function __invoke(
        string $email,
        string $purpose,
        ?array $payload = null,
        User|string|null $notifiable = null,
    ): array {
        $ttlMinutes = max(1, (int) config('akubica.otp_ttl_minutes', 10));
        $length = max(4, (int) config('akubica.otp_length', 6));
        $maxAttempts = max(1, (int) config('akubica.otp_max_attempts', 5));
        $expiresAt = now()->addMinutes($ttlMinutes);
        $expiresIn = $ttlMinutes * 60;

        if ($notifiable === null) {
            return [
                'verification_sent' => true,
                'expires_in' => $expiresIn,
                'channel' => OtpCode::CHANNEL_EMAIL,
            ];
        }

        $plainCode = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        $otp = DB::transaction(function () use ($email, $purpose, $payload, $plainCode, $expiresAt, $maxAttempts) {
            OtpCode::query()
                ->where('email', $email)
                ->where('purpose', $purpose)
                ->where('status', OtpCode::STATUS_PENDING)
                ->whereNull('used_at')
                ->update(['status' => OtpCode::STATUS_EXPIRED]);

            return OtpCode::query()->create([
                'email' => $email,
                'purpose' => $purpose,
                'payload' => $payload,
                'channel' => OtpCode::CHANNEL_EMAIL,
                'code' => Hash::make($plainCode),
                'expires_at' => $expiresAt,
                'attempts' => 0,
                'max_attempts' => $maxAttempts,
                'status' => OtpCode::STATUS_PENDING,
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 2000) ?: null,
            ]);
        });

        try {
            if ($notifiable instanceof User) {
                $notifiable->notify(new AkubicaOtpNotification($plainCode, $purpose, $ttlMinutes));
            } else {
                Notification::route('mail', $notifiable)
                    ->notify(new AkubicaOtpNotification($plainCode, $purpose, $ttlMinutes));
            }
        } catch (\Throwable $e) {
            $otp->update(['status' => OtpCode::STATUS_FAILED]);

            Log::error('akubica_otp_delivery_failed', [
                'email' => $email,
                'purpose' => $purpose,
                'otp_id' => $otp->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return [
            'verification_sent' => true,
            'expires_in' => $expiresIn,
            'channel' => OtpCode::CHANNEL_EMAIL,
        ];
    }
}
