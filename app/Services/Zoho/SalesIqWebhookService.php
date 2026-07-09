<?php

namespace App\Services\Zoho;

use App\Models\ZohoSalesIqEvent;
use App\Support\ZohoSalesIqWebhookPayloadSanitizer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class SalesIqWebhookService
{
    public function __construct(
        private ZohoSalesIqWebhookPayloadSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function record(string $defaultEventType, array $rawPayload): ZohoSalesIqEvent
    {
        $sanitized = $this->sanitizer->sanitize($rawPayload);

        $eventType = $this->stringOrNull($sanitized['event_type'] ?? null)
            ?? $this->stringOrNull($sanitized['event_name'] ?? null)
            ?? $defaultEventType;

        $occurredAt = $this->resolveOccurredAt(
            $sanitized['occurred_at'] ?? $sanitized['created_at'] ?? null
        );

        $event = ZohoSalesIqEvent::query()->create([
            'event_type' => mb_substr($eventType, 0, 64),
            'visitor_id' => $this->stringOrNull($sanitized['visitor_id'] ?? null),
            'conversation_id' => $this->stringOrNull($sanitized['conversation_id'] ?? null),
            'user_id' => $this->positiveIntOrNull($sanitized['user_id'] ?? null),
            'customer_id' => $this->positiveIntOrNull($sanitized['customer_id'] ?? null),
            'operator_name' => $this->stringOrNull($sanitized['operator_name'] ?? null),
            'department' => $this->stringOrNull($sanitized['department'] ?? null),
            'intent' => $this->stringOrNull($sanitized['intent'] ?? null),
            'last_event' => $this->stringOrNull($sanitized['last_event'] ?? null),
            'page' => $this->stringOrNull($sanitized['page'] ?? null),
            'environment' => $this->stringOrNull($sanitized['environment'] ?? null),
            'payload' => $sanitized,
            'occurred_at' => $occurredAt,
        ]);

        Log::info('Zoho SalesIQ webhook stored', [
            'id' => $event->id,
            'event_type' => $event->event_type,
            'visitor_id' => $event->visitor_id,
            'conversation_id' => $event->conversation_id,
            'intent' => $event->intent,
        ]);

        // Punto de extensión futuro (Fase 6): ActiveCampaign post-conversación.
        // No despachar tags ni contactos en Fase 4.

        return $event;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed !== '' ? $trimmed : null;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private function resolveOccurredAt(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return now();
        }

        try {
            return Carbon::parse((string) $value);
        } catch (Throwable) {
            return now();
        }
    }
}
