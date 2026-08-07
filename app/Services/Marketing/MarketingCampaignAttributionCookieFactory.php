<?php

namespace App\Services\Marketing;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class MarketingCampaignAttributionCookieFactory
{
    public function __construct(
        private MarketingCampaignAttributionTokenService $tokenService,
    ) {}

    public function readToken(Request $request): ?string
    {
        $raw = $request->cookie((string) config('marketing-attribution.cookie_name'));

        if (! is_string($raw)) {
            return null;
        }

        $token = trim($raw);

        if ($token === '' || mb_strlen($token) > 128) {
            return null;
        }

        if (! preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
            return null;
        }

        return $token;
    }

    public function makeCookie(string $token, CarbonInterface $expiresAt): Cookie
    {
        return Cookie::create(
            (string) config('marketing-attribution.cookie_name'),
            $token,
            $expiresAt->getTimestamp(),
            (string) config('marketing-attribution.cookie_path', '/'),
            null,
            (bool) config('marketing-attribution.secure', false),
            true,
            false,
            (string) config('marketing-attribution.cookie_same_site', 'lax'),
        );
    }

    public function tokenHashFromRequest(Request $request): ?string
    {
        $token = $this->readToken($request);

        if ($token === null) {
            return null;
        }

        return $this->tokenService->hash($token);
    }
}
