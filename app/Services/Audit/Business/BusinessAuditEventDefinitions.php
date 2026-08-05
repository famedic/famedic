<?php

namespace App\Services\Audit\Business;

use LogicException;

/**
 * Explicit event definitions for business audit.
 *
 * Block 6A: infrastructure.
 * Block 6B: commerce.laboratory_order_created.
 * Block 7B: billing.invoice_requested|completed|documents_replaced.
 * Tests may register temporary definitions only while `app()->environment('testing')`.
 *
 * Future examples (documentation only — not registered yet):
 * - customer.tax_profile_created
 */
final class BusinessAuditEventDefinitions
{
    public const EVENT_COMMERCE_LABORATORY_ORDER_CREATED = 'commerce.laboratory_order_created';

    public const EVENT_BILLING_INVOICE_REQUESTED = 'billing.invoice_requested';

    public const EVENT_BILLING_INVOICE_COMPLETED = 'billing.invoice_completed';

    public const EVENT_BILLING_INVOICE_DOCUMENTS_REPLACED = 'billing.invoice_documents_replaced';

    /**
     * Productive definitions.
     *
     * @var array<string, array{
     *     metadata: list<string>,
     *     outcomes?: list<string>,
     *     actor_types?: list<string>,
     *     channels?: list<string>,
     *     resource_types?: list<string>,
     *     subject_types?: list<string>
     * }>
     */
    private const DEFINITIONS = [
        self::EVENT_COMMERCE_LABORATORY_ORDER_CREATED => [
            'metadata' => [
                'fulfillment_origin',
            ],
            'outcomes' => [
                BusinessAuditOutcome::SUCCEEDED,
            ],
            'actor_types' => [
                BusinessAuditActor::TYPE_CUSTOMER,
                BusinessAuditActor::TYPE_INTEGRATION,
            ],
            'channels' => [
                BusinessAuditChannel::WEB_CHECKOUT,
                BusinessAuditChannel::INTEGRATION_WEBHOOK,
            ],
            'resource_types' => [
                'laboratory_purchase',
            ],
            'subject_types' => [
                BusinessAuditSubject::TYPE_CUSTOMER,
            ],
        ],
        self::EVENT_BILLING_INVOICE_REQUESTED => [
            'metadata' => [
                'request_origin',
                'purchase_type',
                'purchase_id',
            ],
            'outcomes' => [
                BusinessAuditOutcome::SUCCEEDED,
            ],
            'actor_types' => [
                BusinessAuditActor::TYPE_CUSTOMER,
            ],
            'channels' => [
                BusinessAuditChannel::WEB_CHECKOUT,
                BusinessAuditChannel::API_V1,
            ],
            'resource_types' => [
                'invoice_request',
            ],
            'subject_types' => [
                BusinessAuditSubject::TYPE_CUSTOMER,
            ],
        ],
        self::EVENT_BILLING_INVOICE_COMPLETED => [
            'metadata' => [
                'purchase_type',
                'purchase_id',
            ],
            'outcomes' => [
                BusinessAuditOutcome::SUCCEEDED,
            ],
            'actor_types' => [
                BusinessAuditActor::TYPE_ADMIN,
            ],
            'channels' => [
                BusinessAuditChannel::ADMIN_WEB,
            ],
            'resource_types' => [
                'invoice',
            ],
            'subject_types' => [
                BusinessAuditSubject::TYPE_CUSTOMER,
            ],
        ],
        self::EVENT_BILLING_INVOICE_DOCUMENTS_REPLACED => [
            'metadata' => [
                'purchase_type',
                'purchase_id',
                'pdf_replaced',
                'xml_replaced',
            ],
            'outcomes' => [
                BusinessAuditOutcome::SUCCEEDED,
            ],
            'actor_types' => [
                BusinessAuditActor::TYPE_ADMIN,
            ],
            'channels' => [
                BusinessAuditChannel::ADMIN_WEB,
            ],
            'resource_types' => [
                'invoice',
            ],
            'subject_types' => [
                BusinessAuditSubject::TYPE_CUSTOMER,
            ],
        ],
    ];
    /**
     * @var array<string, array{
     *     metadata: list<string>,
     *     outcomes?: list<string>,
     *     actor_types?: list<string>,
     *     channels?: list<string>,
     *     resource_types?: list<string>,
     *     subject_types?: list<string>
     * }>
     */
    private static array $testDefinitions = [];

