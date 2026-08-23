<?php

namespace App\Support;

use App\Models\Efevoo3dsSession;
use App\Models\PaymentAuthenticationAttempt;

class PaymentAuthentication3dsStartResource
{
    public static function make(
        PaymentAuthenticationAttempt $attempt,
        ?Efevoo3dsSession $session = null,
        bool $includeChallenge = false
    ): array {
        $session ??= $attempt->efevoo3dsSession;

        $payload = [
            'attempt_uuid' => $attempt->attempt_uuid,
            'support_reference' => $attempt->support_reference,
            'status' => $attempt->status,
            'session_id' => $session?->id,
            'redirect_url' => $session
                ? route('payment-methods.3ds-redirect', ['sessionId' => $session->id])
                : null,
            'status_url' => $session
                ? route('payment-methods.3ds-status', ['sessionId' => $session->id])
                : null,
            'expires_at' => $attempt->expires_at?->toISOString(),
        ];

        if ($includeChallenge && $session) {
            $payload['url3ds'] = $session->url_3dsecure;
            $payload['token3ds'] = $session->token_3dsecure;
        }

        return $payload;
    }
}
