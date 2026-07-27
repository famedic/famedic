<?php

namespace App\Services\Otp\Registration;

use App\Enums\AkubicaRegistrationIntentInvalidationReason;
use App\Enums\AkubicaRegistrationIntentStatus;
use App\Enums\P0aOtpChannel;
use App\Enums\P0aOtpPurpose;
use App\Exceptions\Otp\RegistrationIntentExpiredException;
use App\Exceptions\Otp\RegistrationIntentInvalidStateException;
use App\Exceptions\Otp\RegistrationIntentNotFoundException;
use App\Exceptions\Otp\RegistrationIntentPayloadException;
use App\Models\AkubicaRegistrationIntent;
use App\Models\OtpChallenge;
use App\Services\Otp\CreateOtpChallengeData;
use App\Services\Otp\OtpAbuseKeyHasher;
use App\Services\Otp\OtpAbusePolicy;
use App\Services\Otp\OtpRequestContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P0-A5.3 — Internal persistence/lifecycle for secure registration intents.
 *
 * Wired to public register request/resend in P0-A5.4 via AkubicaRegisterOtpService.
 * Does not send mail/SMS, create users, emit tokens, or write decoy Redis keys.
 * Challenges are issued through OtpAbusePolicy::issue (no delivery).
 *
 * Expiration source of truth: intent.expires_at is set equal to challenge.expires_at
 * at creation (register TTL). Payload gates use intent status + intent.expires_at
 * AND challenge coherence on every read/consume (purpose, not consumed/invalidated,
 * not expired). OTP verify (future) continues to use challenge timestamps.
 *
 * Supersede is email_fingerprint-scoped only: same phone + different email may
 * leave multiple PENDING intents (intentional in P0-A5.3; not full uniqueness).
 */
final class AkubicaRegistrationIntentService
{
    public const CONTEXT_TYPE = 'akubica_register';

    public const SUBJECT_TYPE = 'email_fp';

    public function __construct(
        private readonly OtpAbusePolicy $abusePolicy,
        private readonly AkubicaRegistrationPayloadCipher $cipher,
        private readonly OtpAbuseKeyHasher $hasher,
    ) {
    }

    /**
     * Atomically create otp_challenge (via anti-abuse issue) + encrypted intent.
     * No delivery of OTP plaintext (plain code retained only in creation result).
     *
     * @throws RegistrationIntentPayloadException
     * @throws \App\Exceptions\Otp\OtpRateLimitExceededException
     * @throws \App\Exceptions\Otp\OtpTemporarilyBlockedException
     */
    public function createPending(
        RegistrationIdentity $identity,
        ?string $clientIp = null,
    ): AkubicaRegistrationIntentCreationResult {
        $payload = AkubicaRegistrationPayload::fromIdentity($identity);
        $ciphertext = $this->cipher->encrypt($payload);
        $emailFp = $this->emailFingerprint($identity->email);
        $ttlMinutes = AkubicaRegistrationPolicy::ttlMinutes();
        $maxAttempts = AkubicaRegistrationPolicy::maxAttempts();
        $emailValue = $identity->email->value();
        $masked = $this->maskEmail($emailValue);

        return DB::transaction(function () use (
            $payload,
            $ciphertext,
            $emailFp,
            $ttlMinutes,
            $maxAttempts,
            $masked,
            $emailValue,
            $clientIp,
        ) {
            $challengeResult = $this->abusePolicy->issue(
                new CreateOtpChallengeData(
                    purpose: P0aOtpPurpose::AkubicaRegister,
                    channel: P0aOtpChannel::Email,
                    ttlMinutes: $ttlMinutes,
                    userId: null,
                    subjectType: self::SUBJECT_TYPE,
                    subjectKey: $emailFp,
                    destinationNormalized: null,
                    destinationMasked: $masked,
                    contextType: self::CONTEXT_TYPE,
                    contextId: null,
                    invalidatePreviousActive: true,
                    meta: ['flow' => 'akubica_register'],
                    maxAttempts: $maxAttempts,
                ),
                new OtpRequestContext(
                    purpose: P0aOtpPurpose::AkubicaRegister,
                    userId: null,
                    subjectType: 'email',
                    subjectKey: $emailValue,
                    contextType: self::CONTEXT_TYPE,
                    contextId: null,
                    channel: P0aOtpChannel::Email,
                    clientIp: $clientIp,
                ),
            );

            $challenge = $challengeResult->challenge;
            $expiresAt = $challenge->expires_at instanceof Carbon
                ? $challenge->expires_at->copy()
                : Carbon::parse($challenge->expires_at);

            $supersededIds = $this->supersedePendingForFingerprint($emailFp);

            $intent = new AkubicaRegistrationIntent;
            $intent->forceFill([
                'otp_challenge_id' => $challenge->id,
                'status' => AkubicaRegistrationIntentStatus::Pending,
                'encrypted_payload' => $ciphertext,
                'payload_version' => $payload->payloadVersion,
                'email_fingerprint' => $emailFp,
                'expires_at' => $expiresAt,
                'consumed_at' => null,
                'invalidated_at' => null,
                'invalidation_reason' => null,
                'superseded_by_id' => null,
            ]);
            $intent->save();

            if ($supersededIds !== []) {
                AkubicaRegistrationIntent::query()
                    ->whereIn('id', $supersededIds)
                    ->update(['superseded_by_id' => $intent->id]);
            }

            return new AkubicaRegistrationIntentCreationResult(
                intent: $intent->fresh(),
                challenge: $challenge->fresh(),
                plainCode: $challengeResult->plainCode(),
            );
        });
    }

