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
use App\Exceptions\Otp\OtpDeliveryFailedException;
use App\Exceptions\Otp\OtpInvalidCodeException;
use App\Exceptions\Otp\OtpRateLimitExceededException;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\Otp\Delivery\AkubicaSecureOtpDeliveryOrchestrator;
use App\Services\Otp\Delivery\OtpDeliveryOutcome;
use App\Services\Otp\Registration\MexicoPhoneNormalizer;
use App\Services\Otp\Registration\PhoneIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * P0-A4 — Akubica login OTP orchestration (flag-gated).
 *
 * When enabled: existing users authenticate via SMS OTP (Vonage) using phone
 * identity. Never creates User/Customer. Never falls back to email silently.
 *
 * Flag matrix:
 * - akubica_login_enabled=false → callers must use legacy otp_codes email path.
 * - akubica_login_enabled=true + anti_abuse_enabled=true + sms_delivery_enabled=true → this service.
 * - Missing anti_abuse or sms_delivery → OtpConfigurationException (never fail open).
 *
 * Decoy lifecycle (Cache): issued challenge_ids for unknown phones mimic real
 * verify/resend public contracts without creating User, otp_challenges, tokens,
 * delivery ops, or anti-abuse buckets.
 */
class AkubicaLoginOtpService
{
    public const CONTEXT_TYPE = 'akubica_login';

    public function __construct(
        private readonly OtpAbusePolicy $abusePolicy,
        private readonly IssueAkubicaTokenAction $issueAkubicaTokenAction,
        private readonly AkubicaLoginOtpDecoyStore $decoyStore,
        private readonly AkubicaSecureOtpDeliveryOrchestrator $deliveryOrchestrator,
        private readonly MexicoPhoneNormalizer $phoneNormalizer,
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

        if (! (bool) config('otp.p0a.flags.sms_delivery_enabled', false)) {
            throw new OtpConfigurationException(
                'akubica_login_enabled requiere sms_delivery_enabled.',
                'OTP_CONFIGURATION_INVALID',
            );
        }
    }

    /**
     * Normalize phone for login request. Public for controller validation path.
     *
     * @throws \App\Exceptions\Otp\OtpIdentityNormalizationException
     */
    public function normalizePhone(string $rawPhone, ?string $phoneCountry = null): PhoneIdentity
    {
        return $this->phoneNormalizer->normalize($rawPhone, $phoneCountry);
    }

    /**
     * Resolve at most one eligible existing user for login. Zero or many → null (decoy).
     */
    public function findEligibleUser(PhoneIdentity $phone): ?User
    {
        $users = $this->findUsersByPhone($phone);
        if ($users->count() !== 1) {
            return null;
        }

        /** @var User $user */
        $user = $users->first();

        if ((bool) config('otp.p0a.policy.require_verified_phone', true)
            && $user->phone_verified_at === null
        ) {
            return null;
        }

        return $user;
    }

    /**
     * Start login OTP for an existing user. Missing users are handled by decoyRequestResponse().
     *
     * @return array<string, mixed>
     *
     * @throws OtpDeliveryFailedException
     */
    public function request(User $user, PhoneIdentity $phone, ?string $clientIp): array
    {
        $this->assertConfigurationReady();

        $subjectKey = $phone->comparisonKey();
        $destination = (string) $phone->e164();
        $ttlMinutes = (int) config('otp.p0a.policy.ttl_minutes', 5);
        $cooldown = (int) config('otp.p0a.policy.cooldown_seconds', 60);

        $data = new CreateOtpChallengeData(
            purpose: P0aOtpPurpose::AkubicaLogin,
            channel: P0aOtpChannel::Sms,
            ttlMinutes: $ttlMinutes,
            userId: $user->id,
            subjectType: 'phone',
            subjectKey: $subjectKey,
            destinationNormalized: $destination,
            destinationMasked: $this->maskPhone($destination),
            contextType: self::CONTEXT_TYPE,
            contextId: $user->id,
            invalidatePreviousActive: true,
            meta: ['flow' => 'akubica_login'],
            maxAttempts: (int) config('otp.p0a.policy.max_attempts', 5),
        );

        $context = new OtpRequestContext(
            purpose: P0aOtpPurpose::AkubicaLogin,
            userId: $user->id,
            subjectType: 'phone',
            subjectKey: $subjectKey,
            contextType: self::CONTEXT_TYPE,
            contextId: $user->id,
            channel: P0aOtpChannel::Sms,
            clientIp: $clientIp,
        );

        $result = $this->abusePolicy->issue($data, $context);
        $this->dispatchDelivery($result->challenge, $result->plainCode(), $destination);

        return $this->challengeResponsePayload($result->challenge->fresh(), $cooldown);
    }

