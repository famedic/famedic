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
        $user = $request->user();
        $customer = $user?->customer;

        if ($customer !== null) {
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
