<?php

namespace App\Services\Otp;

use App\Contracts\Otp\OtpCodeGenerator;
use App\Enums\P0aOtpPurpose;
use App\Exceptions\Otp\OtpChallengeConsumedException;
use App\Exceptions\Otp\OtpChallengeException;
use App\Exceptions\Otp\OtpChallengeExpiredException;
use App\Exceptions\Otp\OtpChallengeInvalidatedException;
use App\Exceptions\Otp\OtpChallengeMismatchException;
use App\Exceptions\Otp\OtpChallengeNotFoundException;
use App\Exceptions\Otp\OtpInvalidCodeException;
use App\Models\OtpChallenge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * P0-A2 OTP challenge persistence/service.
 *
 * Code hashing uses Hash::make / Hash::check for consistency with existing
 * OtpCode flows (IssueAuthOtpAction, LaboratoryResultsOtpController). Brute-force
 * risk is mitigated by failed_attempts + max_attempts (lockout/block in P0-A3).
 *
 * Persistent audit logging is deferred (P0-A later). Purge of expired/consumed
 * rows is deferred — see docs/Akubica/p0-a2-persistencia-servicio-otp.md.
 *
 * This service is NOT wired into production auth/lab controllers yet.
 */
class OtpChallengeService
{
    public function __construct(
        private readonly OtpCodeGenerator $codeGenerator,
    ) {
    }

