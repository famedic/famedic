<?php

namespace App\Services\Otp;

/**
 * Summary of one akubica:prune-otp run (dry-run or force).
 * Counts never include PII; only aggregate integers.
 */
final class AkubicaOtpPruningResult
{
    /**
     * @param  array{challenges_registration_intent: int, challenges_remaining_grants: int, grants_active_secure_links: int}  $skipped
     * @param  list<array{entity: string, exception_class: string, message: string}>  $errors
     */
    public function __construct(
        public readonly bool $dryRun,
        public readonly int $batch,
        public readonly string $type,
        public readonly int $challenges = 0,
        public readonly int $deliveryOperations = 0,
        public readonly int $rateLimits = 0,
        public readonly int $stepUpGrants = 0,
        public readonly int $secureDownloadLinks = 0,
        public readonly array $skipped = [
            'challenges_registration_intent' => 0,
            'challenges_remaining_grants' => 0,
            'grants_active_secure_links' => 0,
        ],
        public readonly int $durationMs = 0,
        public readonly array $errors = [],
    ) {}

    public function failed(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return array{
     *     challenges: int,
     *     delivery_operations: int,
     *     rate_limits: int,
     *     step_up_grants: int,
     *     secure_download_links: int
     * }
     */
    public function counts(): array
    {
        return [
            'challenges' => $this->challenges,
            'delivery_operations' => $this->deliveryOperations,
            'rate_limits' => $this->rateLimits,
            'step_up_grants' => $this->stepUpGrants,
            'secure_download_links' => $this->secureDownloadLinks,
        ];
    }

    /**
     * @return array<string, scalar|array<string, int>>
     */
    public function toLogContext(): array
    {
        return [
            'dry_run' => $this->dryRun,
            'batch' => $this->batch,
            'type' => $this->type,
            'duration_ms' => $this->durationMs,
            'duration_bucket' => $this->durationBucket(),
            'challenges_deleted' => $this->challenges,
            'deliveries_deleted' => $this->deliveryOperations,
            'rate_limits_deleted' => $this->rateLimits,
            'grants_deleted' => $this->stepUpGrants,
            'links_deleted' => $this->secureDownloadLinks,
            'skipped_challenges_registration_intent' => $this->skipped['challenges_registration_intent'] ?? 0,
            'skipped_challenges_remaining_grants' => $this->skipped['challenges_remaining_grants'] ?? 0,
            'skipped_grants_active_secure_links' => $this->skipped['grants_active_secure_links'] ?? 0,
            'error_count' => count($this->errors),
            'environment' => app()->environment(),
        ];
    }

    public function durationBucket(): string
    {
        return match (true) {
            $this->durationMs < 100 => '0-100',
            $this->durationMs < 250 => '100-250',
            $this->durationMs < 500 => '250-500',
            $this->durationMs < 1000 => '500-1000',
            $this->durationMs < 5000 => '1000-5000',
            default => '5000+',
        };
    }
}
