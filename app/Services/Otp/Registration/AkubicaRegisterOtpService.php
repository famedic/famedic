<?php

namespace App\Services\Otp\Registration;

use App\Actions\Api\V1\Auth\IssueAkubicaTokenAction;
use App\Actions\Api\V1\Auth\RegisterAkubicaCustomerAction;
use App\Enums\AkubicaRegistrationIntentInvalidationReason;
use App\Enums\AkubicaRegistrationIntentStatus;
use App\Enums\P0aOtpChannel;
use App\Enums\P0aOtpPurpose;
use App\Exceptions\Otp\OtpChallengeConsumedException;
use App\Exceptions\Otp\OtpChallengeExpiredException;
use App\Exceptions\Otp\OtpChallengeInvalidatedException;
use App\Exceptions\Otp\OtpChallengeMismatchException;
use App\Exceptions\Otp\OtpChallengeNotFoundException;
use App\Exceptions\Otp\OtpConfigurationException;
use App\Exceptions\Otp\OtpIdentityNormalizationException;
use App\Exceptions\Otp\OtpInvalidCodeException;
use App\Exceptions\Otp\OtpRateLimitExceededException;
use App\Exceptions\Otp\OtpTemporaryUnavailableException;
use App\Exceptions\Otp\RegistrationCompletedLoginRequiredException;
use App\Exceptions\Otp\RegistrationIntentPayloadException;
use App\Models\AkubicaRegistrationIntent;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\Otp\MysqlContentionClassifier;
use App\Services\Otp\Delivery\AkubicaSecureOtpDeliveryOrchestrator;
use App\Services\Otp\OtpRateLimitDecision;
use App\Services\Otp\OtpRateLimitService;
use App\Services\Otp\OtpRequestContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * P0-A5.4/5.5/5.7A â€” Secure registration request/resend/verify + decoy (flag-gated).
 *
 * OTP delivery is gated by `sms_delivery` / `delivery_enabled` and runs synchronously after `createPending` commit. Verify creates User/RA/Customer atomically
 * with challenge+intent consume; Sanctum token is issued only after commit.
 * Token failure after commit â†’ LOGIN_REQUIRED (recover via P0-A4 login).
 *
 * Flag matrix:
 * - akubica_register_enabled=false â†’ callers use legacy RegisterController path.
 * - true + infrastructure + anti_abuse â†’ this service.
 * - true without deps â†’ OtpConfigurationException (never fail open).
 */
final class AkubicaRegisterOtpService
{
    public function __construct(
        private readonly AkubicaRegistrationIntentService $intentService,
        private readonly RegistrationCollisionResolver $collisionResolver,
        private readonly AkubicaRegisterOtpDecoyStore $decoyStore,
        private readonly RegisterAkubicaCustomerAction $registerAkubicaCustomerAction,
        private readonly IssueAkubicaTokenAction $issueAkubicaTokenAction,
        private readonly OtpRateLimitService $rateLimits,
        private readonly AkubicaRegistrationPayloadCipher $cipher,
        private readonly MysqlContentionClassifier $contentionClassifier,
        private readonly AkubicaSecureOtpDeliveryOrchestrator $deliveryOrchestrator,
    ) {
    }

    public static function isEnabled(): bool
    {
        return AkubicaRegistrationPolicy::isEnabled();
    }

    /**
     * @throws OtpConfigurationException
     */
    public function assertConfigurationReady(): void
    {
        if (! AkubicaRegistrationPolicy::isEnabled()) {
            throw new OtpConfigurationException(
                'El registro OTP P0-A no esta habilitado.',
                'OTP_CONFIGURATION_INVALID',
            );
        }

        if (! AkubicaRegistrationPolicy::antiAbuseEnabled()) {
            throw new OtpConfigurationException(
                'akubica_register_enabled requiere anti_abuse_enabled.',
                'OTP_CONFIGURATION_INVALID',
            );
        }

        if (! AkubicaRegistrationPolicy::infrastructureEnabled()) {
            throw new OtpConfigurationException(
                'akubica_register_enabled requiere infrastructure_enabled.',
                'OTP_CONFIGURATION_INVALID',
            );
        }
    }

