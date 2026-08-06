<?php

namespace App\DTOs\Orders;

class OrderAutomationResult
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array{
     *     executed?: bool,
     *     success?: bool|null,
     *     operation?: string|null,
     *     duration_ms?: int|null,
     *     error?: string|null,
     *     retryable?: bool|null,
     *     contact_id?: int|null,
     *     operations?: list<array<string, mixed>>,
     *     action?: string|null,
     *     tag?: string|null,
     *     message?: string|null,
     *     reason?: string|null
     * }  $activecampaign
     */
    public function __construct(
        public readonly string $handler,
        public readonly string $status,
        public readonly bool $handled,
        public readonly string $message,
        public readonly array $context,
        public readonly bool $automationsExecuted = false,
        public readonly array $activecampaign = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'handler' => $this->handler,
            'status' => $this->status,
            'handled' => $this->handled,
            'message' => $this->message,
            'automations_executed' => $this->automationsExecuted,
            'context' => $this->context,
            'activecampaign' => $this->activecampaign,
        ];
    }

    /**
     * @return array{
     *     executed: bool,
     *     success: bool|null,
     *     operation: string|null,
     *     duration_ms: int|null,
     *     error: string|null,
     *     retryable: bool|null,
     *     contact_id: int|null,
     *     operations: list<array<string, mixed>>,
     *     reason: string|null
     * }
     */
    public static function emptyActiveCampaignPayload(?string $reason = null): array
    {
        return [
            'executed' => false,
            'success' => null,
            'operation' => null,
            'duration_ms' => null,
            'error' => null,
            'retryable' => null,
            'contact_id' => null,
            'operations' => [],
            'reason' => $reason,
        ];
    }
}
