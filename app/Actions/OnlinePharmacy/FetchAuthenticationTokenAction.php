<?php

namespace App\Actions\OnlinePharmacy;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class FetchAuthenticationTokenAction
{
    public function __invoke(): string
    {
        $accessToken = Cache::get('vitau_access_token');

        if ($accessToken) {
            return $accessToken;
        }

        $refreshToken = Cache::get('vitau_refresh_token');

        if ($refreshToken) {
            return $this->refreshAccessToken();
        }

        return $this->authenticate();
    }

    private function authenticate(): string
    {
        $url = config('services.vitau.url') . 'token/';

        $response = Http::withHeaders([
            'x-api-key' => config('services.vitau.key'),
        ])->post($url, [
            'email' => config('services.vitau.email'),
            'password' => config('services.vitau.password'),
        ]);

        if ($response->failed()) {
            throw new Exception('Authentication failed.');
        }

        $tokens = $response->json();
        $this->storeTokens($tokens['access'], $tokens['refresh']);

        return $tokens['access'];
    }

    private function refreshAccessToken(): string
    {
        $url = config('services.vitau.url') . 'token/refresh/';

        $response = Http::withHeaders([
            'x-api-key' => config('services.vitau.key'),
        ])->post($url, [
            'refresh' => Cache::get('vitau_refresh_token'),
        ]);

        if ($response->failed()) {
            return $this->authenticate();
        }

        $tokens = $response->json();
        $this->storeTokens($tokens['access']);

        return $tokens['access'];
    }

    private function storeTokens($accessToken, $refreshToken = null): void
    {
        // Decode the access token to determine expiration time
        $accessTokenPayload = $this->decodeJwt($accessToken);
        $accessTokenExpiresAt = now()->addSeconds($accessTokenPayload['access_lifetime'] ?? 300);

        Cache::put('vitau_access_token', $accessToken, $accessTokenExpiresAt);

        if ($refreshToken) {
            $refreshTokenExpiresAt = now()->addHours(24);

            Cache::put('vitau_refresh_token', $refreshToken, $refreshTokenExpiresAt);
        }
    }

    private function decodeJwt(string $jwt): array
    {
        try {
            [$header, $payload, $signature] = explode('.', $jwt);
            return json_decode(base64_decode($payload), true) ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }
}
