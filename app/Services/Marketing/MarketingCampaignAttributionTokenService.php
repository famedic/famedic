<?php

namespace App\Services\Marketing;

class MarketingCampaignAttributionTokenService
{
    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
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
