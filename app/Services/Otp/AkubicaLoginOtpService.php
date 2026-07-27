<?php

namespace App\Services\Otp;

use App\Actions\Api\V1\Auth\IssueAkubicaTokenAction;
use App\Enums\P0aOtpChannel;
use App\Enums\P0aOtpPurpose;
use App\Exceptions\Otp\OtpChallengeExpiredException;
use App\Exceptions\Otp\OtpChallengeInvalidatedException;
use App\Exceptions\Otp\OtpChallengeMismatchException;
use App\Exceptions\Otp\OtpChallengeNotFoundException;
use App\Exceptions\Otp\OtpConfigurationException;
use App\Exceptions\Otp\OtpInvalidCodeException;
use App\Exceptions\Otp\OtpRateLimitExceededException;
use App\Models\OtpChallenge;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * P0-A4 — Akubica login OTP orchestration (flag-gated).
 *
 * Does NOT send Notification/Mail/SMS. Delivery adapters remain deferred.
 *
 * Flag matrix:
 * - akubica_login_enabled=false → callers must use legacy otp_codes path.
 * - akubica_login_enabled=true + anti_abuse_enabled=true → this service.
 * - akubica_login_enabled=true + anti_abuse_enabled=false → OtpConfigurationException
 *   (never fail open without anti-abuse).
 *
 * Decoy lifecycle (Cache): issued challenge_ids for unknown emails mimic real
 * verify/resend public contracts without creating User, otp_challenges, tokens,
 * or anti-abuse buckets. Never-issued random UUIDs may still return NO_ACTIVE_CODE;
 * that is intentional and documented (only request-code-issued IDs are the oracle surface).
 */
class AkubicaLoginOtpService
{
    public const CONTEXT_TYPE = 'akubica_login';

    public function __construct(
        private readonly OtpAbusePolicy $abusePolicy,
        private readonly IssueAkubicaTokenAction $issueAkubicaTokenAction,
        private readonly AkubicaLoginOtpDecoyStore $decoyStore,
    ) {
    }

    public static function isEnabled(): bool
    {
        return (bool) config('otp.p0a.flags.akubica_login_enabled', false);
    }

    /**
     * @throws OtpConfigurationException
     */
    public function assertConfigurationReady(): void
    {
        if (! self::isEnabled()) {
            throw new OtpConfigurationException(
                'El login OTP P0-A no esta habilitado.',
                'OTP_CONFIGURATION_INVALID',
            );
        }

        if (! (bool) config('otp.p0a.flags.anti_abuse_enabled', false)) {
            throw new OtpConfigurationException(
                'akubica_login_enabled requiere anti_abuse_enabled.',
                'OTP_CONFIGURATION_INVALID',
            );
        }
    }

    /**
     * Start login OTP for an existing user. Missing users are handled by decoyRequestResponse().
     *
     * @return array<string, mixed>
     */
    public function request(User $user, ?string $clientIp): array
    {
        $this->assertConfigurationReady();

        $email = strtolower(trim((string) $user->email));
        $ttlMinutes = (int) config('otp.p0a.policy.ttl_minutes', 5);
        $cooldown = (int) config('otp.p0a.policy.cooldown_seconds', 60);

        $data = new CreateOtpChallengeData(
            purpose: P0aOtpPurpose::AkubicaLogin,
            channel: P0aOtpChannel::Email,
            ttlMinutes: $ttlMinutes,
            userId: $user->id,
            subjectType: 'email',
            subjectKey: $email,
            destinationNormalized: $email,
            destinationMasked: null,
            contextType: self::CONTEXT_TYPE,
            contextId: $user->id,
            invalidatePreviousActive: true,
            meta: ['flow' => 'akubica_login'],
            maxAttempts: (int) config('otp.p0a.policy.max_attempts', 5),
        );

        $context = new OtpRequestContext(
            purpose: P0aOtpPurpose::AkubicaLogin,
            userId: $user->id,
            subjectType: 'email',
            subjectKey: $email,
            contextType: self::CONTEXT_TYPE,
            contextId: $user->id,
            channel: P0aOtpChannel::Email,
            clientIp: $clientIp,
        );

        $result = $this->abusePolicy->issue($data, $context);

        return $this->challengeResponsePayload($result->challenge, $cooldown);
    }