    /**
     * @throws RegistrationIntentNotFoundException
     * @throws RegistrationIntentExpiredException
     * @throws RegistrationIntentInvalidStateException
     * @throws RegistrationIntentPayloadException
     */
    public function readPayload(int $intentId): AkubicaRegistrationPayload
    {
        return DB::transaction(function () use ($intentId) {
            $intent = $this->lockIntent($intentId);
            $this->assertReadable($intent);
            $this->assertChallengeCoherent($intent);

            return $this->cipher->decrypt((string) $intent->encrypted_payload);
        });
    }

    /**
     * Consume pending intent: terminal CONSUMED + erase ciphertext.
     * Repeated consume on already-consumed throws InvalidState (not silent).
     *
     * @throws RegistrationIntentNotFoundException
     * @throws RegistrationIntentExpiredException
     * @throws RegistrationIntentInvalidStateException
     * @throws RegistrationIntentPayloadException
     */
    public function consume(int $intentId): AkubicaRegistrationPayload
    {
        return DB::transaction(function () use ($intentId) {
            $intent = $this->lockIntent($intentId);
            $this->assertReadable($intent);
            $this->assertChallengeCoherent($intent);

            $payload = $this->cipher->decrypt((string) $intent->encrypted_payload);

            $updated = AkubicaRegistrationIntent::query()
                ->where('id', $intent->id)
                ->where('status', AkubicaRegistrationIntentStatus::Pending)
                ->whereNotNull('encrypted_payload')
                ->where('expires_at', '>', now())
                ->update([
                    'status' => AkubicaRegistrationIntentStatus::Consumed,
                    'consumed_at' => now(),
                    'encrypted_payload' => null,
                    'invalidated_at' => null,
                    'invalidation_reason' => null,
                ]);

            if ($updated !== 1) {
                $intent->refresh();
                $this->throwForTerminalOrExpired($intent);
            }

            return $payload;
        });
    }

    /**
     * P0-A5.5 — Mark PENDING → CONSUMED and erase ciphertext without re-checking
     * challenge unconsumed. Caller must already validate OTP and consume the
     * challenge in the same outer transaction (design §7 verify→create order).
     *
     * @throws RegistrationIntentInvalidStateException
     */
    public function markConsumedClearingCiphertext(int $intentId): void
    {
        $intent = $this->lockIntent($intentId);
        $this->assertReadable($intent);

        $updated = AkubicaRegistrationIntent::query()
            ->where('id', $intent->id)
            ->where('status', AkubicaRegistrationIntentStatus::Pending)
            ->whereNotNull('encrypted_payload')
            ->where('expires_at', '>', now())
            ->update([
                'status' => AkubicaRegistrationIntentStatus::Consumed,
                'consumed_at' => now(),
                'encrypted_payload' => null,
                'invalidated_at' => null,
                'invalidation_reason' => null,
            ]);

        if ($updated !== 1) {
            $intent->refresh();
            $this->throwForTerminalOrExpired($intent);
        }
    }

