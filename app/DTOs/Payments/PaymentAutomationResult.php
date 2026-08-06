<?php

namespace App\DTOs\Payments;

class PaymentAutomationResult
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $handler,
        public readonly string $status,
        public readonly bool $handled,
        public readonly string $message,
        public readonly array $context,
        public readonly bool $automationsExecuted = false,
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
        ];
    }
}
