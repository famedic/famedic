<?php

namespace App\Support\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Opaque correlation id for Akubica API v1 requests (P1-A6).
 *
 * Never derived from user PII. Never sequential.
 */
final class AkubicaCorrelationId
{
    public const HEADER = 'X-Correlation-ID';

    public const REQUEST_ATTRIBUTE = 'akubica.correlation_id';

    /** Inclusive maximum length of a client-supplied value. */
    public const MAX_LENGTH = 128;

    /** Inclusive minimum length of a client-supplied value. */
    public const MIN_LENGTH = 8;

    public static function generate(): string
    {
        return (string) Str::uuid();
    }

    public static function isValid(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $length = strlen($value);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return false;
        }

        // Reject values that look like emails, phones, JWTs, or bearer material.
        if (str_contains($value, '@') || str_contains($value, ' ') || substr_count($value, '.') > 2) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9._-]+$/', $value);
    }

    /**
     * Accept a valid client header or generate a new opaque UUID.
     */
    public static function resolve(?string $incoming): string
    {
        $trimmed = is_string($incoming) ? trim($incoming) : null;

        if (self::isValid($trimmed)) {
            return $trimmed;
        }

        return self::generate();
    }

    public static function fromRequest(Request $request): string
    {
        $existing = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $resolved = self::resolve(self::incomingHeader($request));
        self::bind($request, $resolved);

        return $resolved;
    }

    /**
     * Current request correlation id, or a fresh UUID when outside the HTTP cycle.
     *
     * Prefer attribute (middleware). If missing — e.g. exception rendered without
     * middleware post-processing — resolve from the request header.
     */
    public static function currentOrGenerate(): string
    {
        $request = request();

        if ($request instanceof Request) {
            return self::fromRequest($request);
        }

        return self::generate();
    }

    public static function bind(Request $request, string $correlationId): void
    {
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $correlationId);
    }

    public static function incomingHeader(Request $request): ?string
    {
        $value = $request->header(self::HEADER);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        // Fallback for CGI-style server vars in some test harnesses.
        $server = $request->server->get('HTTP_X_CORRELATION_ID');

        return is_string($server) && $server !== '' ? $server : null;
    }
}
