<?php

namespace App\Services\AutomationOperations;

use App\Models\AutomationOperationEvent;
use App\Models\PaymentAttempt;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only data access for Automation Operations Center.
 * Does not invoke PaymentAutomation / OrderAutomation / Drivers.
 */
class AutomationOperationsRepository
{
    public function eventsTableReady(): bool
    {
        return Schema::hasTable('automation_operation_events');
    }

    /**
     * @return array{success: int, failed: int, skipped: int, partial: int, total: int, avg_ms: float|null, retryables: int}
     */
    public function eventStatsToday(): array
    {
        if (! $this->eventsTableReady()) {
            return $this->emptyEventStats();
        }

        $start = now()->startOfDay();

        $rows = AutomationOperationEvent::query()
            ->where('occurred_at', '>=', $start)
            ->selectRaw('result, COUNT(*) as c, AVG(duration_ms) as avg_ms, SUM(CASE WHEN retryable = 1 THEN 1 ELSE 0 END) as retryables')
            ->groupBy('result')
            ->get();

        $stats = $this->emptyEventStats();
        foreach ($rows as $row) {
            $key = (string) $row->result;
            if (isset($stats[$key])) {
                $stats[$key] = (int) $row->c;
            }
            $stats['total'] += (int) $row->c;
            $stats['retryables'] += (int) $row->retryables;
            if ($row->avg_ms !== null) {
                $stats['avg_ms'] = $stats['avg_ms'] === null
                    ? (float) $row->avg_ms
                    : (($stats['avg_ms'] + (float) $row->avg_ms) / 2);
            }
        }

        return $stats;
    }

    /**
     * @return array{approved: int, declined: int, error: int, pending: int, processing: int, total: int}
     */
    public function paymentAttemptStatsToday(): array
    {
        $start = now()->startOfDay();

        $rows = PaymentAttempt::query()
            ->where('created_at', '>=', $start)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'approved' => (int) ($rows[PaymentAttempt::STATUS_APPROVED] ?? 0),
            'declined' => (int) ($rows[PaymentAttempt::STATUS_DECLINED] ?? 0),
            'error' => (int) ($rows[PaymentAttempt::STATUS_ERROR] ?? 0),
            'pending' => (int) ($rows[PaymentAttempt::STATUS_PENDING] ?? 0),
            'processing' => (int) ($rows[PaymentAttempt::STATUS_PROCESSING] ?? 0),
            'total' => (int) $rows->sum(),
        ];
    }

    /**
     * Pending / in-flight AC dispatches (retryables / pendientes proxy).
     *
     * @return array{pending: int, processing: int, failed_today: int, synced_today: int}
     */
    public function activeCampaignDispatchStatsToday(): array
    {
        if (! Schema::hasTable('activecampaign_dispatches')) {
            return ['pending' => 0, 'processing' => 0, 'failed_today' => 0, 'synced_today' => 0];
        }

        $start = now()->startOfDay();

        return [
            'pending' => (int) DB::table('activecampaign_dispatches')->where('status', 'pending')->count(),
            'processing' => (int) DB::table('activecampaign_dispatches')->where('status', 'processing')->count(),
            'failed_today' => (int) DB::table('activecampaign_dispatches')
                ->where('status', 'failed')
                ->where('updated_at', '>=', $start)
                ->count(),
            'synced_today' => (int) DB::table('activecampaign_dispatches')
                ->where('status', 'synced')
                ->where('updated_at', '>=', $start)
                ->count(),
        ];
    }

    /**
     * @return Collection<int, AutomationOperationEvent>
     */
    public function recentEvents(int $limit = 100): Collection
    {
        if (! $this->eventsTableReady()) {
            return collect();
        }

        return AutomationOperationEvent::query()
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, PaymentAttempt>
     */
    public function recentPaymentAttempts(int $limit = 50): Collection
    {
        return PaymentAttempt::query()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Per-driver aggregates from events table.
     *
     * @return array<string, array{executions: int, errors: int, retryables: int, avg_ms: float|null, last_at: string|null}>
     */
    public function driverAggregates(): array
    {
        if (! $this->eventsTableReady()) {
            return [];
        }

        $from = now()->subDays(7);

        $rows = AutomationOperationEvent::query()
            ->where('occurred_at', '>=', $from)
            ->whereNotNull('driver')
            ->selectRaw('driver, COUNT(*) as executions, SUM(CASE WHEN result = ? THEN 1 ELSE 0 END) as errors, SUM(CASE WHEN retryable = 1 THEN 1 ELSE 0 END) as retryables, AVG(duration_ms) as avg_ms, MAX(occurred_at) as last_at', [
                AutomationOperationEvent::RESULT_FAILED,
            ])
            ->groupBy('driver')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->driver] = [
                'executions' => (int) $row->executions,
                'errors' => (int) $row->errors,
                'retryables' => (int) $row->retryables,
                'avg_ms' => $row->avg_ms !== null ? round((float) $row->avg_ms, 1) : null,
                'last_at' => $row->last_at ? Carbon::parse($row->last_at)->toIso8601String() : null,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{driver: string, avg_ms: float, executions: int}>
     */
    public function slowestDrivers(int $limit = 5): array
    {
        $agg = $this->driverAggregates();
        $list = [];
        foreach ($agg as $driver => $stats) {
            if ($stats['avg_ms'] === null) {
                continue;
            }
            $list[] = [
                'driver' => $driver,
                'avg_ms' => $stats['avg_ms'],
                'executions' => $stats['executions'],
            ];
        }

        usort($list, fn ($a, $b) => $b['avg_ms'] <=> $a['avg_ms']);

        return array_slice($list, 0, $limit);
    }

    /**
     * @return array{success: int, failed: int, skipped: int, partial: int, total: int, avg_ms: float|null, retryables: int}
     */
    private function emptyEventStats(): array
    {
        return [
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'partial' => 0,
            'total' => 0,
            'avg_ms' => null,
            'retryables' => 0,
        ];
    }
}