    /**
     * Register a temporary definition for Pest/PHPUnit only.
     *
     * @param  array{
     *     metadata?: list<string>,
     *     outcomes?: list<string>,
     *     actor_types?: list<string>,
     *     channels?: list<string>,
     *     resource_types?: list<string>,
     *     subject_types?: list<string>
     * }  $definition
     */
    public static function registerTestDefinition(string $eventName, array $definition): void
    {
        if (! app()->environment('testing')) {
            throw new LogicException(
                'BusinessAuditEventDefinitions::registerTestDefinition is testing-only.'
            );
        }

        if ($eventName === '' || strlen($eventName) > 96) {
            throw new LogicException('test event_name length is invalid.');
        }

        if (str_starts_with($eventName, 'payment.')) {
            throw new LogicException('payment.* events are forbidden even as test fixtures.');
        }

        self::$testDefinitions[$eventName] = [
            'metadata' => array_values($definition['metadata'] ?? []),
            'outcomes' => $definition['outcomes'] ?? null,
            'actor_types' => $definition['actor_types'] ?? null,
            'channels' => $definition['channels'] ?? null,
            'resource_types' => $definition['resource_types'] ?? null,
            'subject_types' => $definition['subject_types'] ?? null,
        ];
    }

    public static function clearTestDefinitions(): void
    {
        self::$testDefinitions = [];
    }

    /**
     * @return array{
     *     metadata: list<string>,
     *     outcomes?: list<string>|null,
     *     actor_types?: list<string>|null,
     *     channels?: list<string>|null,
     *     resource_types?: list<string>|null,
     *     subject_types?: list<string>|null
     * }|null
     */
    public static function definition(string $eventName): ?array
    {
        if (isset(self::DEFINITIONS[$eventName])) {
            return self::DEFINITIONS[$eventName];
        }

        if (app()->environment('testing') && isset(self::$testDefinitions[$eventName])) {
            return self::$testDefinitions[$eventName];
        }

        return null;
    }

    public static function isKnownEvent(string $eventName): bool
    {
        return self::definition($eventName) !== null;
    }

    /**
     * @return list<string>
     */
    public static function allowedMetadataKeys(string $eventName): array
    {
        $definition = self::definition($eventName);

        return $definition['metadata'] ?? [];
    }

    /**
     * @return list<string>
     */
    public static function allowedOutcomes(string $eventName): array
    {
        $definition = self::definition($eventName);
        $fromDef = $definition['outcomes'] ?? null;

        if (is_array($fromDef) && $fromDef !== []) {
            return array_values(array_intersect($fromDef, BusinessAuditOutcome::all()));
        }

        return BusinessAuditOutcome::all();
    }

    /**
     * @return list<string>
     */
    public static function allowedActorTypes(string $eventName): array
    {
        $definition = self::definition($eventName);
        $fromDef = $definition['actor_types'] ?? null;

        if (is_array($fromDef) && $fromDef !== []) {
            return array_values(array_intersect($fromDef, BusinessAuditActor::types()));
        }

        return BusinessAuditActor::types();
    }

    /**
     * @return list<string>
     */
    public static function allowedChannels(string $eventName): array
    {
        $definition = self::definition($eventName);
        $fromDef = $definition['channels'] ?? null;

        if (is_array($fromDef) && $fromDef !== []) {
            return array_values(array_intersect($fromDef, BusinessAuditChannel::all()));
        }

        return BusinessAuditChannel::all();
    }

    /**
     * @return list<string>|null  null means any allowlisted subject type or null subject
     */
    public static function allowedSubjectTypes(string $eventName): ?array
    {
        $definition = self::definition($eventName);
        $fromDef = $definition['subject_types'] ?? null;

        if (is_array($fromDef)) {
            return array_values(array_intersect($fromDef, BusinessAuditSubject::TYPES));
        }

        return null;
    }

    /**
     * @return list<string>|null  null means any non-empty resource type string (length-bounded)
     */
    public static function allowedResourceTypes(string $eventName): ?array
    {
        $definition = self::definition($eventName);
        $fromDef = $definition['resource_types'] ?? null;

        if (is_array($fromDef)) {
            return array_values($fromDef);
        }

        return null;
    }

    /**
     * Productive event names only (excludes test registrations).
     *
     * @return list<string>
     */
    public static function productiveEventNames(): array
    {
        return array_keys(self::DEFINITIONS);
    }
}
