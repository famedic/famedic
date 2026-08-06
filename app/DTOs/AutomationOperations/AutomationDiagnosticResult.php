<?php

namespace App\DTOs\AutomationOperations;

readonly class AutomationDiagnosticResult
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $status,
        public string $message,
        public ?int $durationMs = null,
        public array $details = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'status' => $this->status,
            'message' => $this->message,
            'duration_ms' => $this->durationMs,
            'details' => $this->details,
        ];
    }
}
