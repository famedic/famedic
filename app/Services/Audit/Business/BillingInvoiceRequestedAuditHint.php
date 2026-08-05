<?php

namespace App\Services\Audit\Business;

/**
 * Explicit, non-sensitive hint for auditing a first-time invoice request creation.
 *
 * Built by callers of CreateInvoiceRequestAction / CreateAkubicaInvoiceRequestAction.
 * Scalars only — never Request, TaxProfile, fiscal payloads, or Eloquent models.
 */
final class BillingInvoiceRequestedAuditHint
{
    public const ORIGIN_LABORATORY_WEB = 'laboratory_web';

    public const ORIGIN_PHARMACY_WEB = 'pharmacy_web';

    public const ORIGIN_API_V1 = 'api_v1';

    public const PURCHASE_TYPE_LABORATORY = 'laboratory_purchase';

    public const PURCHASE_TYPE_PHARMACY = 'online_pharmacy_purchase';

    /**
     * @var list<string>
     */
    public const ORIGINS = [
        self::ORIGIN_LABORATORY_WEB,
        self::ORIGIN_PHARMACY_WEB,
        self::ORIGIN_API_V1,
    ];

    /**
     * @var list<string>
     */
    public const PURCHASE_TYPES = [
        self::PURCHASE_TYPE_LABORATORY,
        self::PURCHASE_TYPE_PHARMACY,
    ];

    /**
     * @param  non-empty-string  $channel
     * @param  non-empty-string  $requestOrigin
     * @param  non-empty-string  $purchaseType
     */
    public function __construct(
        public readonly string $channel,
        public readonly string $requestOrigin,
        public readonly string $purchaseType,
        public readonly int $purchaseId,
        public readonly int $actorCustomerId,
        public readonly ?int $actorUserId = null,
        public readonly ?int $subjectCustomerId = null,
        public readonly ?string $correlationId = null,
    ) {}

    public static function isValidOrigin(string $origin): bool
    {
        return in_array($origin, self::ORIGINS, true);
    }

    public static function isValidPurchaseType(string $purchaseType): bool
    {
        return in_array($purchaseType, self::PURCHASE_TYPES, true);
    }
}