    /**
     * Start secure registration: real challenge+intent or uniform decoy.
     *
     * @return array<string, mixed>
     *
     * @throws OtpConfigurationException
     * @throws OtpIdentityNormalizationException ambiguous phone â†’ 422 upstream
     * @throws OtpRateLimitExceededException
     */
    public function request(RegistrationIdentity $identity, ?string $clientIp): array
    {
        $this->assertConfigurationReady();

        $collision = $this->collisionResolver->resolve($identity->email, $identity->phone);

        if ($collision->kind === RegistrationCollisionKind::AmbiguousPhone
            || $collision->kind === RegistrationCollisionKind::InvalidIdentity
        ) {
            throw new OtpIdentityNormalizationException(
                'Los datos de registro no son validos.',
                'OTP_IDENTITY_INVALID',
            );
        }

        if ($collision->kind->shouldUseDecoy()) {
            return $this->decoyRequestResponse($identity);
        }

        try {
            $result = $this->intentService->createPending($identity, $clientIp);
        } catch (QueryException|UniqueConstraintViolationException $e) {
            $this->rethrowContention($e);
        } catch (\Throwable $e) {
            $this->rethrowIfContention($e);
            throw $e;
        }

        $this->dispatchDelivery($result, $identity);

        return $this->challengeResponsePayload(
            $result->challenge,
            AkubicaRegistrationPolicy::cooldownSeconds(),
        );
    }

    /**
     * Resend for a previously issued challenge_id (real or decoy).
     *
     * @return array<string, mixed>
     */
    public function resend(string $challengePublicId, ?string $clientIp): array
    {
        $this->assertConfigurationReady();

        $previous = OtpChallenge::query()->where('public_id', $challengePublicId)->first();
        if ($previous === null) {
            return $this->resendDecoy($challengePublicId);
        }

        if ($previous->purpose !== P0aOtpPurpose::AkubicaRegister->value
            || $previous->context_type !== AkubicaRegistrationIntentService::CONTEXT_TYPE
        ) {
            throw new OtpChallengeMismatchException;
        }

        $intent = $previous->registrationIntent;
        if ($intent === null) {
            throw new OtpChallengeMismatchException;
        }

        try {
            $payload = $this->intentService->readPayload((int) $intent->id);
            $identity = $payload->toRegistrationIdentity();
            $result = $this->intentService->createPending(
                $identity,
                $clientIp,
            );
        } catch (\App\Exceptions\Otp\RegistrationIntentInvalidStateException|
            \App\Exceptions\Otp\RegistrationIntentExpiredException|
            \App\Exceptions\Otp\RegistrationIntentNotFoundException
        ) {
            // Concurrent verify/consume won the race â€” safe public contract.
            throw new OtpChallengeInvalidatedException(
                'El codigo ya no es valido. Solicita uno nuevo.',
            );
        } catch (QueryException|UniqueConstraintViolationException $e) {
            $this->rethrowContention($e);
        } catch (\Throwable $e) {
            $this->rethrowIfContention($e);
            throw $e;
        }

        $this->dispatchDelivery($result, $identity);

        return $this->challengeResponsePayload(
            $result->challenge,
            AkubicaRegistrationPolicy::cooldownSeconds(),
        );
    }

    /**
     * Verify OTP, create User+RegularAccount+Customer atomically, then issue token.
     *
     * TX order (design Â§7): lock challenge â†’ validate OTP â†’ decrypt intent â†’
     * re-check collisions â†’ create accounts â†’ consume challenge â†’ consume intent
     * (erase ciphertext). Token is issued only after commit.
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

        if ($challenge->purpose !== P0aOtpPurpose::AkubicaRegister->value
            || $challenge->context_type !== AkubicaRegistrationIntentService::CONTEXT_TYPE
        ) {
            throw new OtpChallengeMismatchException;
        }

        $context = $this->requestContextFromChallenge($challenge, $clientIp, $challengePublicId);

        try {
            $outcome = DB::transaction(function () use ($challengePublicId, $code) {
                return $this->verifyAndProvisionLocked($challengePublicId, $code);
            }, OtpRateLimitService::TRANSACTION_ATTEMPTS);
        } catch (QueryException|UniqueConstraintViolationException $e) {
            $this->rethrowContention($e);
        } catch (\Throwable $e) {
            $this->rethrowIfContention($e);
            throw $e;
        }

        if (isset($outcome['error'])) {
            $this->throwVerifyOutcomeError($outcome, $context, $challengePublicId);
        }

        /** @var User $user */
        $user = $outcome['user'];

