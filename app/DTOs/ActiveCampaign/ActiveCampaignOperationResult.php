<?php

namespace App\DTOs\ActiveCampaign;

use Carbon\CarbonInterface;

class ActiveCampaignOperationResult
{
    /**
     * @param  mixed  $response  Decoded body, scalar payload, or null
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $operation,
        public readonly ?string $resource,
        public readonly ?int $contactId,
        public readonly ?int $tagId,
        public readonly ?int $httpStatus,
        public readonly mixed $response,
        public readonly ?string $error,
        public readonly int $durationMs,
        public readonly bool $retryable,
        public readonly CarbonInterface $timestamp,
    ) {
    }

    /**
     * @param  array{
     *     operation: string,
     *     resource?: string|null,
     *     contact_id?: int|null,
     *     tag_id?: int|null,
     *     http_status?: int|null,
     *     response?: mixed,
     *     error?: string|null,
     *     duration_ms?: int,
     *     retryable?: bool
     * }  $data
     */
    public static function success(array $data): self
    {
        return new self(
            success: true,
            operation: $data['operation'],
            resource: $data['resource'] ?? null,
            contactId: $data['contact_id'] ?? null,
            tagId: $data['tag_id'] ?? null,
            httpStatus: $data['http_status'] ?? null,
            response: $data['response'] ?? null,
            error: null,
            durationMs: (int) ($data['duration_ms'] ?? 0),
            retryable: false,
            timestamp: now(),
        );
    }

    /**
     * @param  array{
     *     operation: string,
     *     resource?: string|null,
     *     contact_id?: int|null,
     *     tag_id?: int|null,
     *     http_status?: int|null,
     *     response?: mixed,
     *     error?: string|null,
     *     duration_ms?: int,
     *     retryable?: bool
     * }  $data
     */
    public static function failure(array $data): self
    {
        return new self(
            success: false,
            operation: $data['operation'],
            resource: $data['resource'] ?? null,
            contactId: $data['contact_id'] ?? null,
            tagId: $data['tag_id'] ?? null,
            httpStatus: $data['http_status'] ?? null,
            response: $data['response'] ?? null,
            error: $data['error'] ?? 'unknown_error',
            durationMs: (int) ($data['duration_ms'] ?? 0),
            retryable: (bool) ($data['retryable'] ?? false),
            timestamp: now(),
        );
    }

    public static function isRetryableHttpStatus(?int $status): bool
    {
        if ($status === null) {
            return true;
        }

        return $status === 429 || $status >= 500;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'operation' => $this->operation,
            'resource' => $this->resource,
            'contact_id' => $this->contactId,
            'tag_id' => $this->tagId,
            'http_status' => $this->httpStatus,
            'response' => $this->response,
            'error' => $this->error,
            'duration_ms' => $this->durationMs,
            'retryable' => $this->retryable,
            'timestamp' => $this->timestamp->toIso8601String(),
        ];
    }
}
