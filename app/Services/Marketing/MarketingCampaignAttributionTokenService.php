<?php

namespace App\Services\Marketing;

class MarketingCampaignAttributionTokenService
{
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function isValid(string $token): bool
    {
        return preg_match(self::TOKEN_PATTERN, trim($token)) === 1;
    }

    public function hash(string $token): string
    {
        $token = trim($token);

        if ($token === '') {
            return hash_hmac('sha256', '', (string) config('marketing-attribution.token_hash_key'));
        }

        return hash_hmac('sha256', $token, (string) config('marketing-attribution.token_hash_key'));
    }
}
