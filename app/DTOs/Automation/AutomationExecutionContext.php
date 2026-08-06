<?php

namespace App\DTOs\Automation;

readonly class AutomationExecutionContext
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $automationUuid,
        public string $driver,
        public string $driverClass,
        public string $handler,
        public ?string $entityType,
        public int|string|null $entityId,
        public ?string $channel,
        public array $payload,
        public int $attempt = 1,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'automation_uuid' => $this->automationUuid,
            'driver' => $this->driver,
            'driver_class' => $this->driverClass,
            'handler' => $this->handler,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'channel' => $this->channel,
            'payload' => $this->payload,
            'attempt' => $this->attempt,
        ];
    }
}