    /**
     * Expire a single pending intent (idempotent if already EXPIRED).
     */
    public function expire(int $intentId): void
    {
        DB::transaction(function () use ($intentId) {
            $intent = $this->lockIntent($intentId);

            if ($intent->status === AkubicaRegistrationIntentStatus::Expired) {
                $this->ensureCiphertextCleared($intent);

                return;
            }

            if ($intent->status !== AkubicaRegistrationIntentStatus::Pending) {
                throw new RegistrationIntentInvalidStateException;
            }

            AkubicaRegistrationIntent::query()
                ->where('id', $intent->id)
                ->where('status', AkubicaRegistrationIntentStatus::Pending)
                ->update([
                    'status' => AkubicaRegistrationIntentStatus::Expired,
                    'encrypted_payload' => null,
                    'invalidated_at' => null,
                    'invalidation_reason' => null,
                ]);
        });
    }

    /**
     * Invalidate pending intent (idempotent if already INVALIDATED with same family).
     */
    public function invalidate(
        int $intentId,
        AkubicaRegistrationIntentInvalidationReason $reason,
    ): void {
        DB::transaction(function () use ($intentId, $reason) {
            $intent = $this->lockIntent($intentId);

            if ($intent->status === AkubicaRegistrationIntentStatus::Invalidated) {
                $this->ensureCiphertextCleared($intent);

                return;
            }

            if ($intent->status !== AkubicaRegistrationIntentStatus::Pending) {
                throw new RegistrationIntentInvalidStateException;
            }

            AkubicaRegistrationIntent::query()
                ->where('id', $intent->id)
                ->where('status', AkubicaRegistrationIntentStatus::Pending)
                ->update([
                    'status' => AkubicaRegistrationIntentStatus::Invalidated,
                    'invalidated_at' => now(),
                    'invalidation_reason' => $reason,
                    'encrypted_payload' => null,
                ]);
        });
    }

