<?php

namespace App\Jobs\Automation;

use App\DTOs\Automation\AutomationExecutionResult;
use App\DTOs\Orders\OrderAutomationContext;
use App\DTOs\Orders\OrderAutomationResult;
use App\Models\AutomationRun;
use App\Services\Automation\AutomationContextRehydrator;
use App\Services\Automation\AutomationRetryService;
use App\Services\AutomationOperations\AutomationOperationsRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Async driver execution. Idempotent by automation_uuid.
 * Does not contain business logic — only invokes the registered driver handler.
 */
class AutomationExecutionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public string $automationUuid,
    ) {
        $this->timeout = (int) config('automation_queue.job_timeout', 120);

        $connection = config('automation_queue.connection');
        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }

        $queue = config('automation_queue.queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public static function dispatchForUuid(string $automationUuid): void
    {
        $job = new self($automationUuid);
        dispatch($job);
    }

    public function handle(
        AutomationContextRehydrator $rehydrator,
        AutomationRetryService $retries,
        AutomationOperationsRecorder $recorder,
    ): void {
        $run = AutomationRun::query()
            ->where('automation_uuid', $this->automationUuid)
            ->first();

        if (! $run) {
            Log::warning('[Automation Job] run not found', [
                'automation_uuid' => $this->automationUuid,
            ]);

            return;
        }

        // Idempotency: never re-execute a completed automation_uuid
        if ($run->isTerminalSuccess()) {
            Log::info('[Automation Job] skipped — already completed', [
                'automation_uuid' => $run->automation_uuid,
                'driver' => $run->driver,
            ]);

            return;
        }

        // Terminal states that must not auto-run (manual retry / requeue reset to pending)
        if ($run->status === AutomationRun::STATUS_DEAD_LETTER) {
            Log::info('[Automation Job] skipped — dead letter (use Requeue)', [
                'automation_uuid' => $run->automation_uuid,
            ]);

            return;
        }

        if ($run->status === AutomationRun::STATUS_FAILED && $run->retryable === false) {
            Log::info('[Automation Job] skipped — terminal non-retryable failure', [
                'automation_uuid' => $run->automation_uuid,
            ]);

            return;
        }

        $run->update([
            'status' => AutomationRun::STATUS_RUNNING,
            'started_at' => now(),
            'next_retry_at' => null,
        ]);

        $started = hrtime(true);

        try {
            if (! $run->driver_class || ! class_exists($run->driver_class)) {
                throw new \RuntimeException("Driver class not found: {$run->driver_class}");
            }

            $driver = app($run->driver_class);
            $context = $rehydrator->rehydrate($run->payload ?? []);
            $driverResult = $this->invokeDriver($driver, $run->handler, $context);
            $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);
            $success = $this->isDriverSuccess($driverResult);

            if ($success) {
                $execution = new AutomationExecutionResult(
                    status: AutomationRun::STATUS_COMPLETED,
                    success: true,
                    retryable: false,
                    error: null,
                    durationMs: $durationMs,
                    response: $driverResult->toArray(),
                );

                $run->update([
                    'status' => AutomationRun::STATUS_COMPLETED,
                    'retryable' => false,
                    'error' => null,
                    'response' => $execution->response,
                    'finished_at' => now(),
                    'duration_ms' => $durationMs,
                    'next_retry_at' => null,
                ]);

                $recorder->record([
                    'automation' => 'OrderAutomation',
                    'driver' => $run->driver,
                    'channel' => $run->channel,
                    'operation' => $run->handler,
                    'result' => 'success',
                    'duration_ms' => $durationMs,
                    'retryable' => false,
                    'subject_type' => $run->entity_type,
                    'subject_id' => $run->entity_id,
                    'reference' => $run->automation_uuid,
                    'meta' => [
                        'automation_uuid' => $run->automation_uuid,
                        'attempt' => $run->attempt,
                    ],
                ]);

                Log::info('[Automation Job] completed', [
                    'automation_uuid' => $run->automation_uuid,
                    'driver' => $run->driver,
                    'duration_ms' => $durationMs,
                    'attempt' => $run->attempt,
                ]);

                return;
            }

            $retryable = $retries->isRetryableFromDriverResult($driverResult);
            $error = (string) ($driverResult->activecampaign['error'] ?? $driverResult->message ?? 'Driver reported failure');
            $execution = new AutomationExecutionResult(
                status: 'failed',
                success: false,
                retryable: $retryable,
                error: $error,
                durationMs: $durationMs,
                httpStatus: $retries->extractHttpStatus($error),
                response: $driverResult->toArray(),
            );

            $run->update([
                'duration_ms' => $durationMs,
                'response' => $execution->response,
                'error' => $error,
                'retryable' => $retryable,
            ]);

            $recorder->record([
                'automation' => 'OrderAutomation',
                'driver' => $run->driver,
                'channel' => $run->channel,
                'operation' => $run->handler,
                'result' => 'failed',
                'duration_ms' => $durationMs,
                'retryable' => $retryable,
                'subject_type' => $run->entity_type,
                'subject_id' => $run->entity_id,
                'reference' => $run->automation_uuid,
                'meta' => [
                    'automation_uuid' => $run->automation_uuid,
                    'attempt' => $run->attempt,
                    'error' => $error,
                ],
            ]);

            $retries->handleFailure($run->fresh(), $execution);
        } catch (Throwable $e) {
            $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);
            $execution = new AutomationExecutionResult(
                status: 'failed',
                success: false,
                retryable: true,
                error: $e->getMessage(),
                durationMs: $durationMs,
                httpStatus: $retries->extractHttpStatus($e->getMessage()),
                response: [
                    'exception' => class_basename($e),
                    'message' => $e->getMessage(),
                ],
            );

            $run->update([
                'duration_ms' => $durationMs,
                'error' => $e->getMessage(),
                'retryable' => true,
                'response' => $execution->response,
            ]);

            $recorder->record([
                'automation' => 'OrderAutomation',
                'driver' => $run->driver,
                'channel' => $run->channel,
                'operation' => $run->handler,
                'result' => 'failed',
                'duration_ms' => $durationMs,
                'retryable' => true,
                'subject_type' => $run->entity_type,
                'subject_id' => $run->entity_id,
                'reference' => $run->automation_uuid,
                'meta' => [
                    'automation_uuid' => $run->automation_uuid,
                    'attempt' => $run->attempt,
                    'exception' => class_basename($e),
                ],
            ]);

            Log::error('[Automation Job] exception', [
                'automation_uuid' => $run->automation_uuid,
                'driver' => $run->driver,
                'error' => $e->getMessage(),
            ]);

            $retries->handleFailure($run->fresh(), $execution, $e);
        }
    }

    private function invokeDriver(object $driver, string $handler, OrderAutomationContext $context): OrderAutomationResult
    {
        if (! method_exists($driver, $handler)) {
            throw new \RuntimeException("Driver handler missing: {$handler}");
        }

        /** @var OrderAutomationResult $result */
        $result = $driver->{$handler}($context);

        return $result;
    }

    private function isDriverSuccess(OrderAutomationResult $result): bool
    {
        if (array_key_exists('success', $result->activecampaign) && $result->activecampaign['success'] !== null) {
            return (bool) $result->activecampaign['success'];
        }

        return in_array($result->status, ['synced', 'prepared', 'completed'], true);
    }
}
