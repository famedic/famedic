<?php

namespace App\Services\Audit\Business;

/**
 * Controlled technical origin channels for business audit events.
 *
 * Channel describes the technical origin. Provider-specific detail belongs
 * in actor_key aliases (e.g. integration:paypal) or future allowlisted metadata —
 * not as additional channel values.
 */
final class BusinessAuditChannel
{
    public const WEB_CHECKOUT = 'web_checkout';

    public const ADMIN_WEB = 'admin_web';

    public const API_V1 = 'api_v1';

    public const SYSTEM_JOB = 'system_job';

    public const CONSOLE = 'console';

    public const INTEGRATION_WEBHOOK = 'integration_webhook';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::WEB_CHECKOUT,
            self::ADMIN_WEB,
            self::API_V1,
            self::SYSTEM_JOB,
            self::CONSOLE,
            self::INTEGRATION_WEBHOOK,
        ];
    }

    public static function isValid(string $channel): bool
    {
        return in_array($channel, self::all(), true);
    }
}