    /**
     * Expire all PENDING intents whose expires_at has passed. Idempotent.
     * Processes up to 500 rows per call (ordered by id) without decrypting.
     *
     * @return int Number of rows transitioned to EXPIRED in this run
     */
    public function expireDuePending(int $limit = 500): int
    {
        $limit = max(1, min(5000, $limit));

        $ids = AkubicaRegistrationIntent::query()
            ->where('status', AkubicaRegistrationIntentStatus::Pending)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $count = 0;
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$count) {
                /** @var AkubicaRegistrationIntent|null $intent */
                $intent = AkubicaRegistrationIntent::query()
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if (! $intent || $intent->status !== AkubicaRegistrationIntentStatus::Pending) {
                    return;
                }

                if ($intent->expires_at->isFuture()) {
                    return;
                }

                $updated = AkubicaRegistrationIntent::query()
                    ->where('id', $intent->id)
                    ->where('status', AkubicaRegistrationIntentStatus::Pending)
                    ->update([
                        'status' => AkubicaRegistrationIntentStatus::Expired,
                        'encrypted_payload' => null,
                    ]);

                if ($updated === 1) {
                    $count++;
                }
            });
        }

        return $count;
    }

    public function emailFingerprint(NormalizedEmail $email): string
    {
        return $this->hasher->hashIdentity(
            purpose: P0aOtpPurpose::AkubicaRegister,
            subjectType: 'email',
            subjectKey: $email->value(),
            contextType: 'akubica_register_intent',
        );
    }

    /**
     * @return list<int>
     */
    private function supersedePendingForFingerprint(string $emailFingerprint): array
    {
        $pendingIds = AkubicaRegistrationIntent::query()
            ->where('email_fingerprint', $emailFingerprint)
            ->where('status', AkubicaRegistrationIntentStatus::Pending)
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($pendingIds === []) {
            return [];
        }

        AkubicaRegistrationIntent::query()
            ->whereIn('id', $pendingIds)
            ->where('status', AkubicaRegistrationIntentStatus::Pending)
            ->update([
                'status' => AkubicaRegistrationIntentStatus::Superseded,
                'invalidated_at' => now(),
                'invalidation_reason' => AkubicaRegistrationIntentInvalidationReason::Superseded,
                'encrypted_payload' => null,
            ]);

        return $pendingIds;
    }

    private function lockIntent(int $intentId): AkubicaRegistrationIntent
    {
        /** @var AkubicaRegistrationIntent|null $intent */
        $intent = AkubicaRegistrationIntent::query()
            ->where('id', $intentId)
            ->lockForUpdate()
            ->first();

        if (! $intent) {
            throw new RegistrationIntentNotFoundException;
        }

        return $intent;
    }

    private function assertReadable(AkubicaRegistrationIntent $intent): void
    {
        if ($intent->status !== AkubicaRegistrationIntentStatus::Pending) {
            throw new RegistrationIntentInvalidStateException;
        }

        if ($intent->expires_at === null || $intent->expires_at->isPast()) {
            throw new RegistrationIntentExpiredException;
        }

        if ($intent->encrypted_payload === null || $intent->encrypted_payload === '') {
            throw new RegistrationIntentPayloadException(
                'El payload del intent no esta disponible.',
                'REGISTRATION_INTENT_PAYLOAD_ABSENT',
            );
        }
    }

    /**
     * Intent PENDING alone is insufficient: associated challenge must still be usable.
     * Validated on every read/consume (not only at creation).
     */
    private function assertChallengeCoherent(AkubicaRegistrationIntent $intent): void
    {
        /** @var OtpChallenge|null $challenge */
        $challenge = OtpChallenge::query()
            ->where('id', $intent->otp_challenge_id)
            ->lockForUpdate()
            ->first();

        if (! $challenge) {
            throw new RegistrationIntentInvalidStateException(
                'La asociacion del intent de registro no es valida.',
            );
        }

        if ($challenge->purpose !== P0aOtpPurpose::AkubicaRegister->value) {
            throw new RegistrationIntentInvalidStateException(
                'La asociacion del intent de registro no es valida.',
            );
        }

        if ($challenge->isConsumed() || $challenge->isInvalidated()) {
            throw new RegistrationIntentInvalidStateException;
        }

        if ($challenge->isExpired()) {
            throw new RegistrationIntentExpiredException;
        }

        // Divergent clocks: if challenge expires earlier than intent row, trust challenge.
        if ($challenge->expires_at !== null
            && $intent->expires_at !== null
            && $challenge->expires_at->lt($intent->expires_at)
            && $challenge->expires_at->isPast()
        ) {
            throw new RegistrationIntentExpiredException;
        }
    }

    private function throwForTerminalOrExpired(AkubicaRegistrationIntent $intent): never
    {
        if ($intent->status === AkubicaRegistrationIntentStatus::Consumed) {
            throw new RegistrationIntentInvalidStateException('El intent de registro ya fue consumido.');
        }

        if ($intent->status === AkubicaRegistrationIntentStatus::Expired
            || ($intent->expires_at !== null && $intent->expires_at->isPast())
        ) {
            throw new RegistrationIntentExpiredException;
        }

        throw new RegistrationIntentInvalidStateException;
    }

    private function ensureCiphertextCleared(AkubicaRegistrationIntent $intent): void
    {
        if ($intent->encrypted_payload !== null) {
            AkubicaRegistrationIntent::query()
                ->where('id', $intent->id)
                ->update(['encrypted_payload' => null]);
        }
    }

    private function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '***';
        }

        [$local, $domain] = explode('@', $email, 2);
        $prefix = $local !== '' ? substr($local, 0, 1) : '*';

        return $prefix.'***@'.$domain;
    }
}
