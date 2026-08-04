<?php

namespace App\Services\Audit\Business;

use App\Models\Audit\BusinessAuditEvent;
use InvalidArgumentException;

/**
 * Domain recorder for commerce.laboratory_order_created (Block 6B).
 *
 * Emit only after the laboratory-order domain transaction has committed.
 * Fail-soft via BusinessAuditEventWriter. Soft-dedupes by resource_key so
 * re-entry for the same purchase does not append a second succeeded row.
 */
final class LaboratoryOrderCreatedAuditRecorder
{
    public function __construct(
        private readonly BusinessAuditEventWriter $writer,
    ) {}

    /**
     * Record a confirmed laboratory purchase creation (outcome=succeeded only).
     */
    public function recordSucceeded(int $laboratoryPurchaseId, LaboratoryOrderCreatedAuditHint $hint): ?BusinessAuditEvent
    {
        if ($laboratoryPurchaseId < 1) {
            return null;
        }

        if (! $this->writer->enabled()) {
            return null;
        }

        $resourceKey = (string) $laboratoryPurchaseId;

        try {
            if ($this->alreadyRecorded($resourceKey)) {
                return null;
            }

            $actor = $this->resolveActor($hint);
            $subject = $this->resolveSubject($hint);
            $origin = $this->normalizeOrigin($hint->fulfillmentOrigin);

            if ($origin === null) {
                return null;
            }

            if (! BusinessAuditChannel::isValid($hint->channel)) {
                return null;
            }

            $context = new BusinessAuditContext(
                channel: $hint->channel,
                actor: $actor,
                correlationId: $hint->correlationId,
                subject: $subject,
            );

            return $this->writer->write([
                'event_name' => BusinessAuditEventDefinitions::EVENT_COMMERCE_LABORATORY_ORDER_CREATED,
                'outcome' => BusinessAuditOutcome::SUCCEEDED,
                'context' => $context,
                'resource_type' => 'laboratory_purchase',
                'resource_key' => $resourceKey,
                'correlation_id' => $hint->correlationId,
                'metadata' => [
                    'fulfillment_origin' => $origin,
                ],
            ]);
        } catch (\Throwable) {
            // Extra safety net: never break order creation. Writer already fail-softs.
            return null;
        }
    }

    private function alreadyRecorded(string $resourceKey): bool
    {
        return BusinessAuditEvent::query()
            ->where('event_name', BusinessAuditEventDefinitions::EVENT_COMMERCE_LABORATORY_ORDER_CREATED)
            ->where('resource_type', 'laboratory_purchase')
            ->where('resource_key', $resourceKey)
            ->exists();
    }

    private function resolveActor(LaboratoryOrderCreatedAuditHint $hint): BusinessAuditActor
    {
        return match ($hint->actorType) {
            BusinessAuditActor::TYPE_CUSTOMER => BusinessAuditActor::customer(
                (int) $hint->actorCustomerId,
                $hint->actorUserId
            ),
            BusinessAuditActor::TYPE_INTEGRATION => BusinessAuditActor::integration(
                (string) ($hint->integrationAlias ?? '')
            ),
            default => throw new InvalidArgumentException('unsupported laboratory order audit actor_type.'),
        };
    }

    private function resolveSubject(LaboratoryOrderCreatedAuditHint $hint): ?BusinessAuditSubject
    {
        if ($hint->subjectCustomerId === null || $hint->subjectCustomerId < 1) {
            return null;
        }

        return BusinessAuditSubject::customer($hint->subjectCustomerId);
    }

    private function normalizeOrigin(string $origin): ?string
    {
        return LaboratoryOrderCreatedAuditHint::isValidOrigin($origin) ? $origin : null;
    }
}
