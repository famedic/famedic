<?php

namespace App\Services\Api\V1\Auth;

use App\Contracts\Api\V1\Auth\AccountContextTokenIssuer;
use App\Models\AccountContext;
use App\Models\User;
use Illuminate\Support\Str;

final class AccountContextTokenService implements AccountContextTokenIssuer
{
    public function issue(User $user, string $phoneHash, ContactRecognitionBinding $binding, int $otpChallengeId): string
    {
        return $this->create($user, $phoneHash, $binding, $otpChallengeId, now());
    }

    public function issuePreOtp(User $user, string $phoneHash, ContactRecognitionBinding $binding, ?int $otpChallengeId = null): string
    {
        return $this->create($user, $phoneHash, $binding, $otpChallengeId, null);
    }

    public function ensurePreOtpContext(User $user, string $phoneHash, ContactRecognitionBinding $binding): AccountContext
    {
        $existing = $this->findActivePreOtp($phoneHash, $binding);
        if ($existing instanceof AccountContext && (int) $existing->user_id === (int) $user->id) {
            $existing->forceFill([
                'expires_at' => $this->expiresAt(),
            ])->save();

            return $existing->fresh() ?? $existing;
        }

        if ($existing instanceof AccountContext) {
            $existing->forceFill(['invalidated_at' => now()])->save();
        }

        $token = Str::random(64);
        $context = AccountContext::query()->create([
            'user_id' => $user->id,
            'otp_challenge_id' => null,
            'token_hash' => $this->tokenHash($token),
            'phone_hash' => $phoneHash,
            'conversation_hash' => $binding->conversationHash,
            'channel_event_hash' => $binding->channelEventHash,
            'channel' => $binding->channel,
            'audience' => $binding->audience,
            'expires_at' => $this->expiresAt(),
            'verified_at' => null,
        ]);

        return $context;
    }

    public function findActivePreOtp(string $phoneHash, ContactRecognitionBinding $binding): ?AccountContext
    {
        return AccountContext::query()
            ->where('phone_hash', $phoneHash)
            ->where('conversation_hash', $binding->conversationHash)
            ->where('channel_event_hash', $binding->channelEventHash)
            ->where('channel', $binding->channel)
            ->where('audience', $binding->audience)
            ->whereNull('verified_at')
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();
    }

    public function attachOtpChallenge(AccountContext $accountContext, int $otpChallengeId): bool
    {
        if ($accountContext->invalidated_at !== null || ! $accountContext->isActive()) {
            return false;
        }

        return $accountContext->forceFill([
            'otp_challenge_id' => $otpChallengeId,
        ])->save();
    }

    public function findPreOtpForChallenge(int $otpChallengeId, string $phoneHash, ContactRecognitionBinding $binding): ?AccountContext
    {
        $accountContext = AccountContext::query()
            ->where('otp_challenge_id', $otpChallengeId)
            ->whereNull('verified_at')
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $accountContext instanceof AccountContext) {
            return null;
        }

        if (! hash_equals($accountContext->phone_hash, $phoneHash)
            || ! hash_equals($accountContext->conversation_hash, $binding->conversationHash)
            || ! hash_equals($accountContext->channel_event_hash, $binding->channelEventHash)
            || ! hash_equals($accountContext->channel, $binding->channel)
            || ! hash_equals($accountContext->audience, $binding->audience)
        ) {
            return null;
        }

        return $accountContext;
    }

    public function elevateForChallenge(int $otpChallengeId, User $user): bool
    {
        return AccountContext::query()
            ->where('otp_challenge_id', $otpChallengeId)
            ->where('user_id', $user->id)
            ->whereNull('verified_at')
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', now())
            ->update(['verified_at' => now()]) === 1;
    }

    private function create(User $user, string $phoneHash, ContactRecognitionBinding $binding, ?int $otpChallengeId, mixed $verifiedAt): string
    {
        $token = Str::random(64);

        AccountContext::query()->create([
            'user_id' => $user->id,
            'otp_challenge_id' => $otpChallengeId,
            'token_hash' => $this->tokenHash($token),
            'phone_hash' => $phoneHash,
            'conversation_hash' => $binding->conversationHash,
            'channel_event_hash' => $binding->channelEventHash,
            'channel' => $binding->channel,
            'audience' => $binding->audience,
            'expires_at' => $this->expiresAt(),
            'verified_at' => $verifiedAt,
        ]);

        return $token;
    }

    public function resolve(string $token, string $phoneHash, ContactRecognitionBinding $binding): ?User
    {
        $accountContext = AccountContext::query()
            ->with('user')
            ->where('token_hash', $this->tokenHash($token))
            ->first();

        if (! $accountContext instanceof AccountContext || ! $accountContext->user instanceof User) {
            return null;
        }

        if ($accountContext->verified_at === null) {
            return null;
        }

        return $this->resolveMatchedUser($accountContext, $phoneHash, $binding);
    }

    public function resolvePreOtp(string $token, string $phoneHash, ContactRecognitionBinding $binding): ?User
    {
        $accountContext = AccountContext::query()
            ->with('user')
            ->where('token_hash', $this->tokenHash($token))
            ->whereNull('verified_at')
            ->first();

        if (! $accountContext instanceof AccountContext || ! $accountContext->user instanceof User) {
            return null;
        }

        return $this->resolveMatchedUser($accountContext, $phoneHash, $binding);
    }

    private function resolveMatchedUser(AccountContext $accountContext, string $phoneHash, ContactRecognitionBinding $binding): ?User
    {

        $matchesBinding = hash_equals($accountContext->phone_hash, $phoneHash)
            && hash_equals($accountContext->conversation_hash, $binding->conversationHash)
            && hash_equals($accountContext->channel_event_hash, $binding->channelEventHash)
            && hash_equals($accountContext->channel, $binding->channel)
            && hash_equals($accountContext->audience, $binding->audience);

        if (! $matchesBinding || ! $accountContext->isActive()) {
            if ($accountContext->invalidated_at === null) {
                $accountContext->forceFill(['invalidated_at' => now()])->save();
            }

            return null;
        }

        $user = $accountContext->user;
        if ($user->phone_verified_at === null || $user->customer === null) {
            $accountContext->forceFill(['invalidated_at' => now()])->save();

            return null;
        }

        return $user;
    }

    private function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function expiresAt(): \Illuminate\Support\Carbon
    {
        return now()->addMinutes((int) config('otp.p0a.account_context.ttl_minutes', 10));
    }
}
