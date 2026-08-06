<?php

namespace App\Services\Automation;

use App\DTOs\Automation\AutomationExecutionResult;
use App\DTOs\Orders\OrderAutomationResult;
use App\Jobs\Automation\AutomationExecutionJob;
use App\Models\AutomationRetryHistory;
use App\Models\AutomationRun;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides retry vs dead-letter and schedules delayed re-execution.
 */
class AutomationRetryService
{
    public function __construct(
        private AutomationDeadLetterService $deadLetters,
    ) {
    }

    public function maxAttempts(): int
    {
        return (int) config('automation_queue.max_attempts', 5);
    }

    /**
     * @return list<int>
     */
    public function backoffSeconds(): array
    {
        return array_values(array_map(
            'intval',
            config('automation_queue.backoff_seconds', [60, 300, 900, 3600])
        ));
    }

    public function isRetryable(AutomationExecutionResult $result, ?Throwable $exception = null): bool
    {
        if ($result->retryable === false) {
            return false;
        }

        if ($result->httpStatus !== null) {
            $codes = config('automation_queue.retryable_http_statuses', [429, 500, 502, 503, 504]);
            if (in_array($result->httpStatus, $codes, true)) {
                return true;
            }
        }

        $message = strtolower(($result->error ?? '').' '.($exception?->getMessage() ?? ''));
        foreach (config('automation_queue.retryable_error_patterns', []) as $pattern) {
            if ($pattern !== '' && str_contains($message, strtolower((string) $pattern))) {
                return true;
            }
        }

        // Explicit retryable=true from driver / exception path
        return $result->retryable === true;
    }

    public function isRetryableFromDriverResult(OrderAutomationResult $driverResult): bool
    {
        $retryable = $driverResult->activecampaign['retryable'] ?? null;
        if ($retryable === false) {
            return false;
        }
        if ($retryable === true) {
            return true;
        }

        $error = (string) ($driverResult->activecampaign['error'] ?? $driverResult->message ?? '');
        $probe = new AutomationExecutionResult(
            status: 'failed',
            success: false,
            retryable: null,
            error: $error,
            httpStatus: $this->extractHttpStatus($error),
        );

        return $this->isRetryable($probe);
    }

    public function extractHttpStatus(?string $message): ?int
    {
        if (! $message) {
            return null;
        }

        if (preg_match('/\b(429|500|502|503|504)\b/', $message, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Schedule next attempt or promote to dead letter.
     */
    public function handleFailure(AutomationRun $run, AutomationExecutionResult $result, ?Throwable $exception = null): void
    {
        $retryable = $this->isRetryable($result, $exception);

        if (! $retryable) {
            $run->update([
                'status' => AutomationRun::STATUS_FAILED,
                'retryable' => false,
                'finished_at' => now(),
                'error' => $result->error ?? $exception?->getMessage(),
                'response' => $result->response,
                'duration_ms' => $result->durationMs ?? $run->duration_ms,
                'next_retry_at' => null,
            ]);

            Log::warning('[Automation Retry] non-retryable failure', [
                'automation_uuid' => $run->automation_uuid,
                'driver' => $run->driver,
                'error' => $run->error,
            ]);

            return;
        }

        if ($run->attempt >= $this->maxAttempts()) {
            $this->deadLetters->promote($run, $result, $exception);

            return;
        }

        $delayIndex = max(0, $run->attempt - 1);
        $backoff = $this->backoffSeconds();
        $delay = $backoff[$delayIndex] ?? end($backoff) ?: 3600;
        $nextAttempt = $run->attempt + 1;
        $scheduledAt = now()->addSeconds($delay);

        AutomationRetryHistory::query()->create([
            'automation_uuid' => $run->automation_uuid,
            'automation_run_id' => $run->id,
            'attempt' => $run->attempt,
            'delay_seconds' => $delay,
            'reason' => 'retryable_failure',
            'http_status' => $result->httpStatus,
            'error' => $result->error ?? $exception?->getMessage(),
            'scheduled_at' => $scheduledAt,
        ]);

        $run->update([
            'status' => AutomationRun::STATUS_RETRYING,
            'retryable' => true,
            'error' => $result->error ?? $exception?->getMessage(),
            'response' => $result->response,
            'finished_at' => now(),
            'duration_ms' => $result->durationMs ?? $run->duration_ms,
            'next_retry_at' => $scheduledAt,
            'attempt' => $nextAttempt,
        ]);

        $job = new AutomationExecutionJob($run->automation_uuid);
        $pending = dispatch($job)->delay($scheduledAt);

        $connection = config('automation_queue.connection');
        if (is_string($connection) && $connection !== '') {
            $pending->onConnection($connection);
        }

        $queue = config('automation_queue.queue');
        if (is_string($queue) && $queue !== '') {
            $pending->onQueue($queue);
        }

        Log::info('[Automation Retry] scheduled', [
            'automation_uuid' => $run->automation_uuid,
            'next_attempt' => $nextAttempt,
            'delay_seconds' => $delay,
            'next_retry_at' => $scheduledAt->toIso8601String(),
        ]);
    }

    /**
     * Manual retry from Ops Center (open dead letter or failed run).
     */
    public function retryManual(AutomationRun $run): AutomationRun
    {
        $run->refresh();

        if ($run->status === AutomationRun::STATUS_COMPLETED) {
            return $run;
        }

        $run->update([
            'status' => AutomationRun::STATUS_PENDING,
            'retryable' => null,
            'error' => null,
            'next_retry_at' => null,
            'started_at' => null,
            'finished_at' => null,
            'duration_ms' => null,
            'attempt' => max(1, (int) $run->attempt),
        ]);

        AutomationExecutionJob::dispatchForUuid($run->automation_uuid);

        Log::info('[Automation Retry] manual retry queued', [
            'automation_uuid' => $run->automation_uuid,
            'attempt' => $run->attempt,
        ]);

        return $run->fresh();
    }
}
