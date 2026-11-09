<?php

namespace App\Services\Api\V1\Idempotency;

use App\Services\Otp\OtpAbuseKeyHasher;
use App\Services\Otp\Registration\MexicoPhoneNormalizer;
use Illuminate\Http\Request;
use Throwable;

/**
 * Resolves opaque actor_key for idempotency isolation.
 *
 * Authenticated: customer:{id}
 * Public auth: HMAC of operation + normalized identity (never plaintext).
 */
final class IdempotencyActorResolver
{
    public function __construct(
        private readonly OtpAbuseKeyHasher $hasher,
        private readonly MexicoPhoneNormalizer $phoneNormalizer,
    ) {}

    /**
     * @return non-empty-string|null null when actor cannot be resolved safely
     */
    public function resolve(Request $request): ?string
    {
        $backend = $request->attributes->get(\App\Http\Middleware\Api\V1\AuthenticateOtpBackend::ATTRIBUTE);
        if ($backend instanceof \App\Services\Api\V1\Auth\AuthenticatedBackendClient) {
            $fresh = app(\App\Contracts\Api\V1\Auth\BackendClientAuthenticator::class)->authenticate($request);
            if (! $fresh || $fresh->consumerId !== $backend->consumerId || $fresh->channelId !== $backend->channelId) {
                throw new \App\Exceptions\PaymentLinkAuthorizationDenied(\App\Http\Responses\ApiResponse::error('UNAUTHENTICATED', 'No autenticado.', 401));
            }
            $public = $this->legacyOtpActor($request);

            return $public ? 'backend-public:'.hash('sha256', json_encode([$fresh->consumerId, $fresh->channelId, $public], JSON_THROW_ON_ERROR)) : null;
        }
        $user = $request->user();
        $customer = $user?->customer;

        if ($customer !== null) {
            if ($request->attributes->has(\App\Services\Api\V1\Auth\ConsumerRequestScope::ATTRIBUTE)) {
                $context = $request->attributes->get(\App\Services\Api\V1\Auth\ConsumerRequestScope::ATTRIBUTE);
                $token = $user instanceof \App\Models\User ? $user->currentAccessToken() : null;
                if ($context instanceof \App\Services\Api\V1\Auth\ConsumerAuthorizationContext
                    && $user instanceof \App\Models\User
                    && $token instanceof \Laravel\Sanctum\PersonalAccessToken
                    && $context->matches($user, $token, now()->timestamp)) {
                    return \App\Services\Api\V1\Auth\ConsumerRequestScope::actorKey((int) $customer->id, $context);
                }
                throw new \App\Exceptions\PaymentLinkAuthorizationDenied(\App\Http\Responses\ApiResponse::error(
                    'CONSUMER_NOT_AUTHORIZED', 'Operación no autorizada.', 403,
                ));
            }

            return 'customer:'.(string) $customer->id;
        }

        // Public routes only — never invent an actor from IP alone.
        $routeName = (string) ($request->route()?->getName() ?? '');

        return match (true) {
            str_contains($routeName, 'auth.login.request-code') => $this->publicFromPhone(
                $request,
                'login.request-code'
            ),
            str_contains($routeName, 'auth.register')
                && ! str_contains($routeName, 'verify')
                && ! str_contains($routeName, 'resend') => $this->publicFromRegister($request),
            default => null,
        };
    }

    /**
     * @return non-empty-string|null
     */
    public function legacyOtpActor(Request $request): ?string
    {
        return $this->publicFromPhone($request, 'login.request-code');
    }

    private function publicFromPhone(Request $request, string $operation): ?string
    {
        $phone = $request->input('phone');
        $email = $request->input('email');

        if (is_string($phone) && trim($phone) !== '') {
            try {
                $normalized = $this->phoneNormalizer->normalize(
                    $phone,
                    is_string($request->input('phone_country'))
                        ? $request->input('phone_country')
                        : MexicoPhoneNormalizer::DEFAULT_COUNTRY,
                );
                $comparisonKey = $normalized->comparisonKey();
            } catch (Throwable) {
                // Best-effort fallback: trimmed digits only for hashing material.
                $comparisonKey = preg_replace('/\D+/', '', $phone) ?? '';
                if ($comparisonKey === '') {
                    return null;
                }
            }

            $digest = $this->hasher->hashOpaque(
                'idempotency|v1|'.$operation,
                $comparisonKey
            );

            return 'public:'.$digest;
        }

        if (is_string($email) && trim($email) !== '') {
            $digest = $this->hasher->hashOpaque(
                'idempotency|v1|'.$operation.'|email',
                mb_strtolower(trim($email))
            );

            return 'public:'.$digest;
        }

        return null;
    }

    /**
     * @return non-empty-string|null
     */
    private function publicFromRegister(Request $request): ?string
    {
        $phone = $request->input('phone');
        $email = $request->input('email');

        if (! is_string($phone) || trim($phone) === '') {
            return null;
        }

        try {
            $normalized = $this->phoneNormalizer->normalize(
                $phone,
                is_string($request->input('phone_country'))
                    ? $request->input('phone_country')
                    : MexicoPhoneNormalizer::DEFAULT_COUNTRY,
            );
            $comparisonKey = $normalized->comparisonKey();
        } catch (Throwable) {
            $comparisonKey = preg_replace('/\D+/', '', $phone) ?? '';
            if ($comparisonKey === '') {
                return null;
            }
        }

        $emailPart = is_string($email) ? mb_strtolower(trim($email)) : '';
        $material = $comparisonKey.'|'.$emailPart;
        $digest = $this->hasher->hashOpaque('idempotency|v1|register', $material);

        return 'public:'.$digest;
    }
}
