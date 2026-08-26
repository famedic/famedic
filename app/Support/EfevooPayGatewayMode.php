<?php

namespace App\Support;

class EfevooPayGatewayMode
{
    public const MOCK = 'mock';

    public const TEST = 'test';

    public const LIVE = 'live';

    public static function current(): string
    {
        if (filter_var(config('efevoopay.force_simulation', false), FILTER_VALIDATE_BOOLEAN)) {
            return self::MOCK;
        }

        $configured = strtolower(trim((string) config('efevoopay.gateway', '')));

        if (in_array($configured, [self::MOCK, self::TEST, self::LIVE], true)) {
            return $configured;
        }

        return app()->environment('production') ? self::LIVE : self::MOCK;
    }

    public static function usesMock(): bool
    {
        return self::current() === self::MOCK;
    }

    public static function usesHttpGateway(): bool
    {
        return ! self::usesMock();
    }
}
