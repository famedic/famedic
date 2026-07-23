<?php

namespace App\Services\Otp;

use App\Exceptions\Otp\OtpChallengeInvalidatedException;
use App\Exceptions\Otp\OtpInvalidCodeException;
use App\Exceptions\Otp\OtpRateLimitExceededException;
use App\Exceptions\Otp\OtpTemporarilyBlockedException;
use App\Models\OtpChallenge;
use Illuminate\Support\Facades\DB;

/**
 * P0-A3 anti-abuse orchestration around OtpChallengeService.
 *
 * Separates rate-limit evaluation from challenge persistence.
 * Does NOT send SMS/email and is NOT wired into production controllers.
 *
 * Feature-flag decision:
 * - Domain methods always enforce when called (no silent open).
 * - Future productive callers must check otp.p0a.flags.anti_abuse_enabled
 *   before invoking this policy. Tests call the policy directly.
 */
class OtpAbusePolicy
{
    public function __construct(
        private readonly OtpChallengeService $challenges,
        private readonly OtpRateLimitService $rateLimits,
    ) {
    }

    /**
     * Authorize + create challenge + record delivery attempt (no real delivery).
     * Invalidates previous active challenges via CreateOtpChallengeData flags.
     *
     * @throws OtpRateLimitExceededException
     * @throws OtpTemporarilyBlockedException
     */
    public function issue(
        CreateOtpChallengeData $data,
        OtpRequestContext $context,
    ): OtpChallengeCreationResult {
        $context = $this->alignContext($data, $context);

        $outcome = DB::transaction(function () use ($data, $context) {
            $decision = $this->rateLimits->evaluateIssueLocked($context);

            if (! $decision->allowed) {
                // Return deny without throwing so the transaction can commit
                // bucket state (e.g. blocked_until) before audit/exception.
                return ['decision' => $decision];
            }

            $result = $this->challenges->create($data);

            $this->rateLimits->commitAllowedIssueLocked($context, $result->challenge->id);
            // Same outer transaction: delivery counter rolls back with the challenge
            // if a later step fails (no partial OTP + send_count).
            $this->challenges->recordDeliveryAttempt($result->challenge->public_id);

            return [
                'result' => new OtpChallengeCreationResult(
                    $result->challenge->fresh(),
                    $result->plainCode(),
                ),
            ];
        }, OtpRateLimitService::TRANSACTION_ATTEMPTS);

        if (isset($outcome['decision']) && ! $outcome['decision']->allowed) {
            $this->rateLimits->persistDecisionAudit($context, $outcome['decision']);
            $this->throwFromDecision($outcome['decision']);
        }

        return $outcome['result'];
    }

    /**
     * Explicit resend alias — same authorization path as issue; creates a new code.
     *
     * @throws OtpRateLimitExceededException
     * @throws OtpTemporarilyBlockedException
     */
    public function resend(
        CreateOtpChallengeData $data,
        OtpRequestContext $context,
    ): OtpChallengeCreationResult {
        $dataWithInvalidate = new CreateOtpChallengeData(
            purpose: $data->purpose,
            channel: $data->channel,
            ttlMinutes: $data->ttlMinutes,
            userId: $data->userId,
            subjectType: $data->subjectType,
            subjectKey: $data->subjectKey,
            destinationNormalized: $data->destinationNormalized,
            destinationMasked: $data->destinationMasked,
            contextType: $data->contextType,
            contextId: $data->contextId,
            invalidatePreviousActive: true,
            meta: $data->meta,
            maxAttempts: $data->maxAttempts,
        );

        return $this->issue($dataWithInvalidate, $context);
    }

    /**
     * Verify via challenge service; on attempts exhausted, apply anti-abuse block.
     *
     * @throws OtpRateLimitExceededException when max attempts reached (after invalidation)
     */
    public function verify(
        string $publicId,
        string $code,
        OtpRequestContext $context,
    ): OtpChallenge {
        try {
            return $this->challenges->verify(
                publicId: $publicId,
                code: $code,
                purpose: $context->purpose,
                userId: $context->userId,
                contextType: $context->contextType,
                contextId: $context->contextId,
            );
        } catch (OtpChallengeInvalidatedException $e) {
            $challenge = OtpChallenge::query()->where('public_id', $publicId)->first();

            if ($challenge && $challenge->invalidated_reason === 'attempts_exhausted') {
                $decision = $this->rateLimits->recordMaxAttemptsExhausted(
                    $context,
                    $challenge->id,
                );

                throw new OtpRateLimitExceededException($decision);
            }

            throw $e;
        } catch (OtpInvalidCodeException $e) {
            throw $e;
        }
    }

    public function evaluate(OtpRequestContext $context): OtpRateLimitDecision
    {
        return $this->rateLimits->authorizeIssue($context);
    }

    private function alignContext(
        CreateOtpChallengeData $data,
        OtpRequestContext $context,
    ): OtpRequestContext {
        return new OtpRequestContext(
            purpose: $data->purpose,
            userId: $context->userId ?? $data->userId,
            subjectType: $context->subjectType ?? $data->subjectType,
            subjectKey: $context->subjectKey ?? $data->subjectKey,
            contextType: $context->contextType ?? $data->contextType,
            contextId: $context->contextId ?? $data->contextId,
            channel: $context->channel ?? $data->channel,
            clientIp: $context->clientIp,
            existingChallengePublicId: $context->existingChallengePublicId,
        );
    }

    private function throwFromDecision(OtpRateLimitDecision $decision): never
    {
        if ($decision->errorCode === OtpRateLimitDecision::CODE_BLOCKED
            || $decision->decision === 'blocked'
            || $decision->decision === 'blocked_request'
        ) {
            throw new OtpTemporarilyBlockedException($decision);
        }

        throw new OtpRateLimitExceededException($decision);
    }
}
