<?php

namespace App\Services\AutomationOperations;

use App\Models\AutomationOperationEvent;
use Illuminate\Support\Facades\Schema;

/**
 * Writes telemetry rows for the Operations Center.
 * Safe to call from Diagnostics or (later) from automation layers without changing business logic.
 */
class AutomationOperationsRecorder
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function record(array $attributes): ?AutomationOperationEvent
    {
        if (! Schema::hasTable('automation_operation_events')) {
            return null;
        }

        return AutomationOperationEvent::query()->create([
            'automation' => $attributes['automation'],
            'driver' => $attributes['driver'] ?? null,
            'channel' => $attributes['channel'] ?? null,
            'operation' => $attributes['operation'] ?? null,
            'result' => $attributes['result'],
            'duration_ms' => $attributes['duration_ms'] ?? null,
            'retryable' => $attributes['retryable'] ?? null,
            'customer_id' => $attributes['customer_id'] ?? null,
            'subject_type' => $attributes['subject_type'] ?? null,
            'subject_id' => $attributes['subject_id'] ?? null,
            'reference' => $attributes['reference'] ?? null,
            'meta' => $attributes['meta'] ?? null,
            'occurred_at' => $attributes['occurred_at'] ?? now(),
        ]);
    }
}
