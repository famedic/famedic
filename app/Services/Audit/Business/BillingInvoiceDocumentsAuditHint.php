<?php

namespace App\Services\Audit\Business;

/**
 * Explicit, non-sensitive hint for auditing invoice document completion / replacement.
 *
 * Built by admin upload callers of CreateInvoiceAction. Scalars only —
 * never UploadedFile, Storage paths, Request, or Eloquent models.
 */
final class BillingInvoiceDocumentsAuditHint
{
    public const PURCHASE_TYPE_LABORATORY = 'laboratory_purchase';

    public const PURCHASE_TYPE_PHARMACY = 'online_pharmacy_purchase';

    /**
     * @var list<string>
     */
    public const PURCHASE_TYPES = [
        self::PURCHASE_TYPE_LABORATORY,
        self::PURCHASE_TYPE_PHARMACY,
    ];

    /**
     * @param  non-empty-string  $channel
     * @param  non-empty-string  $purchaseType
     */
    public function __construct(
        public readonly string $channel,
        public readonly string $purchaseType,
        public readonly int $purchaseId,
        public readonly int $actorAdminUserId,
        public readonly ?int $subjectCustomerId = null,
        public readonly ?string $correlationId = null,
    ) {}

    public static function isValidPurchaseType(string $purchaseType): bool
    {
        return in_array($purchaseType, self::PURCHASE_TYPES, true);
    }
}
