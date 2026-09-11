<?php

namespace App\Support;

use Illuminate\Http\Request;

class ClientContext
{
    /**
     * @return array{device_type: string, browser: string, os: string, source: string}
     */
    public static function fromRequest(Request $request): array
    {
        return self::fromUserAgent((string) $request->userAgent());
    }

    /**
     * @return array{device_type: string, browser: string, os: string, source: string}
     */
    public static function fromUserAgent(?string $userAgent): array
    {
        $userAgent = trim((string) $userAgent);

        return [
            'device_type' => self::deviceType($userAgent),
            'browser' => self::browser($userAgent),
            'os' => self::os($userAgent),
            'source' => 'request_user_agent',
        ];
    }

    private static function deviceType(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'unknown';
        }

        if (preg_match('/iPad/i', $userAgent)) {
            return 'tablet';
        }

        if (preg_match('/Android/i', $userAgent) && ! preg_match('/Mobile/i', $userAgent)) {
            return 'tablet';
        }

        if (preg_match('/Android.*Mobile|iPhone|Mobile/i', $userAgent)) {
            return 'mobile';
        }

        if (preg_match('/Windows NT|Macintosh|CrOS/i', $userAgent)) {
            return 'desktop';
        }

        if (preg_match('/Linux/i', $userAgent) && ! preg_match('/Android/i', $userAgent)) {
            return 'desktop';
        }

        return 'unknown';
    }

    private static function browser(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'Unknown';
        }

        return match (true) {
            preg_match('/SamsungBrowser/i', $userAgent) === 1 => 'Samsung Internet',
            preg_match('/Edg|Edge/i', $userAgent) === 1 => 'Edge',
            preg_match('/OPR|Opera/i', $userAgent) === 1 => 'Opera',
            preg_match('/Chrome|CriOS/i', $userAgent) === 1 => 'Chrome',
            preg_match('/Firefox|FxiOS/i', $userAgent) === 1 => 'Firefox',
            preg_match('/Safari/i', $userAgent) === 1 => 'Safari',
            default => 'Other',
        };
    }

    private static function os(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'Unknown';
        }

        return match (true) {
            preg_match('/Android/i', $userAgent) === 1 => 'Android',
            preg_match('/iPhone|iPad|iPod/i', $userAgent) === 1 => 'iOS',
            preg_match('/Windows NT/i', $userAgent) === 1 => 'Windows',
            preg_match('/CrOS/i', $userAgent) === 1 => 'ChromeOS',
            preg_match('/Mac OS X|Macintosh/i', $userAgent) === 1 => 'macOS',
            preg_match('/Linux/i', $userAgent) === 1 => 'Linux',
            default => 'Other',
        };
    }
}