    public function create(CreateOtpChallengeData $data): OtpChallengeCreationResult
    {
        $length = (int) config('otp.p0a.policy.length', 6);
        $maxAttempts = $data->maxAttempts
            ?? (int) config('otp.p0a.policy.max_attempts', 5);

        $plainCode = $this->codeGenerator->generate($length);
        $destinationMasked = $data->destinationMasked
            ?? $this->maskDestination($data->destinationNormalized, $data->channel->value);

        $contextId = $data->contextId === null ? null : (int) $data->contextId;

        return DB::transaction(function () use ($data, $plainCode, $maxAttempts, $destinationMasked, $contextId) {
            if ($data->invalidatePreviousActive) {
                $this->invalidateActiveScope(
                    purpose: $data->purpose,
                    userId: $data->userId,
                    subjectType: $data->subjectType,
                    subjectKey: $data->subjectKey,
                    contextType: $data->contextType,
                    contextId: $contextId,
                    reason: 'superseded',
                );
            }

            $challenge = OtpChallenge::query()->create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $data->userId,
                'subject_type' => $data->subjectType,
                'subject_key' => $data->subjectKey,
                'purpose' => $data->purpose->value,
                'channel' => $data->channel->value,
                'destination_normalized' => $data->destinationNormalized,
                'destination_masked' => $destinationMasked,
                // Hash::make: consistent with legacy otp_codes; attempts limit brute force.
                'code_hash' => Hash::make($plainCode),
                'expires_at' => now()->addMinutes(max(1, $data->ttlMinutes)),
                'failed_attempts' => 0,
                'max_attempts' => $maxAttempts,
                'send_count' => 0,
                'context_type' => $data->contextType,
                'context_id' => $contextId,
                'meta' => $data->meta,
            ]);

            return new OtpChallengeCreationResult($challenge, $plainCode);
        });
    }

    /**
     * Verify code and consume atomically. On success returns the consumed challenge.
     *
     * @throws OtpChallengeException
     */
    public function verify(
        string $publicId,
        string $code,
        P0aOtpPurpose $purpose,
        ?int $userId = null,
        ?string $contextType = null,
        string|int|null $contextId = null,
    ): OtpChallenge {
        $contextIdInt = $contextId === null ? null : (int) $contextId;

        // Return a result envelope so failed-attempt writes commit before exceptions
        // are thrown (exceptions inside DB::transaction would otherwise roll them back).
        $outcome = DB::transaction(function () use ($publicId, $code, $purpose, $userId, $contextType, $contextIdInt) {
            /** @var OtpChallenge|null $challenge */
            $challenge = OtpChallenge::query()
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->first();

            if (! $challenge) {
                return ['error' => OtpChallengeNotFoundException::class];
            }

            if ($challenge->purpose !== $purpose->value) {
                return ['error' => OtpChallengeMismatchException::class];
            }

            if ($userId !== null && (int) $challenge->user_id !== $userId) {
                return ['error' => OtpChallengeMismatchException::class];
            }

            if ($contextType !== null && $challenge->context_type !== $contextType) {
                return ['error' => OtpChallengeMismatchException::class];
            }

            if ($contextIdInt !== null && (int) $challenge->context_id !== $contextIdInt) {
                return ['error' => OtpChallengeMismatchException::class];
            }

            if ($challenge->isConsumed()) {
                return ['error' => OtpChallengeConsumedException::class];
            }

            if ($challenge->isInvalidated()) {
                return ['error' => OtpChallengeInvalidatedException::class];
            }

            if ($challenge->isExpired()) {
                return ['error' => OtpChallengeExpiredException::class];
            }

            if ((int) $challenge->failed_attempts >= (int) $challenge->max_attempts) {
                return [
                    'error' => OtpChallengeInvalidatedException::class,
                    'message' => 'Se agotaron los intentos del desafio OTP.',
                ];
            }

            if (! Hash::check($code, (string) $challenge->code_hash)) {
                $challenge->increment('failed_attempts');
                $challenge->refresh();

                if ((int) $challenge->failed_attempts >= (int) $challenge->max_attempts) {
                    $challenge->update([
                        'invalidated_at' => now(),
                        'invalidated_reason' => 'attempts_exhausted',
                    ]);

                    return [
                        'error' => OtpChallengeInvalidatedException::class,
                        'message' => 'Se agotaron los intentos del desafio OTP.',
                    ];
                }

                return ['error' => OtpInvalidCodeException::class];
            }

            // Conditional update prevents double-consume (SQLite lockForUpdate may no-op).
            $updated = OtpChallenge::query()
                ->where('id', $challenge->id)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->where('expires_at', '>', now())
                ->update(['consumed_at' => now()]);

            if ($updated !== 1) {
                $challenge->refresh();

                if ($challenge->isConsumed()) {
                    return ['error' => OtpChallengeConsumedException::class];
                }

                if ($challenge->isInvalidated()) {
                    return ['error' => OtpChallengeInvalidatedException::class];
                }

                if ($challenge->isExpired()) {
                    return ['error' => OtpChallengeExpiredException::class];
                }

                return [
                    'error' => OtpChallengeException::class,
                    'message' => 'No se pudo consumir el desafio OTP.',
                ];
            }

            return ['challenge' => $challenge->fresh()];
        });

        if (isset($outcome['error'])) {
            $class = $outcome['error'];
            $message = $outcome['message'] ?? null;

            throw $message === null ? new $class : new $class($message);
        }

        return $outcome['challenge'];
    }

    public function invalidate(string $publicId, string $reason): void
    {
        DB::transaction(function () use ($publicId, $reason) {
            /** @var OtpChallenge|null $challenge */
            $challenge = OtpChallenge::query()
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->first();

            if (! $challenge) {
                throw new OtpChallengeNotFoundException;
            }

            if ($challenge->isConsumed() || $challenge->isInvalidated()) {
                return;
            }

            $challenge->update([
                'invalidated_at' => now(),
                'invalidated_reason' => $reason,
            ]);
        });
    }

    public function findActive(string $publicId): ?OtpChallenge
    {
        return OtpChallenge::query()
            ->activeFor()
            ->where('public_id', $publicId)
            ->first();
    }

    public function statusByPublicId(string $publicId): string
    {
        $challenge = OtpChallenge::query()->where('public_id', $publicId)->first();

        if (! $challenge) {
            throw new OtpChallengeNotFoundException;
        }

        return $challenge->status();
    }

    /**
     * Record a delivery attempt (initial or resend). Does NOT send notifications.
     */
    public function recordDeliveryAttempt(string $publicId): void
    {
        DB::transaction(function () use ($publicId) {
            /** @var OtpChallenge|null $challenge */
            $challenge = OtpChallenge::query()
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->first();

            if (! $challenge) {
                throw new OtpChallengeNotFoundException;
            }

            $challenge->update([
                'send_count' => (int) $challenge->send_count + 1,
                'last_sent_at' => now(),
            ]);
        });
    }

    private function invalidateActiveScope(
        P0aOtpPurpose $purpose,
        ?int $userId,
        ?string $subjectType,
        ?string $subjectKey,
        ?string $contextType,
        ?int $contextId,
        string $reason,
    ): void {
        $query = OtpChallenge::query()
            ->activeFor()
            ->where('purpose', $purpose->value);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        } elseif ($subjectType !== null && $subjectKey !== null) {
            $query->where('subject_type', $subjectType)
                ->where('subject_key', $subjectKey);
        } else {
            return;
        }

        if ($contextType !== null) {
            $query->where('context_type', $contextType);
        } else {
            $query->whereNull('context_type');
        }

        if ($contextId !== null) {
            $query->where('context_id', $contextId);
        } else {
            $query->whereNull('context_id');
        }

        $query->update([
            'invalidated_at' => now(),
            'invalidated_reason' => $reason,
        ]);
    }

    private function maskDestination(?string $normalized, string $channel): ?string
    {
        if ($normalized === null || $normalized === '') {
            return null;
        }

        if ($channel === 'email' && str_contains($normalized, '@')) {
            [$local, $domain] = explode('@', $normalized, 2);
            $prefix = substr($local, 0, 1);

            return $prefix.'***@'.$domain;
        }

        $digits = preg_replace('/\D+/', '', $normalized) ?? '';
        $tail = substr($digits, -4);

        return '***'.$tail;
    }
}