    /**
     * Anti-enumeration decoy when the email does not belong to a user.
     *
     * Issues an opaque UUID into ephemeral cache so later verify/resend mimic a real
     * challenge cycle. No User / otp_challenge / token / abuse bucket is created.
     *
     * @return array<string, mixed>
     */
    public function decoyRequestResponse(string $requestedEmail): array
    {
        $this->assertConfigurationReady();

        $ttlMinutes = (int) config('otp.p0a.policy.ttl_minutes', 5);
        $cooldown = (int) config('otp.p0a.policy.cooldown_seconds', 60);
        $now = now();
        $email = strtolower(trim($requestedEmail));
        $publicId = (string) Str::uuid();
        $expiresAt = $now->copy()->addMinutes($ttlMinutes);
        $resendAvailableAt = $now->copy()->addSeconds($cooldown);
        $masked = $this->maskEmailForPublicResponse($email);

        $this->decoyStore->put($publicId, [
            'destination_masked' => $masked,
            'last_sent_at' => $now->getTimestamp(),
            'expires_at' => $expiresAt->getTimestamp(),
            'failed_attempts' => 0,
            'max_attempts' => (int) config('otp.p0a.policy.max_attempts', 5),
            'invalidated_at' => null,
            'invalidated_reason' => null,
        ]);

        return [
            'requires_otp' => true,
            'challenge_id' => $publicId,
            'purpose' => P0aOtpPurpose::AkubicaLogin->value,
            'channel' => P0aOtpChannel::Email->value,
            'destination_masked' => $masked,
            'expires_at' => $expiresAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'resend_available_at' => $resendAvailableAt->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Verify OTP and issue Sanctum token only after atomic challenge consume.
     *
     * Consume is atomic inside OtpChallengeService (lockForUpdate + conditional
     * UPDATE where consumed_at IS NULL). createToken runs *after* that transaction
     * commits — there is a failure window: challenge consumed, no token returned.
     * Recovery: client must request/resend a new challenge (OTP cannot be reused).
     *
     * `expires_in` comes from IssueAkubicaTokenAction: seconds until expires_at
     * (Sanctum config expiration is minutes; the JSON field is remaining seconds).
     *
     * @return array{token: string, token_type: string, expires_in: int, expires_at: string, user: array<string, mixed>}
     */
    public function verify(string $challengePublicId, string $code, ?string $clientIp): array
    {
        $this->assertConfigurationReady();

        $challenge = OtpChallenge::query()->where('public_id', $challengePublicId)->first();
        if ($challenge === null) {
            $this->verifyDecoy($challengePublicId, $code);
        }

        assert($challenge instanceof OtpChallenge);

        if ($challenge->purpose !== P0aOtpPurpose::AkubicaLogin->value
            || $challenge->context_type !== self::CONTEXT_TYPE
            || $challenge->user_id === null
        ) {
            throw new OtpChallengeMismatchException;
        }

        $userId = (int) $challenge->user_id;
        $context = new OtpRequestContext(
            purpose: P0aOtpPurpose::AkubicaLogin,
            userId: $userId,
            subjectType: $challenge->subject_type,
            subjectKey: $challenge->subject_key,
            contextType: self::CONTEXT_TYPE,
            contextId: $challenge->context_id ?? $userId,
            channel: P0aOtpChannel::tryFrom((string) $challenge->channel) ?? P0aOtpChannel::Email,
            clientIp: $clientIp,
            existingChallengePublicId: $challengePublicId,
        );

        $this->abusePolicy->verify($challengePublicId, $code, $context);

        $user = User::query()->find($userId);
        if (! $user) {
            throw new OtpChallengeMismatchException('El codigo ingresado no es valido.');
        }

        $tokenData = ($this->issueAkubicaTokenAction)($user);

        return [
            ...$tokenData,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => trim($user->full_name) ?: $user->name,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resend(string $challengePublicId, ?string $clientIp): array
    {
        $this->assertConfigurationReady();

        $previous = OtpChallenge::query()->where('public_id', $challengePublicId)->first();
        if (! $previous) {
            return $this->resendDecoy($challengePublicId);
        }

        if ($previous->purpose !== P0aOtpPurpose::AkubicaLogin->value
            || $previous->context_type !== self::CONTEXT_TYPE
            || $previous->user_id === null
        ) {
            throw new OtpChallengeMismatchException;
        }

        $user = User::query()->find((int) $previous->user_id);
        if (! $user) {
            throw new OtpChallengeMismatchException;
        }

        $email = strtolower(trim((string) $user->email));
        $ttlMinutes = (int) config('otp.p0a.policy.ttl_minutes', 5);
        $cooldown = (int) config('otp.p0a.policy.cooldown_seconds', 60);

        $data = new CreateOtpChallengeData(
            purpose: P0aOtpPurpose::AkubicaLogin,
            channel: P0aOtpChannel::Email,
            ttlMinutes: $ttlMinutes,
            userId: $user->id,
            subjectType: 'email',
            subjectKey: $email,
            destinationNormalized: $email,
            destinationMasked: null,
            contextType: self::CONTEXT_TYPE,
            contextId: $user->id,
            invalidatePreviousActive: true,
            meta: ['flow' => 'akubica_login', 'resend_of' => $previous->public_id],
            maxAttempts: (int) config('otp.p0a.policy.max_attempts', 5),
        );

        $context = new OtpRequestContext(
            purpose: P0aOtpPurpose::AkubicaLogin,
            userId: $user->id,
            subjectType: 'email',
            subjectKey: $email,
            contextType: self::CONTEXT_TYPE,
            contextId: $user->id,
            channel: P0aOtpChannel::Email,
            clientIp: $clientIp,
            existingChallengePublicId: $challengePublicId,
        );

        $result = $this->abusePolicy->resend($data, $context);

        return $this->challengeResponsePayload($result->challenge, $cooldown);
    }

    /**
     * Decoy verify: never succeeds; public errors mirror a real active challenge.
     *
     * @throws OtpChallengeNotFoundException never-issued UUID
     * @throws OtpChallengeInvalidatedException superseded
     * @throws OtpChallengeExpiredException
     * @throws OtpRateLimitExceededException max attempts
     * @throws OtpInvalidCodeException wrong code (always for active decoys)
     */
    private function verifyDecoy(string $publicId, string $code): never
    {
        $decoy = $this->decoyStore->get($publicId);
        if ($decoy === null) {
            throw new OtpChallengeNotFoundException;
        }

        if ($decoy['invalidated_at'] !== null) {
            if (($decoy['invalidated_reason'] ?? null) === 'attempts_exhausted') {
                throw new OtpRateLimitExceededException($this->maxAttemptsDecision());
            }

            throw new OtpChallengeInvalidatedException;
        }

        if ($decoy['expires_at'] <= now()->getTimestamp()) {
            throw new OtpChallengeExpiredException;
        }

        // Decoys never hold a real OTP — any code is incorrect.
        unset($code);

        $decoy['failed_attempts'] = (int) $decoy['failed_attempts'] + 1;

        if ($decoy['failed_attempts'] >= (int) $decoy['max_attempts']) {
            $decoy['invalidated_at'] = now()->getTimestamp();
            $decoy['invalidated_reason'] = 'attempts_exhausted';
            $this->decoyStore->put($publicId, $decoy);

            throw new OtpRateLimitExceededException($this->maxAttemptsDecision());
        }

        $this->decoyStore->put($publicId, $decoy);

        throw new OtpInvalidCodeException;
    }

    /**
     * @return array<string, mixed>
     */
    private function resendDecoy(string $publicId): array
    {
        $previous = $this->decoyStore->get($publicId);
        if ($previous === null) {
            throw new OtpChallengeNotFoundException;
        }

        $cooldown = (int) config('otp.p0a.policy.cooldown_seconds', 60);
        $availableAt = Carbon::createFromTimestamp((int) $previous['last_sent_at'])
            ->utc()
            ->addSeconds($cooldown);

        if ($availableAt->gt(now())) {
            $retryAfter = max(1, $availableAt->getTimestamp() - now()->getTimestamp());

            throw new OtpRateLimitExceededException(OtpRateLimitDecision::deny(
                errorCode: OtpRateLimitDecision::CODE_COOLDOWN,
                publicMessage: 'Espera unos segundos antes de solicitar otro codigo.',
                decision: 'cooldown',
                scope: OtpRateLimitDecision::SCOPE_IDENTITY,
                retryAfterSeconds: $retryAfter,
                availableAt: $availableAt,
                purpose: P0aOtpPurpose::AkubicaLogin->value,
            ));
        }

        $previous['invalidated_at'] = now()->getTimestamp();
        $previous['invalidated_reason'] = 'superseded';
        $this->decoyStore->put($publicId, $previous);

        $ttlMinutes = (int) config('otp.p0a.policy.ttl_minutes', 5);
        $now = now();
        $newId = (string) Str::uuid();
        $expiresAt = $now->copy()->addMinutes($ttlMinutes);
        $resendAvailableAt = $now->copy()->addSeconds($cooldown);

        $this->decoyStore->put($newId, [
            'destination_masked' => $previous['destination_masked'],
            'last_sent_at' => $now->getTimestamp(),
            'expires_at' => $expiresAt->getTimestamp(),
            'failed_attempts' => 0,
            'max_attempts' => (int) ($previous['max_attempts'] ?? config('otp.p0a.policy.max_attempts', 5)),
            'invalidated_at' => null,
            'invalidated_reason' => null,
        ]);

        return [
            'requires_otp' => true,
            'challenge_id' => $newId,
            'purpose' => P0aOtpPurpose::AkubicaLogin->value,
            'channel' => P0aOtpChannel::Email->value,
            'destination_masked' => $previous['destination_masked'],
            'expires_at' => $expiresAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'resend_available_at' => $resendAvailableAt->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function maxAttemptsDecision(): OtpRateLimitDecision
    {
        $blockMinutes = (int) config('otp.p0a.policy.block_minutes', 30);
        $availableAt = now()->addMinutes($blockMinutes);
        $retryAfter = max(1, $availableAt->getTimestamp() - now()->getTimestamp());

        return OtpRateLimitDecision::deny(
            errorCode: OtpRateLimitDecision::CODE_MAX_ATTEMPTS,
            publicMessage: 'Se alcanzo el limite de intentos. Intenta mas tarde.',
            decision: 'max_attempts',
            scope: OtpRateLimitDecision::SCOPE_CHALLENGE,
            retryAfterSeconds: $retryAfter,
            availableAt: $availableAt,
            purpose: P0aOtpPurpose::AkubicaLogin->value,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function challengeResponsePayload(OtpChallenge $challenge, int $cooldownSeconds): array
    {
        $sentAt = $challenge->last_sent_at ?? now();
        $resendAvailableAt = $sentAt->copy()->addSeconds($cooldownSeconds);

        return [
            'requires_otp' => true,
            'challenge_id' => $challenge->public_id,
            'purpose' => $challenge->purpose,
            'channel' => $challenge->channel,
            'destination_masked' => $challenge->destination_masked,
            'expires_at' => $challenge->expires_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'resend_available_at' => $resendAvailableAt->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Same masking used by OtpChallengeService for email channels (public contract parity).
     */
    private function maskEmailForPublicResponse(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '***';
        }

        [$local, $domain] = explode('@', $email, 2);
        $prefix = substr($local, 0, 1);

        return $prefix.'***@'.$domain;
    }
}
