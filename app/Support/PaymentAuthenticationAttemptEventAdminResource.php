<?php

namespace App\Support;

use App\Models\PaymentAuthenticationAttemptEvent;
use App\Services\PaymentAuthenticationAttempts\PaymentAuthenticationAttemptDateRange;

class PaymentAuthenticationAttemptEventAdminResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(PaymentAuthenticationAttemptEvent $event): array
    {
        return [
            'id' => $event->id,
            'event_uuid' => $event->event_uuid,
            'event_type' => $event->event_type,
            'label' => PaymentAuthenticationAttemptAdminResource::eventLabel($event->event_type),
            'kind' => PaymentAuthenticationAttemptAdminResource::eventKind($event),
            'source' => $event->source,
            'status_from' => $event->status_from,
            'status_to' => $event->status_to,
            'result_category' => $event->result_category,
            'failure_origin' => $event->failure_origin,
            'failure_certainty' => $event->failure_certainty,
            'origin_label' => PaymentAuthenticationAttemptAdminResource::originLabel($event->failure_origin, $event->failure_certainty),
            'external_operation' => $event->external_operation,
            'external_call_number' => $event->external_call_number,
            'http_status' => $event->http_status,
            'duration_ms' => $event->duration_ms,
            'provider_status' => $event->provider_status,
            'provider_code' => $event->provider_code,
            'provider_message' => $event->provider_message,
            'metadata' => $event->allowlistedMetadata(),
            'occurred_at' => $event->occurred_at?->toISOString(),
            'occurred_at_local' => $event->occurred_at
                ? $event->occurred_at->copy()->timezone(PaymentAuthenticationAttemptDateRange::TIMEZONE)->isoFormat('D MMM Y, HH:mm:ss')
                : null,
        ];
    }
}
