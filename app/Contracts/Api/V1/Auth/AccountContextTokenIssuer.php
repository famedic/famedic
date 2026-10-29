<?php

namespace App\Contracts\Api\V1\Auth;

use App\Models\User;
use App\Services\Api\V1\Auth\ContactRecognitionBinding;

interface AccountContextTokenIssuer
{
    /** Issue a pre-OTP context with greeting-only capability. */
    public function issuePreOtp(User $user, string $phoneHash, ContactRecognitionBinding $binding, ?int $otpChallengeId = null): string;

    /** Elevate the same pre-OTP context after the challenge is verified. */
    public function elevateForChallenge(int $otpChallengeId, User $user): bool;

    public function findActivePreOtp(string $phoneHash, ContactRecognitionBinding $binding): ?\App\Models\AccountContext;

    public function ensurePreOtpContext(User $user, string $phoneHash, ContactRecognitionBinding $binding): \App\Models\AccountContext;

    public function attachOtpChallenge(\App\Models\AccountContext $accountContext, int $otpChallengeId): bool;

    public function findPreOtpForChallenge(int $otpChallengeId, string $phoneHash, ContactRecognitionBinding $binding): ?\App\Models\AccountContext;

    /**
     * Issue an opaque internal token after successful OTP verification.
     * The token must never be serialized into an HTTP or conversational response.
     */
    public function issue(User $user, string $phoneHash, ContactRecognitionBinding $binding, int $otpChallengeId): string;

    public function resolve(string $token, string $phoneHash, ContactRecognitionBinding $binding): ?User;

    public function resolvePreOtp(string $token, string $phoneHash, ContactRecognitionBinding $binding): ?User;
}
