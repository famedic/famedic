<?php

namespace App\Support;

/**
 * Sanitiza payloads entrantes de webhooks Zoho SalesIQ.
 * No conserva tokens, OTP, tarjetas, secretos ni datos clínicos.
 */
class ZohoSalesIqWebhookPayloadSanitizer
{
    private const MAX_STRING_LENGTH = 500;

    private const MAX_KEYS = 40;

    private const FORBIDDEN_KEY_PATTERN =
        '/(password|token|otp|secret|card|cvv|cvc|pan|raw_response|gateway_response|remember|pdf|clinical|resultados?)/i';

    /**
     * Claves de primer nivel permitidas (whitelist).
     *
     * @var list<string>
     */
    private const ALLOWED_KEYS = [
        'event_type',
        'event_name',
        'visitor_id',
        'conversation_id',
        'operator_name',
        'operator_email',
        'department',
        'intent',
        'last_event',
        'page',
        'environment',
        'user_id',
        'customer_id',
        'cart_total_cents',
        'balance_amount_cents',
        'checkout_step',
        'safe_payment_error',
        'resolution',
        'occurred_at',
        'created_at',
        'brand',
        'checkout_type',
        'source',
        'topic',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $payload): array
    {
        $cleaned = [];

        foreach (self::ALLOWED_KEYS as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            if (preg_match(self::FORBIDDEN_KEY_PATTERN, $key)) {
                continue;
            }

            $value = $this->sanitizeValue($payload[$key]);

            if ($value !== null) {
                $cleaned[$key] = $value;
            }

            if (count($cleaned) >= self::MAX_KEYS) {
                break;
            }
        }

        return $cleaned;
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return null;
            }

            return mb_strlen($trimmed) > self::MAX_STRING_LENGTH
                ? mb_substr($trimmed, 0, self::MAX_STRING_LENGTH).'…'
                : $trimmed;
        }

        if (is_array($value)) {
            // No anidar objetos arbitrarios: solo escalares planos.
            return null;
        }

        return null;
    }
}
