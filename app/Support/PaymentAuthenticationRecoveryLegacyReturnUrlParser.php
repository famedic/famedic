<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Temporary compatibility parser for open `return_url` values.
 *
 * Accept a legacy return URL only after it matches the same named-route allowlist
 * used by the new recovery context. Do not copy the raw URL into context_data.
 *
 * Removal: after checkout clients send structured origin params and existing
 * 3DS sessions no longer read `3ds_return_url_{sessionId}` from the session.
 */
class PaymentAuthenticationRecoveryLegacyReturnUrlParser
{
    /**
     * @return array{route_name: string, parameters: array<string, mixed>}|null
     */
    public function parse(?string $returnUrl): ?array
    {
        if (! is_string($returnUrl) || trim($returnUrl) === '') {
            return null;
        }

        $returnUrl = trim($returnUrl);

        if (preg_match('/^\s*javascript:/i', $returnUrl) === 1) {
            return null;
        }

        if (str_contains($returnUrl, "\0") || str_contains($returnUrl, "\r") || str_contains($returnUrl, "\n")) {
            return null;
        }

        $path = $this->relativePath($returnUrl);

        if ($path === null) {
            return null;
        }

        try {
            $matched = Route::getRoutes()->match(Request::create($path, 'GET'));
        } catch (\Throwable) {
            return null;
        }

        $routeName = $matched->getName();

        if (! PaymentAuthenticationRecoveryReturnRouteAllowlist::isAllowed($routeName)) {
            return null;
        }

        $parameters = $this->allowlistedParameters($matched->parameters(), $this->queryParameters($path));

        return [
            'route_name' => $routeName,
            'parameters' => $parameters,
        ];
    }

    public function isSafe(?string $returnUrl): bool
    {
        return $this->parse($returnUrl) !== null;
    }

    private function relativePath(string $returnUrl): ?string
    {
        if (preg_match('#^(https?:)?//#i', $returnUrl) === 1) {
            $parts = parse_url($returnUrl);

            if (! is_array($parts) || empty($parts['host'])) {
                return null;
            }

            $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

            if (! is_string($appHost) || strcasecmp($parts['host'], $appHost) !== 0) {
                return null;
            }

            $path = $parts['path'] ?? '/';
            $query = isset($parts['query']) ? '?'.$parts['query'] : '';

            return $path.$query;
        }

        if (Str::startsWith($returnUrl, ['data:', 'file:', 'vbscript:'])) {
            return null;
        }

        if (! str_starts_with($returnUrl, '/')) {
            return null;
        }

        return $returnUrl;
    }

    /**
     * @param  array<string, mixed>  $routeParameters
     * @param  array<string, mixed>  $queryParameters
     * @return array<string, mixed>
     */
    private function allowlistedParameters(array $routeParameters, array $queryParameters): array
    {
        $allowed = [
            'laboratory_brand',
            'contact',
            'contact_id',
            'address',
            'address_id',
            'appointment',
            'appointment_id',
            'coupon_id',
            'step',
        ];

        $merged = array_merge($queryParameters, $routeParameters);
        $safe = [];

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $merged) || $merged[$key] === null || $merged[$key] === '') {
                continue;
            }

            $safe[$key] = $merged[$key];
        }

        return $safe;
    }

    /**
     * @return array<string, mixed>
     */
    private function queryParameters(string $path): array
    {
        $query = parse_url($path, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return [];
        }

        parse_str($query, $parameters);

        return is_array($parameters) ? $parameters : [];
    }
}
