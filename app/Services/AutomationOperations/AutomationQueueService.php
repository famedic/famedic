<?php

namespace App\Services\AutomationOperations;

use App\Models\AutomationDeadLetter;
use App\Models\AutomationRetryHistory;
use App\Models\AutomationRun;
use App\Services\Automation\AutomationDeadLetterService;
use App\Services\Automation\AutomationRetryService;
use Illuminate\Support\Facades\Schema;

/**
 * Queue visibility + manual actions for Automation Operations Center.
 */
class AutomationQueueService
{
    public function __construct(
        private AutomationRetryService $retries,
        private AutomationDeadLetterService $deadLetters,
    ) {
    }

    public function tablesReady(): bool
    {
        return Schema::hasTable('automation_runs')
            && Schema::hasTable('automation_dead_letters');
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $limit = 50): array
    {
        if (! $this->tablesReady()) {
            return [
                'ready' => false,
                'counts' => $this->emptyCounts(),
                'runs' => [],
                'dead_letters' => [],
                'meta' => ['message' => 'Ejecuta migraciones de automation_runs / dead_letters.'],
            ];
        }

        $counts = [
            'pending' => AutomationRun::query()->where('status', AutomationRun::STATUS_PENDING)->count(),
            'running' => AutomationRun::query()->where('status', AutomationRun::STATUS_RUNNING)->count(),
            'retrying' => AutomationRun::query()->where('status', AutomationRun::STATUS_RETRYING)->count(),
            'completed' => AutomationRun::query()->where('status', AutomationRun::STATUS_COMPLETED)->count(),
            'failed' => AutomationRun::query()->where('status', AutomationRun::STATUS_FAILED)->count(),
            'dead_letter' => AutomationRun::query()->where('status', AutomationRun::STATUS_DEAD_LETTER)->count(),
            'dead_letter_open' => AutomationDeadLetter::query()->where('status', AutomationDeadLetter::STATUS_OPEN)->count(),
        ];

        $runs = AutomationRun::query()
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (AutomationRun $run) => $this->serializeRun($run))
            ->all();

        $deadLetters = AutomationDeadLetter::query()
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (AutomationDeadLetter $dl) => [
                'id' => $dl->id,
                'automation_uuid' => $dl->automation_uuid,
                'automation_run_id' => $dl->automation_run_id,
                'driver' => $dl->driver,
                'handler' => $dl->handler,
                'entity_type' => $dl->entity_type,
                'entity_id' => $dl->entity_id,
                'error' => $dl->error,
                'attempts' => $dl->attempts,
                'status' => $dl->status,
                'last_execution_at' => $dl->last_execution_at?->toIso8601String(),
                'created_at' => $dl->created_at?->toIso8601String(),
            ])
            ->all();

        return [
            'ready' => true,
            'counts' => $counts,
            'runs' => $runs,
            'dead_letters' => $deadLetters,
            'meta' => [
                'max_attempts' => $this->retries->maxAttempts(),
                'backoff_seconds' => $this->retries->backoffSeconds(),
                'queue' => config('automation_queue.queue'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function performanceExtras(): array
    {
        if (! $this->tablesReady()) {
            return [
                'retries_total' => 0,
                'dead_letters_total' => 0,
                'success_rate' => null,
                'p95_ms' => null,
                'p99_ms' => null,
                'avg_ms_by_driver' => [],
            ];
        }

        $from = now()->subDays(7);
        $completed = AutomationRun::query()
            ->where('status', AutomationRun::STATUS_COMPLETED)
            ->where('created_at', '>=', $from)
            ->count();
        $failedish = AutomationRun::query()
            ->whereIn('status', [
                AutomationRun::STATUS_FAILED,
                AutomationRun::STATUS_DEAD_LETTER,
            ])
            ->where('created_at', '>=', $from)
            ->count();
        $total = $completed + $failedish;

        $durations = AutomationRun::query()
            ->where('created_at', '>=', $from)
            ->whereNotNull('duration_ms')
            ->orderBy('duration_ms')
            ->pluck('duration_ms')
            ->map(fn ($v) => (int) $v)
            ->values()
            ->all();

        $retriesTotal = Schema::hasTable('automation_retry_history')
            ? AutomationRetryHistory::query()->where('created_at', '>=', $from)->count()
            : 0;

        $avgByDriver = AutomationRun::query()
            ->where('created_at', '>=', $from)
            ->whereNotNull('duration_ms')
            ->selectRaw('driver, AVG(duration_ms) as avg_ms, COUNT(*) as executions')
            ->groupBy('driver')
            ->orderByDesc('avg_ms')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'driver' => $row->driver,
                'avg_ms' => round((float) $row->avg_ms, 1),
                'executions' => (int) $row->executions,
            ])
            ->all();

        return [
            'retries_total' => $retriesTotal,
            'dead_letters_total' => AutomationDeadLetter::query()->where('created_at', '>=', $from)->count(),
            'success_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : null,
            'p95_ms' => $this->percentile($durations, 95),
            'p99_ms' => $this->percentile($durations, 99),
            'avg_ms_by_driver' => $avgByDriver,
        ];
    }

    public function retryManual(int $runId): AutomationRun
    {
        $run = AutomationRun::query()->findOrFail($runId);

        return $this->retries->retryManual($run);
    }

    public function requeueDeadLetter(int $deadLetterId): AutomationRun
    {
        $dl = AutomationDeadLetter::query()->findOrFail($deadLetterId);

        return $this->deadLetters->requeue($dl);
    }

    public function discardDeadLetter(int $deadLetterId, ?int $adminUserId = null): AutomationDeadLetter
    {
        $dl = AutomationDeadLetter::query()->findOrFail($deadLetterId);

        return $this->deadLetters->discard($dl, $adminUserId);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRun(AutomationRun $run): array
    {
        return [
            'id' => $run->id,
            'automation_uuid' => $run->automation_uuid,
            'driver' => $run->driver,
            'handler' => $run->handler,
            'channel' => $run->channel,
            'entity_type' => $run->entity_type,
            'entity_id' => $run->entity_id,
            'attempt' => $run->attempt,
            'status' => $run->status,
            'retryable' => $run->retryable,
            'error' => $run->error,
            'duration_ms' => $run->duration_ms,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'next_retry_at' => $run->next_retry_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function emptyCounts(): array
    {
        return [
            'pending' => 0,
            'running' => 0,
            'retrying' => 0,
            'completed' => 0,
            'failed' => 0,
            'dead_letter' => 0,
            'dead_letter_open' => 0,
        ];
    }

    /**
     * @param  list<int>  $sorted
     */
    private function percentile(array $sorted, float $p): ?int
    {
        $n = count($sorted);
        if ($n === 0) {
            return null;
        }

        $rank = (int) ceil(($p / 100) * $n) - 1;
        $rank = max(0, min($n - 1, $rank));

        return $sorted[$rank];
    }
}
