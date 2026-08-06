<?php

namespace App\Services\Automation;

use App\DTOs\Automation\AutomationExecutionResult;
use App\Jobs\Automation\AutomationExecutionJob;
use App\Models\AutomationDeadLetter;
use App\Models\AutomationRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AutomationDeadLetterService
{
    public function promote(
        AutomationRun $run,
        AutomationExecutionResult $result,
        ?Throwable $exception = null,
    ): AutomationDeadLetter {
        return DB::transaction(function () use ($run, $result, $exception) {
            $run->update([
                'status' => AutomationRun::STATUS_DEAD_LETTER,
                'retryable' => true,
                'error' => $result->error ?? $exception?->getMessage(),
                'response' => $result->response,
                'finished_at' => now(),
                'duration_ms' => $result->durationMs ?? $run->duration_ms,
                'next_retry_at' => null,
            ]);

            $deadLetter = AutomationDeadLetter::query()->updateOrCreate(
                ['automation_uuid' => $run->automation_uuid],
                [
                    'automation_run_id' => $run->id,
                    'driver' => $run->driver,
                    'handler' => $run->handler,
                    'entity_type' => $run->entity_type,
                    'entity_id' => $run->entity_id,
                    'payload' => $run->payload,
                    'error' => $result->error ?? $exception?->getMessage(),
                    'stack' => $exception?->getTraceAsString(),
                    'attempts' => $run->attempt,
                    'last_execution_at' => $run->finished_at ?? now(),
                    'status' => AutomationDeadLetter::STATUS_OPEN,
                    'requeued_at' => null,
                    'discarded_at' => null,
                    'discarded_by' => null,
                ]
            );

            Log::error('[Automation Dead Letter] promoted', [
                'automation_uuid' => $run->automation_uuid,
                'driver' => $run->driver,
                'attempts' => $run->attempt,
                'error' => $deadLetter->error,
            ]);

            return $deadLetter;
        });
    }

    /**
     * Requeue an open dead letter for another execution cycle.
     */
    public function requeue(AutomationDeadLetter $deadLetter): AutomationRun
    {
        if ($deadLetter->status === AutomationDeadLetter::STATUS_DISCARDED) {
            throw new \RuntimeException('Cannot requeue a discarded dead letter.');
        }

        return DB::transaction(function () use ($deadLetter) {
            $run = $deadLetter->run;
            if (! $run) {
                $run = AutomationRun::query()
                    ->where('automation_uuid', $deadLetter->automation_uuid)
                    ->firstOrFail();
            }

            $run->update([
                'status' => AutomationRun::STATUS_PENDING,
                'attempt' => 1,
                'error' => null,
                'response' => null,
                'retryable' => null,
                'started_at' => null,
                'finished_at' => null,
                'duration_ms' => null,
                'next_retry_at' => null,
                'payload' => $deadLetter->payload ?? $run->payload,
            ]);

            $deadLetter->update([
                'status' => AutomationDeadLetter::STATUS_REQUEUED,
                'requeued_at' => now(),
            ]);

            AutomationExecutionJob::dispatchForUuid($run->automation_uuid);

            Log::info('[Automation Dead Letter] requeued', [
                'automation_uuid' => $run->automation_uuid,
                'dead_letter_id' => $deadLetter->id,
            ]);

            return $run->fresh();
        });
    }

    public function discard(AutomationDeadLetter $deadLetter, ?int $adminUserId = null): AutomationDeadLetter
    {
        $deadLetter->update([
            'status' => AutomationDeadLetter::STATUS_DISCARDED,
            'discarded_at' => now(),
            'discarded_by' => $adminUserId,
        ]);

        if ($deadLetter->run) {
            $deadLetter->run->update([
                'status' => AutomationRun::STATUS_FAILED,
                'retryable' => false,
                'next_retry_at' => null,
            ]);
        }

        Log::info('[Automation Dead Letter] discarded', [
            'automation_uuid' => $deadLetter->automation_uuid,
            'dead_letter_id' => $deadLetter->id,
        ]);

        return $deadLetter->fresh();
    }
}
