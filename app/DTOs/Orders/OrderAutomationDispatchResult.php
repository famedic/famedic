<?php

namespace App\DTOs\Orders;

class OrderAutomationDispatchResult
{
    /**
     * @param  list<array<string, mixed>>  $drivers
     * @param  list<array<string, mixed>>  $operations
     * @param  list<array<string, mixed>>  $errors
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly array $drivers,
        public readonly int $successful,
        public readonly int $failed,
        public readonly int $durationMs,
        public readonly array $operations,
        public readonly array $errors,
        public readonly string $channel,
        public readonly array $context = [],
        public readonly bool $handled = true,
        public readonly string $status = 'completed',
        public readonly string $message = '',
    ) {
    }

    public function ok(): bool
    {
        return $this->failed === 0 && $this->successful > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'handled' => $this->handled,
            'message' => $this->message,
            'channel' => $this->channel,
            'successful' => $this->successful,
            'failed' => $this->failed,
            'duration_ms' => $this->durationMs,
            'drivers' => $this->drivers,
            'operations' => $this->operations,
            'errors' => $this->errors,
            'context' => $this->context,
        ];
    }
}
