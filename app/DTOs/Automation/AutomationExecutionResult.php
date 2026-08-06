<?php

namespace App\DTOs\Automation;

readonly class AutomationExecutionResult
{
    /**
     * @param  array<string, mixed>|null  $response
     */
    public function __construct(
        public string $status,
        public bool $success,
        public ?bool $retryable,
        public ?string $error = null,
        public ?int $durationMs = null,
        public ?int $httpStatus = null,
        public ?array $response = null,
        public bool $skippedIdempotent = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'success' => $this->success,
            'retryable' => $this->retryable,
            'error' => $this->error,
            'duration_ms' => $this->durationMs,
            'http_status' => $this->httpStatus,
            'response' => $this->response,
            'skipped_idempotent' => $this->skippedIdempotent,
        ];
    }
}
