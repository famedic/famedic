<?php

namespace App\Services\Otp\Staging;

use App\Enums\P0aOtpPurpose;
use App\Models\OtpChallenge;
use App\Models\OtpStagingDiagnosticRevealAudit;
use App\Models\OtpStagingDiagnosticVault;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Staging-only vault for Akúbica login OTP (RC1 Newman). Never active in production.
 */
final class AkubicaStagingOtpDiagnosticVault
{
    public function isFeatureEnabled(): bool
    {
        if (! app()->environment('staging')) {
            return false;
        }

        return (bool) config('otp.staging_diagnostic.code_reveal_enabled', false);
    }

    public function storeLoginChallengeCode(OtpChallenge $challenge, string $plainCode): void
    {
        if (! $this->isFeatureEnabled()) {
            return;
        }

        if ($challenge->purpose !== P0aOtpPurpose::AkubicaLogin->value) {
            return;
        }

        $expiresAt = $challenge->expires_at ?? now()->addMinutes((int) config('otp.p0a.policy.ttl_minutes', 5));

        OtpStagingDiagnosticVault::query()->updateOrCreate(
            ['challenge_public_id' => $challenge->public_id],
            [
                'purpose' => $challenge->purpose,
                'code_ciphertext' => Crypt::encryptString($plainCode),
                'expires_at' => $expiresAt,
                'revealed_at' => null,
            ],
        );
    }

    public function purgeForChallenge(string $challengePublicId): void
    {
        OtpStagingDiagnosticVault::query()
            ->where('challenge_public_id', $challengePublicId)
            ->delete();
    }

    /**
     * @return array{code: string, expires_at: string}
     */
    public function reveal(int $administratorId, string $challengePublicId, ?string $correlationId = null): array
    {
        if (! $this->isFeatureEnabled()) {
            throw new NotFoundHttpException;
        }

        $challenge = OtpChallenge::query()->where('public_id', $challengePublicId)->first();
        if (! $challenge instanceof OtpChallenge) {
            throw new NotFoundHttpException;
        }

        if ($challenge->purpose !== P0aOtpPurpose::AkubicaLogin->value) {
            throw new NotFoundHttpException;
        }

        if ($challenge->isConsumed() || $challenge->consumed_at !== null) {
            throw new GoneHttpException('El challenge ya fue consumido.');
        }

        if ($challenge->isInvalidated() || $challenge->invalidated_at !== null) {
            throw new GoneHttpException('El challenge ya no está activo.');
        }

        if ($challenge->isExpired()) {
            throw new GoneHttpException('El challenge expiró.');
        }

        return DB::transaction(function () use ($administratorId, $challengePublicId, $correlationId, $challenge): array {
            /** @var OtpStagingDiagnosticVault|null $vault */
            $vault = OtpStagingDiagnosticVault::query()
                ->where('challenge_public_id', $challengePublicId)
                ->lockForUpdate()
                ->first();

            if ($vault === null || $vault->revealed_at !== null || $vault->code_ciphertext === '') {
                throw new ConflictHttpException('El OTP ya fue revelado o no está disponible.');
            }

            if ($vault->expires_at->isPast()) {
                $vault->delete();
                throw new GoneHttpException('El OTP expiró.');
            }

            try {
                $code = Crypt::decryptString($vault->code_ciphertext);
            } catch (\Throwable) {
                $vault->delete();
                throw new AccessDeniedHttpException('No se pudo descifrar el OTP almacenado.');
            }

            $vault->forceFill([
                'revealed_at' => now(),
                'code_ciphertext' => '',
            ])->save();

            OtpStagingDiagnosticRevealAudit::query()->create([
                'administrator_id' => $administratorId,
                'challenge_public_id' => $challengePublicId,
                'correlation_id' => $correlationId,
                'created_at' => now(),
            ]);

            return [
                'code' => $code,
                'expires_at' => $challenge->expires_at?->utc()->format('Y-m-d\TH:i:s\Z') ?? $vault->expires_at->utc()->format('Y-m-d\TH:i:s\Z'),
            ];
        });
    }
}