        try {
            $tokenData = ($this->issueAkubicaTokenAction)($user);
        } catch (\Throwable) {
            // Account + consume already committed (D11): recover via login OTP.
            throw new RegistrationCompletedLoginRequiredException;
        }

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
     * @return array{user: User}|array{error: class-string, message?: string, attempts_exhausted?: bool, challenge_id?: int}
     */
    private function verifyAndProvisionLocked(string $publicId, string $code): array
    {
        /** @var OtpChallenge|null $challenge */
        $challenge = OtpChallenge::query()
            ->where('public_id', $publicId)
            ->lockForUpdate()
            ->first();

        if (! $challenge) {
            return ['error' => OtpChallengeNotFoundException::class];
        }

        if ($challenge->purpose !== P0aOtpPurpose::AkubicaRegister->value
            || $challenge->context_type !== AkubicaRegistrationIntentService::CONTEXT_TYPE
        ) {
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
                'attempts_exhausted' => true,
                'challenge_id' => (int) $challenge->id,
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
                    'attempts_exhausted' => true,
                    'challenge_id' => (int) $challenge->id,
                ];
            }

            return ['error' => OtpInvalidCodeException::class];
        }

        /** @var AkubicaRegistrationIntent|null $intent */
        $intent = AkubicaRegistrationIntent::query()
            ->where('otp_challenge_id', $challenge->id)
            ->lockForUpdate()
            ->first();

        if ($intent === null) {
            return ['error' => OtpChallengeMismatchException::class];
        }

        if ($intent->status !== AkubicaRegistrationIntentStatus::Pending) {
            return $this->mapIntentTerminalToChallengeError($intent);
        }

        if ($intent->expires_at === null || $intent->expires_at->isPast()) {
            return ['error' => OtpChallengeExpiredException::class];
        }

        if ($intent->encrypted_payload === null || $intent->encrypted_payload === '') {
            $this->invalidateIntentAndChallenge(
                $intent,
                $challenge,
                AkubicaRegistrationIntentInvalidationReason::CorruptedPayload,
            );

            return ['error' => OtpInvalidCodeException::class];
        }

        try {
            $payload = $this->cipher->decrypt((string) $intent->encrypted_payload);
        } catch (RegistrationIntentPayloadException|\InvalidArgumentException) {
            $this->invalidateIntentAndChallenge(
                $intent,
                $challenge,
                AkubicaRegistrationIntentInvalidationReason::CorruptedPayload,
            );

            return ['error' => OtpInvalidCodeException::class];
        }

        $collision = $this->collisionResolver->resolve($payload->email, $payload->phone);
        if ($collision->kind !== RegistrationCollisionKind::Available) {
            $this->invalidateIntentAndChallenge(
                $intent,
                $challenge,
                AkubicaRegistrationIntentInvalidationReason::InconsistentAssociation,
            );

            return ['error' => OtpInvalidCodeException::class];
        }

        try {
            $user = ($this->registerAkubicaCustomerAction)([
                'email' => $payload->email->value(),
                'phone' => $payload->phone->nationalNumber(),
                'full_name' => $payload->fullName,
                'phone_country' => $payload->phone->countryCode(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            $this->invalidateIntentAndChallenge(
                $intent,
                $challenge,
                AkubicaRegistrationIntentInvalidationReason::InconsistentAssociation,
            );

            // Phone or email uniqueness â€” never enumerate which (anti-enumeration).
            return ['error' => OtpInvalidCodeException::class];
        }

        $updated = OtpChallenge::query()
            ->where('id', $challenge->id)
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', now())
            ->update(['consumed_at' => now()]);

        if ($updated !== 1) {
            throw new OtpChallengeConsumedException;
        }

        $intentConsumed = AkubicaRegistrationIntent::query()
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

        if ($intentConsumed !== 1) {
            throw new OtpChallengeConsumedException;
        }

        return ['user' => $user->fresh(['customer'])];
    }

    /**
     * @param  array{error: class-string, message?: string, attempts_exhausted?: bool, challenge_id?: int}  $outcome
     */
    private function throwVerifyOutcomeError(array $outcome, OtpRequestContext $context, string $publicId): never
    {
        $class = $outcome['error'];
        $message = $outcome['message'] ?? null;

        if (! empty($outcome['attempts_exhausted'])) {
            $challengeId = $outcome['challenge_id']
                ?? OtpChallenge::query()->where('public_id', $publicId)->value('id');

            $decision = $this->rateLimits->recordMaxAttemptsExhausted(
                $context,
                $challengeId !== null ? (int) $challengeId : null,
            );

            throw new OtpRateLimitExceededException($decision);
        }

        throw $message === null ? new $class : new $class($message);
    }

    /**
     * @return array{error: class-string}
     */
    private function mapIntentTerminalToChallengeError(AkubicaRegistrationIntent $intent): array
    {
        return match ($intent->status) {
            AkubicaRegistrationIntentStatus::Consumed => ['error' => OtpChallengeConsumedException::class],
            AkubicaRegistrationIntentStatus::Expired => ['error' => OtpChallengeExpiredException::class],
            AkubicaRegistrationIntentStatus::Invalidated,
            AkubicaRegistrationIntentStatus::Superseded => ['error' => OtpChallengeInvalidatedException::class],
            default => ['error' => OtpChallengeMismatchException::class],
        };
    }

    private function invalidateIntentAndChallenge(
        AkubicaRegistrationIntent $intent,
        OtpChallenge $challenge,
        AkubicaRegistrationIntentInvalidationReason $reason,
    ): void {
        AkubicaRegistrationIntent::query()
            ->where('id', $intent->id)
            ->where('status', AkubicaRegistrationIntentStatus::Pending)
            ->update([
                'status' => AkubicaRegistrationIntentStatus::Invalidated,
                'invalidated_at' => now(),
                'invalidation_reason' => $reason,
                'encrypted_payload' => null,
            ]);

        if ($challenge->invalidated_at === null && $challenge->consumed_at === null) {
            $challenge->update([
                'invalidated_at' => now(),
                'invalidated_reason' => 'registration_collision',
            ]);
        }
    }

    private function requestContextFromChallenge(
        OtpChallenge $challenge,
        ?string $clientIp,
        string $publicId,
    ): OtpRequestContext {
        return new OtpRequestContext(
            purpose: P0aOtpPurpose::AkubicaRegister,
            userId: null,
            subjectType: $challenge->subject_type ?? AkubicaRegistrationIntentService::SUBJECT_TYPE,
            subjectKey: (string) ($challenge->subject_key ?? ''),
            contextType: AkubicaRegistrationIntentService::CONTEXT_TYPE,
            contextId: $challenge->context_id,
            channel: P0aOtpChannel::tryFrom((string) $challenge->channel) ?? P0aOtpChannel::Email,
            clientIp: $clientIp,
            existingChallengePublicId: $publicId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function decoyRequestResponse(RegistrationIdentity $identity): array
    {
        $this->assertConfigurationReady();

        $ttlMinutes = AkubicaRegistrationPolicy::ttlMinutes();
        $cooldown = AkubicaRegistrationPolicy::cooldownSeconds();
        $now = now();
        $publicId = (string) Str::uuid();
        $expiresAt = $now->copy()->addMinutes($ttlMinutes);
        $resendAvailableAt = $now->copy()->addSeconds($cooldown);
        $masked = $this->maskPhone($identity->phone->e164() ?? $identity->phone->nationalNumber());

        $this->decoyStore->put($publicId, [
            'destination_masked' => $masked,
            'last_sent_at' => $now->getTimestamp(),
            'expires_at' => $expiresAt->getTimestamp(),
            'failed_attempts' => 0,
            'max_attempts' => AkubicaRegistrationPolicy::maxAttempts(),
            'invalidated_at' => null,
            'invalidation_reason' => null,
        ]);

        return [
            'requires_otp' => true,
            'challenge_id' => $publicId,
            'purpose' => P0aOtpPurpose::AkubicaRegister->value,
            'channel' => P0aOtpChannel::Sms->value,
            'destination_masked' => $masked,
            'expires_at' => $expiresAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'resend_available_at' => $resendAvailableAt->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Decoy verify: never succeeds; public errors mirror a real active challenge.
     *
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

        $invalidatedReason = $decoy['invalidation_reason'] ?? $decoy['invalidated_reason'] ?? null;

        if ($decoy['invalidated_at'] !== null) {
            if ($invalidatedReason === 'attempts_exhausted') {
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
            $decoy['invalidation_reason'] = 'attempts_exhausted';
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

        $cooldown = AkubicaRegistrationPolicy::cooldownSeconds();
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
                purpose: P0aOtpPurpose::AkubicaRegister->value,
            ));
        }

        $previous['invalidated_at'] = now()->getTimestamp();
        $previous['invalidation_reason'] = 'superseded';
        $this->decoyStore->put($publicId, $previous);

        $ttlMinutes = AkubicaRegistrationPolicy::ttlMinutes();
        $now = now();
        $newId = (string) Str::uuid();
        $expiresAt = $now->copy()->addMinutes($ttlMinutes);
        $resendAvailableAt = $now->copy()->addSeconds($cooldown);

        $this->decoyStore->put($newId, [
            'destination_masked' => $previous['destination_masked'],
            'last_sent_at' => $now->getTimestamp(),
            'expires_at' => $expiresAt->getTimestamp(),
            'failed_attempts' => 0,
            'max_attempts' => (int) ($previous['max_attempts'] ?? AkubicaRegistrationPolicy::maxAttempts()),
            'invalidated_at' => null,
            'invalidation_reason' => null,
        ]);

        return [
            'requires_otp' => true,
            'challenge_id' => $newId,
            'purpose' => P0aOtpPurpose::AkubicaRegister->value,
            'channel' => P0aOtpChannel::Sms->value,
            'destination_masked' => $previous['destination_masked'],
            'expires_at' => $expiresAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'resend_available_at' => $resendAvailableAt->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
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

    private function maxAttemptsDecision(): OtpRateLimitDecision
    {
        $retryAfter = max(1, (int) config('otp.p0a.anti_abuse.block_minutes', 15) * 60);

        return OtpRateLimitDecision::deny(
            errorCode: OtpRateLimitDecision::CODE_MAX_ATTEMPTS,
            publicMessage: 'Se agotaron los intentos. Solicita un codigo nuevo.',
            decision: 'max_attempts',
            scope: OtpRateLimitDecision::SCOPE_CHALLENGE,
            retryAfterSeconds: $retryAfter,
            availableAt: now()->addSeconds($retryAfter),
            purpose: P0aOtpPurpose::AkubicaRegister->value,
        );
    }

    /**
     * Map MySQL contention / uniqueness to the safe OTP public contract.
     * Never rethrows QueryException (no SQLSTATE / index / PII leakage).
     *
     * @throws OtpTemporaryUnavailableException
     * @throws OtpInvalidCodeException
     */
    private function rethrowContention(\Throwable $e): never
    {
        $classified = $this->contentionClassifier->classify($e);

        if ($classified['kind'] === MysqlContentionClassifier::KIND_DEADLOCK
            || $classified['kind'] === MysqlContentionClassifier::KIND_LOCK_WAIT_TIMEOUT) {
            throw new OtpTemporaryUnavailableException;
        }

        if ($classified['kind'] === MysqlContentionClassifier::KIND_DUPLICATE_KEY) {
            // Concurrent phone/email uniqueness â€” never reveal which field.
            throw new OtpInvalidCodeException;
        }

        throw new OtpTemporaryUnavailableException;
    }

    /**
     * Walk the exception chain for driver contention wrapped by other throwables.
     *
     * @throws OtpTemporaryUnavailableException
     * @throws OtpInvalidCodeException
     */
    private function rethrowIfContention(\Throwable $e): void
    {
        $cursor = $e;
        while ($cursor !== null) {
            if ($cursor instanceof QueryException || $cursor instanceof UniqueConstraintViolationException) {
                $this->rethrowContention($cursor);
            }
            $cursor = $cursor->getPrevious();
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

    private function dispatchDelivery(AkubicaRegistrationIntentCreationResult $result, RegistrationIdentity $identity): void
    {
        if (! AkubicaRegistrationPolicy::deliveryEnabled()) {
            return;
        }

        $this->deliveryOrchestrator->deliverRegisterSafely(
            $result->challenge,
            $result->plainCode(),
            $identity,
            (string) Str::uuid(),
        );
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $digits === '' ? '***' : '***'.substr($digits, -4);
    }
}
