<?php

namespace App\DTOs\AutomationOperations;

readonly class AutomationTimelineItem
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function __construct(
        public string $id,
        public string $occurredAt,
        public string $automation,
        public ?string $driver,
        public string $result,
        public ?int $durationMs,
        public ?bool $retryable,
        public ?string $channel = null,
        public ?string $operation = null,
        public ?string $reference = null,
        public string $source = 'events',
        public ?array $meta = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'occurred_at' => $this->occurredAt,
            'automation' => $this->automation,
            'driver' => $this->driver,
            'result' => $this->result,
            'duration_ms' => $this->durationMs,
            'retryable' => $this->retryable,
            'channel' => $this->channel,
            'operation' => $this->operation,
            'reference' => $this->reference,
            'source' => $this->source,
            'meta' => $this->meta,
        ];
    }
}
