<?php

namespace App\Support;

class TrackingPathSanitizer
{
    /** @var list<string> */
    private const BLOCKED_PREFIXES = [
        '/admin',
        '/api',
        '/apigda',
        '/auth',
        '/derechos-arco',
        '/descargar-documento',
        '/efevoo',
        '/efevoopay',
        '/forgot-password',
        '/invoice',
        '/invoice-requests',
        '/laboratory/webhook',
        '/login',
        '/logout',
        '/magic-login',
        '/mis-solicitudes-arco',
        '/payment-methods/3ds',
        '/paypal',
        '/register/invitation',
        '/reset-password',
        '/solicitud-arco',
        '/tax-profiles',
        '/verify-email',
        '/verify-phone',
        '/webhook',
        '/webhooks',
    ];

    /** @var list<string> */
    private const BLOCKED_EXACT_PATHS = [
        '/confirm-password',
        '/email/verification-notification',
        '/phone/verification-notification',
    ];

    /** @var list<string> */
    private const SENSITIVE_QUERY_KEYS = [
        'code',
        'email',
        'expires',
        'hash',
        'redirect',
        'signature',
        'state',
        'session',
        'session_id',
        'token',
    ];

    public static function sanitizeTrackingPath(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $parts = parse_url($value);
        if ($parts === false) {
            return null;
        }

        $query = is_string($parts['query'] ?? null) ? $parts['query'] : '';
        if (self::hasSensitiveQuery($query)) {
            return null;
        }

        $path = is_string($parts['path'] ?? null) ? $parts['path'] : '/';
        $path = self::normalizePath($path);

        if ($path === null || self::isBlockedPath($path)) {
            return null;
        }

        return $path;
    }

    public static function isBlockedPath(string $path): bool
    {
        $path = self::normalizePath($path);
        if ($path === null) {
            return true;
        }

        if (in_array($path, self::BLOCKED_EXACT_PATHS, true)) {
            return true;
        }

        foreach (self::BLOCKED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        $segments = array_values(array_filter(explode('/', $path)));
        foreach ($segments as $segment) {
            if (str_contains(mb_strtolower($segment), 'otp')) {
                return true;
            }
        }

        if (
            in_array('laboratory-purchases', $segments, true) &&
            array_intersect($segments, ['results', 'results-automatic-fetch', 'download-pdf', 'email-pdf']) !== []
        ) {
            return true;
        }

        return false;
    }

    private static function normalizePath(string $path): ?string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path);
        $path = $path !== '/' ? rtrim($path, '/') : $path;

        if (! is_string($path) || $path === '') {
            return null;
        }

        return mb_substr($path, 0, 512);
    }

    private static function hasSensitiveQuery(string $query): bool
    {
        if ($query === '') {
            return false;
        }

        parse_str($query, $params);

        foreach (array_keys($params) as $key) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, self::SENSITIVE_QUERY_KEYS, true)) {
                return true;
            }

            if (str_contains($normalized, 'token') || str_contains($normalized, 'otp')) {
                return true;
            }
        }

        return false;
    }
}
