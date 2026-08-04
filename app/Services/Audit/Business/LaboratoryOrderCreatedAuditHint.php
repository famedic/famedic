<?php

namespace App\Services\Audit\Business;

/**
 * Explicit, non-sensitive hint for auditing a confirmed laboratory order creation.
 *
 * Built by callers of FulfillLaboratoryCartOrderAction. Contains only scalars —
 * never Request, Payment, Transaction, payloads, or Eloquent models.
 */
final class LaboratoryOrderCreatedAuditHint
{
    public const ORIGIN_WEB_CHECKOUT = 'web_checkout';

    public const ORIGIN_PAYPAL_CAPTURE = 'paypal_capture';

    public const ORIGIN_PAYPAL_WEBHOOK = 'paypal_webhook';

    /**
     * @var list<string>
     */
    public const ORIGINS = [
        self::ORIGIN_WEB_CHECKOUT,
        self::ORIGIN_PAYPAL_CAPTURE,
        self::ORIGIN_PAYPAL_WEBHOOK,
    ];

    /**
     * @param  non-empty-string  $channel  BusinessAuditChannel allowlisted value
     * @param  non-empty-string  $fulfillmentOrigin  One of ORIGINS
     * @param  non-empty-string  $actorType  customer|integration
     */
    public function __construct(
        public readonly string $channel,
        public readonly string $fulfillmentOrigin,
        public readonly string $actorType,
        public readonly ?int $actorCustomerId = null,
        public readonly ?int $actorUserId = null,
        public readonly ?string $integrationAlias = null,
        public readonly ?int $subjectCustomerId = null,
        public readonly ?string $correlationId = null,
    ) {}

    public static function isValidOrigin(string $origin): bool
    {
        return in_array($origin, self::ORIGINS, true);
    }
}