    /**
     * Anti-enumeration decoy when the phone does not belong to an eligible user.
     *
     * @return array<string, mixed>
     */
    public function decoyRequestResponse(PhoneIdentity $phone): array
    {
        $this->assertConfigurationReady();

        $ttlMinutes = (int) config('otp.p0a.policy.ttl_minutes', 5);
        $cooldown = (int) config('otp.p0a.policy.cooldown_seconds', 60);
        $now = now();
        $publicId = (string) Str::uuid();
        $expiresAt = $now->copy()->addMinutes($ttlMinutes);
        $resendAvailableAt = $now->copy()->addSeconds($cooldown);
        $masked = $this->maskPhone((string) ($phone->e164() ?? $phone->nationalNumber()));

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
            'channel' => P0aOtpChannel::Sms->value,
            'destination_masked' => $masked,
            'expires_at' => $expiresAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'resend_available_at' => $resendAvailableAt->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Verify OTP and issue Sanctum token only after atomic challenge consume.
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
            channel: P0aOtpChannel::tryFrom((string) $challenge->channel) ?? P0aOtpChannel::Sms,
            clientIp: $clientIp,
            existingChallengePublicId: $challengePublicId,
        );

        $this->abusePolicy->verify($challengePublicId, $code, $context);

        $user = User::query()->find($userId);
        if (! $user) {
            throw new OtpChallengeMismatchException('El codigo ingresado no es valido.');
        }

        if ((bool) config('otp.p0a.policy.require_verified_phone', true)
            && $user->phone_verified_at === null
        ) {
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
     *
     * @throws OtpDeliveryFailedException
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

        $subjectKey = (string) $previous->subject_key;
        $destination = (string) $previous->destination_normalized;
        $ttlMinutes = (int) config('otp.p0a.policy.ttl_minutes', 5);
        $cooldown = (int) config('otp.p0a.policy.cooldown_seconds', 60);

        $data = new CreateOtpChallengeData(
            purpose: P0aOtpPurpose::AkubicaLogin,
            channel: P0aOtpChannel::Sms,
            ttlMinutes: $ttlMinutes,
            userId: $user->id,
            subjectType: 'phone',
            subjectKey: $subjectKey,
            destinationNormalized: $destination,
            destinationMasked: $previous->destination_masked ?? $this->maskPhone($destination),
            contextType: self::CONTEXT_TYPE,
            contextId: $user->id,
            invalidatePreviousActive: true,
            meta: ['flow' => 'akubica_login', 'resend_of' => $previous->public_id],
            maxAttempts: (int) config('otp.p0a.policy.max_attempts', 5),
        );

        $context = new OtpRequestContext(
            purpose: P0aOtpPurpose::AkubicaLogin,
            userId: $user->id,
            subjectType: 'phone',
            subjectKey: $subjectKey,
            contextType: self::CONTEXT_TYPE,
            contextId: $user->id,
            channel: P0aOtpChannel::Sms,
            clientIp: $clientIp,
            existingChallengePublicId: $challengePublicId,
        );

        $result = $this->abusePolicy->resend($data, $context);
        $this->dispatchDelivery($result->challenge, $result->plainCode(), $destination);

        return $this->challengeResponsePayload($result->challenge->fresh(), $cooldown);
    }

    /**
     * @throws OtpChallengeNotFoundException
     * @throws OtpChallengeInvalidatedException
     * @throws OtpChallengeExpiredException
     * @throws OtpRateLimitExceededException
     * @throws OtpInvalidCodeException
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
            'channel' => P0aOtpChannel::Sms->value,
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
     * @throws OtpDeliveryFailedException
     * @throws OtpConfigurationException
     */
    private function dispatchDelivery(OtpChallenge $challenge, string $plainCode, string $phoneE164): void
    {
        $outcome = $this->deliveryOrchestrator->deliverLoginSafely(
            $challenge,
            $plainCode,
            $phoneE164,
            (string) Str::uuid(),
        );

        if ($outcome === OtpDeliveryOutcome::Succeeded
            || $outcome === OtpDeliveryOutcome::DuplicateSuppressed
        ) {
            return;
        }

        // Skipped or Failed: login must not leave a usable challenge without SMS.
        $this->invalidateAfterDeliveryFailure($challenge);

        throw new OtpDeliveryFailedException;
    }

    private function invalidateAfterDeliveryFailure(OtpChallenge $challenge): void
    {
        if ($challenge->invalidated_at === null && $challenge->consumed_at === null) {
            $challenge->update([
                'invalidated_at' => now(),
                'invalidated_reason' => 'delivery_failed',
            ]);
        }
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $digits === '' ? '***' : '***'.substr($digits, -4);
    }

    /**
     * @return Collection<int, User>
     */
    private function findUsersByPhone(PhoneIdentity $phone): Collection
    {
        $national = $phone->nationalNumber();
        $country = $phone->countryCode();
        $legacyTrunk = '1'.$national;

        $query = User::query()
            ->where(function ($q) use ($national, $legacyTrunk) {
                $q->where('phone', $national)
                    ->orWhere('phone', $legacyTrunk)
                    ->orWhere('phone', '+52'.$national)
                    ->orWhere('phone', '+521'.$national)
                    ->orWhere('phone', '52'.$national)
                    ->orWhere('phone', '521'.$national);
            });

        $query->where(function ($q) use ($country) {
            $q->where('phone_country', $country)
                ->orWhereNull('phone_country')
                ->orWhere('phone_country', '');
        });

        return $query->get();
    }
}
