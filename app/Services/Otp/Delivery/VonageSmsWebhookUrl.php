<?php

namespace App\Services\Otp\Delivery;

final class VonageSmsWebhookUrl
{
    public static function deliveryReceiptCallback(): ?string
    {
        if (! (bool) config('vonage.sms_dlr.enabled', false)) {
            return null;
        }

        $token = trim((string) config('vonage.sms_dlr.webhook_token'));
        if ($token === '' || strlen($token) < 32) {
            return null;
        }

        $base = trim((string) (config('vonage.sms_dlr.callback_base_url') ?: config('app.url')));
        $base = rtrim($base, '/');

        if ($base === '' || ! str_starts_with(strtolower($base), 'https://')) {
            return null;
        }

        $path = '/webhooks/vonage/sms/delivery/'.$token;
        $url = $base.$path;

        $maxLength = max(100, (int) config('vonage.sms_dlr.callback_max_length', 200));
        if (strlen($url) > $maxLength) {
            return null;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $url;
    }

    /** Safe for logs: host + path prefix without token. */
    public static function redactedPath(): string
    {
        return '/webhooks/vonage/sms/delivery/[REDACTED]';
    }

    /**
     * Safe audit payload for staging validation (no token, no full URL).
     *
     * @return array{
     *     valid: bool,
     *     scheme: ?string,
     *     host: ?string,
     *     path_redacted: string,
     *     url_length: int,
     *     token_meets_minimum: bool,
     * }
     */
    public static function auditCallbackUrl(?string $url = null): array
    {
        $url ??= self::deliveryReceiptCallback();
        $token = trim((string) config('vonage.sms_dlr.webhook_token'));
        $maxLength = max(100, (int) config('vonage.sms_dlr.callback_max_length', 200));

        $scheme = is_string($url) ? parse_url($url, PHP_URL_SCHEME) : null;
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
        $length = is_string($url) ? strlen($url) : 0;

        $valid = is_string($url)
            && $url !== ''
            && strtolower((string) $scheme) === 'https'
            && is_string($host) && $host !== ''
            && str_contains($url, '/webhooks/vonage/sms/delivery/')
            && $length <= $maxLength
            && strlen($token) >= 32
            && filter_var($url, FILTER_VALIDATE_URL) !== false;

        return [
            'valid' => $valid,
            'scheme' => is_string($scheme) ? strtolower($scheme) : null,
            'host' => is_string($host) ? $host : null,
            'path_redacted' => self::redactedPath(),
            'url_length' => $length,
            'token_meets_minimum' => strlen($token) >= 32,
        ];
    }
}
