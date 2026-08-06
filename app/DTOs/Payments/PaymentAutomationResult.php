<?php

namespace App\DTOs\Payments;

class PaymentAutomationResult
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array{
     *     executed?: bool,
     *     success?: bool|null,
     *     action?: string|null,
     *     tag?: string|null,
     *     contact_id?: int|null,
     *     message?: string|null,
     *     error?: string|null,
     *     duration_ms?: int|null,
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
     * Default ActiveCampaign audit payload.
     *
     * @return array{
     *     executed: bool,
     *     success: bool|null,
     *     action: string|null,
     *     tag: string|null,
     *     contact_id: int|null,
     *     message: string|null,
     *     error: string|null,
     *     duration_ms: int|null,
     *     reason: string|null
     * }
     */
    public static function emptyActiveCampaignPayload(?string $reason = null): array
    {
        return [
            'executed' => false,
            'success' => null,
            'action' => null,
            'tag' => null,
            'contact_id' => null,
            'message' => null,
            'error' => null,
            'duration_ms' => null,
            'reason' => $reason,
        ];
    }
}
