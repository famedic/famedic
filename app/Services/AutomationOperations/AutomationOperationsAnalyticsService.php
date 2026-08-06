<?php

namespace App\Services\AutomationOperations;

use App\DTOs\AutomationOperations\AutomationDriverStatus;
use App\DTOs\AutomationOperations\AutomationTimelineItem;
use App\Models\AutomationOperationEvent;
use App\Models\PaymentAttempt;

/**
 * Builds dashboard payloads for Automation Operations Center.
 * Consumes proxies + events table — never runs business automations.
 */
class AutomationOperationsAnalyticsService
{
    public function __construct(
        private AutomationOperationsRepository $repository,
        private AutomationQueueService $queue,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $eventStats = $this->repository->eventStatsToday();
        $paymentStats = $this->repository->paymentAttemptStatsToday();
        $acStats = $this->repository->activeCampaignDispatchStatsToday();
        $drivers = $this->buildDrivers();
        $activeDrivers = collect($drivers)->where('status', 'active')->count();
        $inactiveDrivers = collect($drivers)->whereIn('status', ['planned', 'inactive'])->count();

        $automationsToday = max($eventStats['total'], $paymentStats['total']);
        $correct = $eventStats['total'] > 0
            ? $eventStats['success']
            : $paymentStats['approved'];
        $failed = $eventStats['total'] > 0
            ? $eventStats['failed']
            : ($paymentStats['error'] + $paymentStats['declined']);
        $avgMs = $eventStats['avg_ms'];
        $retryables = $eventStats['retryables'] + $acStats['failed_today'];
        $pending = $acStats['pending'] + $acStats['processing'] + $paymentStats['pending'] + $paymentStats['processing'];

        $successRate = $automationsToday > 0
            ? round(($correct / $automationsToday) * 100, 1)
            : null;

        $healthTone = 'emerald';
        if ($failed > 0 || $acStats['failed_today'] > 0) {
            $healthTone = 'amber';
        }
        if ($failed > 10 || $pending > 50) {
            $healthTone = 'rose';
        }

        return [
            'health' => [
                'status' => $healthTone === 'emerald' ? 'healthy' : ($healthTone === 'amber' ? 'degraded' : 'critical'),
                'tone' => $healthTone,
                'label' => match ($healthTone) {
                    'emerald' => 'Automation Health: OK',
                    'amber' => 'Automation Health: Atención',
                    default => 'Automation Health: Crítico',
                },
                'drivers_active' => $activeDrivers,
                'drivers_inactive' => $inactiveDrivers,
                'registered_order_drivers' => count(config('order_automation.drivers', [])),
            ],
            'kpis' => [
                [
                    'id' => 'active',
                    'label' => 'Drivers activos',
                    'value' => $activeDrivers,
                    'tone' => 'green',
                    'hint' => 'Catalogados como active en la plataforma',
                ],
                [
                    'id' => 'abandoned',
                    'label' => 'Drivers inactivos',
                    'value' => $inactiveDrivers,
                    'tone' => 'slate',
                    'hint' => 'Planned / no cableados (Email, WhatsApp, …)',
                ],
                [
                    'id' => 'created',
                    'label' => 'Automatizaciones hoy',
                    'value' => $automationsToday,
                    'tone' => 'blue',
                    'hint' => $eventStats['total'] > 0
                        ? 'Desde automation_operation_events'
                        : 'Proxy: payment_attempts (eventos aún no telemetrizados)',
                ],
                [
                    'id' => 'sales',
                    'label' => 'Correctas',
                    'value' => $correct,
                    'tone' => 'green',
                ],
                [
                    'id' => 'lost',
                    'label' => 'Fallidas',
                    'value' => $failed,
                    'tone' => $failed > 0 ? 'red' : 'slate',
                ],
                [
                    'id' => 'time_to_purchase',
                    'label' => 'Tiempo promedio',
                    'value' => $avgMs !== null ? round($avgMs).' ms' : '—',
                    'tone' => 'blue',
                    'hint' => 'Disponible cuando hay eventos con duration_ms',
                ],
                [
                    'id' => 'recovered',
                    'label' => 'Retryables',
                    'value' => $retryables,
                    'tone' => $retryables > 0 ? 'orange' : 'slate',
                    'hint' => 'Eventos retryable + AC failed hoy',
                ],
                [
                    'id' => 'with_cart',
                    'label' => 'Pendientes',
                    'value' => $pending,
                    'tone' => $pending > 0 ? 'orange' : 'slate',
                    'hint' => 'AC pending/processing + payment pending/processing',
                ],
                [
                    'id' => 'conversion',
                    'label' => 'Success rate',
                    'value' => $successRate !== null ? $successRate.'%' : '—',
                    'tone' => ($successRate ?? 100) >= 95 ? 'green' : 'orange',
                ],
            ],
            'payment_proxy' => $paymentStats,
            'ac_proxy' => $acStats,
            'event_stats' => $eventStats,
            'drivers' => $drivers,
            'timeline' => $this->buildTimeline(),
            'performance' => $this->buildPerformance(),
            'queue' => $this->queue->build(),
            'architecture' => $this->architecture(),
            'roadmap' => $this->roadmap(),
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'events_table' => $this->repository->eventsTableReady(),
                'purpose' => 'Monitorear, auditar y diagnosticar la Automation Platform — sin ejecutar automatizaciones de negocio.',
                'source_of_truth' => 'automation_operation_events + proxies (payment_attempts, activecampaign_dispatches, config)',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buildDrivers(): array
    {
        $aggregates = $this->repository->driverAggregates();
        $registered = collect(config('order_automation.drivers', []))
            ->map(fn ($c) => is_string($c) ? class_basename($c) : class_basename($c::class))
            ->all();

        $catalog = config('automation_operations.drivers', []);
        $out = [];

        foreach ($catalog as $entry) {
            $name = $entry['name'];
            $stats = $aggregates[$name] ?? [
                'executions' => 0,
                'errors' => 0,
                'retryables' => 0,
                'avg_ms' => null,
                'last_at' => null,
            ];

            $status = $entry['status'];
            if ($status === 'active' && $entry['class'] && ! class_exists($entry['class'])) {
                $status = 'inactive';
            }

            $dto = new AutomationDriverStatus(
                key: $entry['key'],
                name: $name,
                layer: $entry['layer'],
                status: $status,
                version: $entry['version'] ?? null,
                description: $entry['description'] ?? '',
                class: $entry['class'],
                lastExecutionAt: $stats['last_at'],
                avgDurationMs: $stats['avg_ms'],
                errors: $stats['errors'],
                retryables: $stats['retryables'],
                executions: $stats['executions'],
                stats: [
                    'in_order_dispatcher' => in_array($name, $registered, true),
                ],
            );

            $out[] = $dto->toArray();
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buildTimeline(int $limit = 80): array
    {
        $items = [];

        foreach ($this->repository->recentEvents($limit) as $event) {
            /** @var AutomationOperationEvent $event */
            $items[] = (new AutomationTimelineItem(
                id: 'evt-'.$event->id,
                occurredAt: $event->occurred_at?->toIso8601String() ?? $event->created_at->toIso8601String(),
                automation: $event->automation,
                driver: $event->driver,
                result: $event->result,
                durationMs: $event->duration_ms,
                retryable: $event->retryable,
                channel: $event->channel,
                operation: $event->operation,
                reference: $event->reference,
                source: 'events',
                meta: $event->meta,
            ))->toArray();
        }

        // Proxy timeline from payment attempts when events are sparse
        if (count($items) < 20) {
            foreach ($this->repository->recentPaymentAttempts(40) as $attempt) {
                $result = match ($attempt->status) {
                    PaymentAttempt::STATUS_APPROVED => AutomationOperationEvent::RESULT_SUCCESS,
                    PaymentAttempt::STATUS_DECLINED, PaymentAttempt::STATUS_ERROR => AutomationOperationEvent::RESULT_FAILED,
                    PaymentAttempt::STATUS_PENDING, PaymentAttempt::STATUS_PROCESSING => 'pending',
                    default => $attempt->status,
                };

                $items[] = (new AutomationTimelineItem(
                    id: 'pa-'.$attempt->id,
                    occurredAt: ($attempt->processed_at ?? $attempt->created_at)->toIso8601String(),
                    automation: 'PaymentAutomation',
                    driver: 'ActiveCampaignPaymentDriver',
                    result: $result,
                    durationMs: null,
                    retryable: $attempt->status === PaymentAttempt::STATUS_ERROR,
                    channel: 'payment',
                    operation: 'handle'.ucfirst($attempt->status),
                    reference: $attempt->reference,
                    source: 'payment_attempt_proxy',
                    meta: [
                        'payment_attempt_id' => $attempt->id,
                        'status' => $attempt->status,
                        'retry_count' => $attempt->retry_count,
                    ],
                ))->toArray();
            }
        }

        usort($items, fn ($a, $b) => strcmp($b['occurred_at'], $a['occurred_at']));

        return array_slice($items, 0, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPerformance(): array
    {
        $from = now()->subHours(23)->startOfHour();
        $to = now()->endOfHour();
        $hourly = $this->normalizeHourlySeries($from, $to);

        $total = array_sum(array_column($hourly, 'total'));
        $success = array_sum(array_column($hourly, 'success'));
        $failed = array_sum(array_column($hourly, 'failed'));

        $queuePerf = $this->queue->performanceExtras();

        return [
            'hourly' => $hourly,
            'errors_hourly' => array_map(fn ($row) => [
                'label' => $row['label'],
                'hour' => $row['hour'],
                'errors' => $row['failed'],
            ], $hourly),
            'avg_duration_hourly' => array_map(fn ($row) => [
                'label' => $row['label'],
                'hour' => $row['hour'],
                'avg_ms' => $row['avg_ms'],
            ], $hourly),
            'slowest_drivers' => $queuePerf['avg_ms_by_driver'] !== []
                ? $queuePerf['avg_ms_by_driver']
                : $this->repository->slowestDrivers(),
            'success_rate' => $queuePerf['success_rate'] ?? ($total > 0 ? round(($success / $total) * 100, 1) : null),
            'retries_total' => $queuePerf['retries_total'],
            'dead_letters_total' => $queuePerf['dead_letters_total'],
            'p95_ms' => $queuePerf['p95_ms'],
            'p99_ms' => $queuePerf['p99_ms'],
            'avg_ms_by_driver' => $queuePerf['avg_ms_by_driver'],
            'totals' => [
                'total' => $total,
                'success' => $success,
                'failed' => $failed,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function architecture(): array
    {
        return [
            'nodes' => [
                ['id' => 'checkout', 'label' => 'Checkout', 'role' => 'entry'],
                ['id' => 'payment', 'label' => 'PaymentAutomation', 'role' => 'layer'],
                ['id' => 'order', 'label' => 'OrderAutomation', 'role' => 'layer'],
                ['id' => 'dispatcher', 'label' => 'Dispatcher', 'role' => 'fanout'],
                ['id' => 'job', 'label' => 'Automation Job', 'role' => 'fanout'],
                ['id' => 'worker', 'label' => 'Queue Worker', 'role' => 'workers'],
                ['id' => 'drivers', 'label' => 'Drivers', 'role' => 'workers'],
                ['id' => 'ac', 'label' => 'ActiveCampaign', 'role' => 'destination'],
                ['id' => 'email', 'label' => 'Email', 'role' => 'planned'],
                ['id' => 'whatsapp', 'label' => 'WhatsApp', 'role' => 'planned'],
                ['id' => 'push', 'label' => 'Push', 'role' => 'planned'],
                ['id' => 'analytics', 'label' => 'Analytics', 'role' => 'planned'],
                ['id' => 'ai', 'label' => 'IA', 'role' => 'planned'],
                ['id' => 'journey', 'label' => 'Customer Journey', 'role' => 'planned'],
                ['id' => 'health', 'label' => 'Customer Health', 'role' => 'planned'],
            ],
            'edges' => [
                ['from' => 'checkout', 'to' => 'payment'],
                ['from' => 'payment', 'to' => 'order'],
                ['from' => 'order', 'to' => 'dispatcher'],
                ['from' => 'dispatcher', 'to' => 'job'],
                ['from' => 'job', 'to' => 'worker'],
                ['from' => 'worker', 'to' => 'drivers'],
                ['from' => 'drivers', 'to' => 'ac'],
                ['from' => 'drivers', 'to' => 'email', 'planned' => true],
                ['from' => 'drivers', 'to' => 'whatsapp', 'planned' => true],
                ['from' => 'drivers', 'to' => 'push', 'planned' => true],
                ['from' => 'drivers', 'to' => 'analytics', 'planned' => true],
                ['from' => 'drivers', 'to' => 'ai', 'planned' => true],
                ['from' => 'drivers', 'to' => 'journey', 'planned' => true],
                ['from' => 'drivers', 'to' => 'health', 'planned' => true],
            ],
            'flow_text' => [
                'Checkout',
                'PaymentAutomation',
                'OrderAutomation',
                'Dispatcher',
                'Automation Job',
                'Queue Worker',
                'Drivers',
                'ActiveCampaign (+ canales futuros)',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function roadmap(): array
    {
        return [
            ['key' => 'email', 'label' => 'Email', 'status' => 'planned'],
            ['key' => 'whatsapp', 'label' => 'WhatsApp', 'status' => 'planned'],
            ['key' => 'push', 'label' => 'Push', 'status' => 'planned'],
            ['key' => 'analytics', 'label' => 'Analytics', 'status' => 'planned'],
            ['key' => 'ai', 'label' => 'IA', 'status' => 'planned'],
            ['key' => 'customer_journey', 'label' => 'Customer Journey', 'status' => 'planned'],
            ['key' => 'customer_health', 'label' => 'Customer Health', 'status' => 'planned'],
        ];
    }

    /**
     * Build hourly series in PHP (DB-agnostic).
     *
     * @return list<array{hour: string, label: string, total: int, success: int, failed: int, avg_ms: float|null}>
     */
    private function normalizeHourlySeries($from, $to): array
    {
        $hours = [];
        $cursor = $from->copy()->startOfHour();
        while ($cursor <= $to) {
            $key = $cursor->format('Y-m-d-H');
            $hours[$key] = [
                'hour' => $cursor->format('H:00'),
                'label' => $cursor->format('d/m H:00'),
                'total' => 0,
                'success' => 0,
                'failed' => 0,
                'avg_ms' => null,
                '_ms_sum' => 0.0,
                '_ms_n' => 0,
            ];
            $cursor->addHour();
        }

        if ($this->repository->eventsTableReady()) {
            AutomationOperationEvent::query()
                ->whereBetween('occurred_at', [$from, $to])
                ->orderBy('occurred_at')
                ->select(['occurred_at', 'result', 'duration_ms'])
                ->chunk(500, function ($chunk) use (&$hours) {
                    foreach ($chunk as $event) {
                        $key = $event->occurred_at->format('Y-m-d-H');
                        if (! isset($hours[$key])) {
                            continue;
                        }
                        $hours[$key]['total']++;
                        if ($event->result === AutomationOperationEvent::RESULT_SUCCESS) {
                            $hours[$key]['success']++;
                        }
                        if ($event->result === AutomationOperationEvent::RESULT_FAILED) {
                            $hours[$key]['failed']++;
                        }
                        if ($event->duration_ms !== null) {
                            $hours[$key]['_ms_sum'] += $event->duration_ms;
                            $hours[$key]['_ms_n']++;
                        }
                    }
                });
        } else {
            PaymentAttempt::query()
                ->whereBetween('created_at', [$from, $to])
                ->orderBy('created_at')
                ->select(['created_at', 'status'])
                ->chunk(500, function ($chunk) use (&$hours) {
                    foreach ($chunk as $attempt) {
                        $key = $attempt->created_at->format('Y-m-d-H');
                        if (! isset($hours[$key])) {
                            continue;
                        }
                        $hours[$key]['total']++;
                        if ($attempt->status === PaymentAttempt::STATUS_APPROVED) {
                            $hours[$key]['success']++;
                        }
                        if (in_array($attempt->status, [PaymentAttempt::STATUS_ERROR, PaymentAttempt::STATUS_DECLINED], true)) {
                            $hours[$key]['failed']++;
                        }
                    }
                });
        }

        return array_values(array_map(function (array $row) {
            if ($row['_ms_n'] > 0) {
                $row['avg_ms'] = round($row['_ms_sum'] / $row['_ms_n'], 1);
            }
            unset($row['_ms_sum'], $row['_ms_n']);

            return $row;
        }, $hours));
    }
}
