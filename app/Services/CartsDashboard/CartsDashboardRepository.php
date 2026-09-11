<?php

namespace App\Services\CartsDashboard;

use App\Enums\CartCheckoutFlowType;
use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\PaymentAttempt;
use App\Services\Carts\CartUserActivityResolver;
use App\Support\CartsDashboard\CartsDashboardFilter;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CartsDashboardRepository
{
    /**
     * @return array<string, mixed>
     */
    public function summary(CartsDashboardFilter $filter, Carbon $start, Carbon $end): array
    {
        $createdQuery = $this->baseCartsQuery($filter)
            ->whereBetween('carts.created_at', [$start, $end]);
        $abandonedQuery = $this->abandonedCartsQuery($filter, $start, $end);
        $completedQuery = $this->completedCartsQuery($filter, $start, $end);

        $created = (int) (clone $createdQuery)->count('carts.id');
        $abandoned = (int) (clone $abandonedQuery)->count('carts.id');
        $completed = (int) (clone $completedQuery)->count('carts.id');

        $createdValue = (float) (clone $createdQuery)->sum('carts.total');
        $abandonedValue = (float) (clone $abandonedQuery)->sum('carts.total');
        $completedValue = (float) (clone $completedQuery)->sum('carts.total');

        $eligible = $completed + $abandoned;

        return [
            'created' => $created,
            'abandoned' => $abandoned,
            'completed' => $completed,
            'created_value' => round($createdValue, 2),
            'abandoned_value' => round($abandonedValue, 2),
            'completed_value' => round($completedValue, 2),
            'abandonment_percent' => $created > 0 ? round(100 * $abandoned / $created, 1) : null,
            'conversion_percent' => $eligible > 0 ? round(100 * $completed / $eligible, 1) : null,
            'avg_ticket_created' => $created > 0 ? round($createdValue / $created, 2) : null,
            'avg_ticket_abandoned' => $abandoned > 0 ? round($abandonedValue / $abandoned, 2) : null,
            'avg_ticket_completed' => $completed > 0 ? round($completedValue / $completed, 2) : null,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function operationalSummary(CartsDashboardFilter $filter): array
    {
        return [
            'attention_required' => $this->operationalCount($filter, 'attention'),
            'payment_incidents' => $this->operationalCount($filter, 'payment'),
            'payment_declined' => $this->paymentStatusCount($filter, PaymentAttempt::STATUS_DECLINED),
            'payment_error' => $this->paymentStatusCount($filter, PaymentAttempt::STATUS_ERROR),
            'payment_pending' => $this->paymentStatusCount($filter, PaymentAttempt::STATUS_PENDING),
            'payment_available' => $this->appointmentConfirmedPendingPaymentCount($filter),
            'appointment_pending_active' => $this->appointmentPendingCount($filter),
            'appointment_confirmed_without_payment' => $this->appointmentConfirmedPendingPaymentCount($filter),
            'appointments_to_handle' => $this->appointmentPendingCount($filter)
                + $this->appointmentConfirmedPendingPaymentCount($filter),
            'contact_requested' => $this->callbackRequestedCount($filter) + $this->phoneIntentCount($filter),
            'callback_requested' => $this->callbackRequestedCount($filter),
            'phone_call_intent' => $this->phoneIntentCount($filter),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function dailyCarts(CartsDashboardFilter $filter): array
    {
        $days = $this->localDateKeys($filter->startLocal, $filter->endLocal);
        $created = $this->dailyCreatedTotals($filter);
        $abandoned = $this->dailyAbandonedTotals($filter);
        $completed = $this->dailyCompletedTotals($filter);

        return collect($days)->map(function (string $date) use ($created, $abandoned, $completed) {
            $createdRow = $created[$date] ?? ['amount' => 0.0, 'count' => 0];
            $abandonedRow = $abandoned[$date] ?? ['amount' => 0.0, 'count' => 0];
            $completedRow = $completed[$date] ?? ['amount' => 0.0, 'count' => 0];

            return [
                'date' => $date,
                'label' => Carbon::parse($date)->format('d/m'),
                'created_count' => (int) $createdRow['count'],
                'abandoned_count' => (int) $abandonedRow['count'],
                'completed_count' => (int) $completedRow['count'],
                'created_amount' => round((float) $createdRow['amount'], 2),
                'abandoned_amount' => round((float) $abandonedRow['amount'], 2),
                'completed_amount' => round((float) $completedRow['amount'], 2),
            ];
        })->values()->all();
    }

    /**
     * @return list<array{key: string, label: string, count: int}>
     */
    public function checkoutFunnel(CartsDashboardFilter $filter): array
    {
        return [
            [
                'key' => 'cart',
                'label' => 'Carrito',
                'count' => (int) $this->baseCartsQuery($filter)
                    ->whereBetween('carts.created_at', [$filter->start, $filter->end])
                    ->count('carts.id'),
            ],
            [
                'key' => 'checkout_started',
                'label' => 'Checkout iniciado',
                'count' => (int) $this->baseCartsQuery($filter)
                    ->whereBetween('carts.created_at', [$filter->start, $filter->end])
                    ->where(function (Builder $query) {
                        $query->whereExists($this->checkoutDraftExistsSubquery())
                            ->orWhereExists($this->appointmentExistsSubquery())
                            ->orWhere(fn (Builder $q) => $q->whereExists($this->paymentStatusExistsSubquery([
                                PaymentAttempt::STATUS_PENDING,
                                PaymentAttempt::STATUS_PROCESSING,
                                PaymentAttempt::STATUS_APPROVED,
                                PaymentAttempt::STATUS_DECLINED,
                                PaymentAttempt::STATUS_ERROR,
                            ])));
                    })
                    ->count('carts.id'),
            ],
            [
                'key' => 'appointment',
                'label' => 'Cita',
                'count' => (int) $this->baseCartsQuery($filter)
                    ->whereBetween('carts.created_at', [$filter->start, $filter->end])
                    ->whereExists($this->appointmentExistsSubquery())
                    ->count('carts.id'),
            ],
            [
                'key' => 'payment_attempted',
                'label' => 'Pago intentado',
                'count' => (int) $this->baseCartsQuery($filter)
                    ->whereBetween('carts.created_at', [$filter->start, $filter->end])
                    ->whereExists($this->paymentStatusExistsSubquery([
                        PaymentAttempt::STATUS_PENDING,
                        PaymentAttempt::STATUS_PROCESSING,
                        PaymentAttempt::STATUS_APPROVED,
                        PaymentAttempt::STATUS_DECLINED,
                        PaymentAttempt::STATUS_ERROR,
                    ]))
                    ->count('carts.id'),
            ],
            [
                'key' => 'completed',
                'label' => 'Compra',
                'count' => (int) $this->completedCartsQuery($filter, $filter->start, $filter->end)->count('carts.id'),
            ],
        ];
    }

    /**
     * Embudos por flujo de checkout (cita primero vs estándar).
     *
     * @return array<string, mixed>
     */
    public function checkoutFunnelByFlow(CartsDashboardFilter $filter): array
    {
        return [
            'distribution' => $this->checkoutFlowDistribution($filter),
            'appointment_first' => $this->flowCheckoutFunnel($filter, CartCheckoutFlowType::AppointmentFirst),
            'standard' => $this->flowCheckoutFunnel($filter, CartCheckoutFlowType::Standard),
            'milestone_notes' => [
                'appointment_first' => 'Estudios → Paciente → Dirección → Cita → Pago → Compra',
                'standard' => 'Estudios → Paciente → Dirección → Pago → Cita → Compra',
                'abandonment' => 'Cita pendiente de concierge no se cuenta como abandono.',
            ],
        ];
    }

    /**
     * @return list<array{key: string, label: string, count: int}>
     */
    private function checkoutFlowDistribution(CartsDashboardFilter $filter): array
    {
        $base = $this->baseCartsQuery($filter)
            ->whereBetween('carts.created_at', [$filter->start, $filter->end])
            ->where('carts.type', MonitoringCartType::Lab->value);

        $appointmentFirst = (int) (clone $base)
            ->whereExists($this->storedCheckoutFlowExistsSubquery(CartCheckoutFlowType::AppointmentFirst->value))
            ->count('carts.id');
        $standard = (int) (clone $base)
            ->whereExists($this->storedCheckoutFlowExistsSubquery(CartCheckoutFlowType::Standard->value))
            ->count('carts.id');
        $total = (int) (clone $base)->count('carts.id');

        return [
            ['key' => 'appointment_first', 'label' => 'Cita primero', 'count' => $appointmentFirst],
            ['key' => 'standard', 'label' => 'Estándar', 'count' => $standard],
            ['key' => 'unknown', 'label' => 'Sin flujo persistido', 'count' => max(0, $total - $appointmentFirst - $standard)],
        ];
    }

    /**
     * @return list<array{key: string, label: string, count: int}>
     */
    private function flowCheckoutFunnel(CartsDashboardFilter $filter, CartCheckoutFlowType $flow): array
    {
        $milestones = $this->flowMilestoneCounts($filter, $flow);

        $orderedKeys = $flow === CartCheckoutFlowType::AppointmentFirst
            ? ['cart', 'checkout_started', 'patient', 'address', 'appointment', 'payment_attempted', 'completed']
            : ['cart', 'checkout_started', 'patient', 'address', 'payment_attempted', 'appointment', 'completed'];

        $labels = [
            'cart' => 'Carrito',
            'checkout_started' => 'Checkout iniciado',
            'patient' => 'Paciente',
            'address' => 'Dirección',
            'appointment' => 'Cita',
            'payment_attempted' => 'Pago intentado',
            'completed' => 'Compra',
        ];

        return collect($orderedKeys)
            ->map(fn (string $key) => [
                'key' => $key,
                'label' => $labels[$key],
                'count' => (int) ($milestones[$key] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function flowMilestoneCounts(CartsDashboardFilter $filter, CartCheckoutFlowType $flow): array
    {
        $scoped = fn () => $this->baseCartsQuery($filter)
            ->whereBetween('carts.created_at', [$filter->start, $filter->end])
            ->where('carts.type', MonitoringCartType::Lab->value)
            ->whereExists($this->storedCheckoutFlowExistsSubquery($flow->value));

        return [
            'cart' => (int) $scoped()->count('carts.id'),
            'checkout_started' => (int) $scoped()
                ->where(function (Builder $query) {
                    $query->whereExists($this->checkoutDraftExistsSubquery())
                        ->orWhereExists($this->appointmentExistsSubquery())
                        ->orWhereExists($this->paymentStatusExistsSubquery([
                            PaymentAttempt::STATUS_PENDING,
                            PaymentAttempt::STATUS_PROCESSING,
                            PaymentAttempt::STATUS_APPROVED,
                            PaymentAttempt::STATUS_DECLINED,
                            PaymentAttempt::STATUS_ERROR,
                        ]));
                })
                ->count('carts.id'),
            'patient' => (int) $scoped()
                ->whereExists($this->checkoutDraftFieldExistsSubquery('contact_id'))
                ->count('carts.id'),
            'address' => (int) $scoped()
                ->whereExists($this->checkoutDraftFieldExistsSubquery('address_id'))
                ->count('carts.id'),
            'appointment' => (int) $scoped()
                ->whereExists($this->appointmentExistsSubquery())
                ->count('carts.id'),
            'payment_attempted' => (int) $scoped()
                ->whereExists($this->paymentStatusExistsSubquery([
                    PaymentAttempt::STATUS_PENDING,
                    PaymentAttempt::STATUS_PROCESSING,
                    PaymentAttempt::STATUS_APPROVED,
                    PaymentAttempt::STATUS_DECLINED,
                    PaymentAttempt::STATUS_ERROR,
                ]))
                ->count('carts.id'),
            'completed' => (int) $scoped()
                ->where('carts.status', MonitoringCartStatus::Completed->value)
                ->count('carts.id'),
        ];
    }

    /**
     * @return list<array{key: string, label: string, count: int, percent: float}>
     */
    public function abandonmentByStage(CartsDashboardFilter $filter): array
    {
        $total = max(1, (int) $this->abandonedCartsQuery($filter, $filter->start, $filter->end)->count('carts.id'));

        $rows = [
            ['key' => 'payment', 'label' => 'Pago', 'count' => $this->abandonedStagePaymentCount($filter)],
            ['key' => 'appointment', 'label' => 'Cita', 'count' => $this->abandonedStageAppointmentCount($filter)],
            ['key' => 'checkout', 'label' => 'Checkout iniciado', 'count' => $this->abandonedStageCheckoutCount($filter)],
            ['key' => 'cart', 'label' => 'Carrito', 'count' => $this->abandonedStageCartCount($filter)],
        ];

        return collect($rows)
            ->filter(fn (array $row) => $row['count'] > 0)
            ->map(fn (array $row) => [
                'key' => $row['key'],
                'label' => $row['label'],
                'count' => $row['count'],
                'percent' => round(100 * $row['count'] / $total, 1),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function paymentAnalytics(CartsDashboardFilter $filter): array
    {
        $declined = $this->paymentStatusCount($filter, PaymentAttempt::STATUS_DECLINED);
        $error = $this->paymentStatusCount($filter, PaymentAttempt::STATUS_ERROR);
        $pending = $this->paymentStatusCount($filter, PaymentAttempt::STATUS_PENDING);

        return [
            'status_breakdown' => [
                ['key' => 'declined', 'label' => 'Pago rechazado', 'count' => $declined],
                ['key' => 'error', 'label' => 'Error tecnico', 'count' => $error],
                ['key' => 'pending', 'label' => 'Pago pendiente', 'count' => $pending],
            ],
            'incident_rate' => null,
            'trend' => $this->dailyPaymentIncidents($filter),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function dailyPaymentIncidents(CartsDashboardFilter $filter): array
    {
        $declined = $this->dailyPaymentStatusCounts($filter, PaymentAttempt::STATUS_DECLINED);
        $error = $this->dailyPaymentStatusCounts($filter, PaymentAttempt::STATUS_ERROR);
        $pending = $this->dailyPaymentStatusCounts($filter, PaymentAttempt::STATUS_PENDING);

        return collect($this->localDateKeys($filter->startLocal, $filter->endLocal))
            ->map(fn (string $date) => [
                'date' => $date,
                'label' => Carbon::parse($date)->format('d/m'),
                'declined' => (int) ($declined[$date] ?? 0),
                'error' => (int) ($error[$date] ?? 0),
                'pending' => (int) ($pending[$date] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function appointmentAnalytics(CartsDashboardFilter $filter): array
    {
        return [
            'status_breakdown' => [
                ['key' => 'without_appointment', 'label' => 'Sin cita', 'count' => $this->withoutAppointmentCount($filter)],
                ['key' => 'pending', 'label' => 'Cita pendiente', 'count' => $this->appointmentPendingCount($filter)],
                ['key' => 'confirmed_without_payment', 'label' => 'Cita confirmada sin pago', 'count' => $this->appointmentConfirmedPendingPaymentCount($filter)],
                ['key' => 'confirmed_with_purchase', 'label' => 'Cita confirmada con compra', 'count' => $this->appointmentConfirmedWithPurchaseCount($filter)],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function contactAnalytics(CartsDashboardFilter $filter): array
    {
        return [
            'summary' => [
                ['key' => 'callback_requested', 'label' => 'Solicito llamada', 'count' => $this->callbackRequestedCount($filter)],
                ['key' => 'phone_call_intent', 'label' => 'Intento llamar', 'count' => $this->phoneIntentCount($filter)],
            ],
            'trend' => $this->dailyContactSignals($filter),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function dailyContactSignals(CartsDashboardFilter $filter): array
    {
        $callback = $this->dailyContactCounts($filter, $this->callbackExistsSubquery());
        $phone = $this->dailyContactCounts($filter, $this->phoneIntentExistsSubquery());

        return collect($this->localDateKeys($filter->startLocal, $filter->endLocal))
            ->map(fn (string $date) => [
                'date' => $date,
                'label' => Carbon::parse($date)->format('d/m'),
                'callback_requested' => (int) ($callback[$date] ?? 0),
                'phone_call_intent' => (int) ($phone[$date] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function laboratoryRanking(CartsDashboardFilter $filter): array
    {
        if ($filter->type === MonitoringCartType::Pharmacy->value) {
            return [];
        }

        $brands = $filter->brand
            ? collect([LaboratoryBrand::tryFrom($filter->brand)])->filter()
            : collect(LaboratoryBrand::cases());

        $rows = $brands->map(function (LaboratoryBrand $brand) use ($filter) {
            return $this->laboratoryRow($filter, $brand->value, $brand->label());
        });

        if (! $filter->brand) {
            $rows->push($this->laboratoryRow($filter, null, 'Sin identificar', true));
        }

        return $rows
            ->filter(fn (array $row) => $row['carts_count'] > 0
                || $row['abandoned_count'] > 0
                || $row['completed_count'] > 0
                || $row['abandoned_value'] > 0)
            ->sortByDesc('abandoned_count')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function customerProfile(CartsDashboardFilter $filter): array
    {
        $rows = [
            'new' => ['key' => 'new', 'label' => 'Cliente nuevo', 'abandoned_count' => 0, 'abandoned_value' => 0.0, 'created_count' => 0, 'completed_count' => 0],
            'existing' => ['key' => 'existing', 'label' => 'Cliente existente', 'abandoned_count' => 0, 'abandoned_value' => 0.0, 'created_count' => 0, 'completed_count' => 0],
            'recurring' => ['key' => 'recurring', 'label' => 'Cliente recurrente', 'abandoned_count' => 0, 'abandoned_value' => 0.0, 'created_count' => 0, 'completed_count' => 0],
        ];

        foreach (array_keys($rows) as $segment) {
            $created = $this->customerSegmentQuery($filter, $segment)
                ->whereBetween('carts.created_at', [$filter->start, $filter->end]);
            $abandoned = $this->customerSegmentQuery($filter, $segment)
                ->where('carts.status', MonitoringCartStatus::Active->value)
                ->whereRaw($this->lastUserActivityAtSql().' < ?', [$this->staleBefore()])
                ->whereRaw($this->lastUserActivityAtSql().' BETWEEN ? AND ?', [$filter->start, $filter->end])
                ->whereNotExists($this->appointmentExistsSubquery(function (Builder $appointment) {
                    $appointment->whereNull('la.confirmed_at');
                }));
            $completed = $this->customerSegmentQuery($filter, $segment)
                ->where('carts.status', MonitoringCartStatus::Completed->value)
                ->where(function (Builder $query) use ($filter) {
                    $query->whereBetween('carts.completed_at', [$filter->start, $filter->end])
                        ->orWhere(function (Builder $fallback) use ($filter) {
                            $fallback->whereNull('carts.completed_at')
                                ->whereBetween('carts.updated_at', [$filter->start, $filter->end]);
                        });
                });

            $rows[$segment]['created_count'] = (int) (clone $created)->count('carts.id');
            $rows[$segment]['abandoned_count'] = (int) (clone $abandoned)->count('carts.id');
            $rows[$segment]['abandoned_value'] = round((float) (clone $abandoned)->sum('carts.total'), 2);
            $rows[$segment]['completed_count'] = (int) (clone $completed)->count('carts.id');
        }

        $totalAbandoned = max(1, array_sum(array_column($rows, 'abandoned_count')));

        return [
            'segments' => collect($rows)->map(fn (array $row) => array_merge($row, [
                'abandoned_percent' => round(100 * $row['abandoned_count'] / $totalAbandoned, 1),
                'conversion_percent' => ($row['completed_count'] + $row['abandoned_count']) > 0
                    ? round(100 * $row['completed_count'] / ($row['completed_count'] + $row['abandoned_count']), 1)
                    : null,
            ]))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function topStudies(CartsDashboardFilter $filter, int $limit = 10): array
    {
        return [
            'abandoned' => $this->topAbandonedStudies($filter, $limit),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ticketAverages(CartsDashboardFilter $filter): array
    {
        $current = $this->summary($filter, $filter->start, $filter->end);

        return [
            'avg_ticket_created' => $current['avg_ticket_created'],
            'avg_ticket_abandoned' => $current['avg_ticket_abandoned'],
            'avg_ticket_completed' => $current['avg_ticket_completed'],
        ];
    }

    private function operationalCount(CartsDashboardFilter $filter, string $bucket): int
    {
        return (int) $this->eloquentBaseCartsQuery($filter)
            ->whereRaw($this->lastUserActivityAtSql().' BETWEEN ? AND ?', [$filter->start, $filter->end])
            ->operationalBucket($bucket)
            ->count('carts.id');
    }

    private function paymentStatusCount(CartsDashboardFilter $filter, string $status): int
    {
        return (int) $this->eloquentBaseCartsQuery($filter)
            ->whereRaw($this->lastUserActivityAtSql().' BETWEEN ? AND ?', [$filter->start, $filter->end])
            ->relatedPaymentAttemptStatus($status)
            ->count('carts.id');
    }

    private function appointmentPendingCount(CartsDashboardFilter $filter): int
    {
        return (int) $this->eloquentBaseCartsQuery($filter)
            ->whereRaw($this->lastUserActivityAtSql().' BETWEEN ? AND ?', [$filter->start, $filter->end])
            ->appointmentPendingConfirmation()
            ->count('carts.id');
    }

    private function appointmentConfirmedPendingPaymentCount(CartsDashboardFilter $filter): int
    {
        return (int) $this->eloquentBaseCartsQuery($filter)
            ->whereRaw($this->lastUserActivityAtSql().' BETWEEN ? AND ?', [$filter->start, $filter->end])
            ->appointmentConfirmedPendingPayment()
            ->count('carts.id');
    }

    private function callbackRequestedCount(CartsDashboardFilter $filter, ?Carbon $start = null, ?Carbon $end = null): int
    {
        return (int) $this->baseCartsQuery($filter)
            ->where('carts.status', '!=', MonitoringCartStatus::Completed->value)
            ->whereRaw($this->lastUserActivityAtSql().' BETWEEN ? AND ?', [$start ?? $filter->start, $end ?? $filter->end])
            ->whereExists($this->callbackExistsSubquery())
            ->count('carts.id');
    }

    private function phoneIntentCount(CartsDashboardFilter $filter, ?Carbon $start = null, ?Carbon $end = null): int
    {
        return (int) $this->baseCartsQuery($filter)
            ->where('carts.status', '!=', MonitoringCartStatus::Completed->value)
            ->whereRaw($this->lastUserActivityAtSql().' BETWEEN ? AND ?', [$start ?? $filter->start, $end ?? $filter->end])
            ->whereExists($this->phoneIntentExistsSubquery())
            ->count('carts.id');
    }

    private function withoutAppointmentCount(CartsDashboardFilter $filter): int
    {
        return (int) $this->baseCartsQuery($filter)
            ->where('carts.type', MonitoringCartType::Lab->value)
            ->whereRaw($this->lastUserActivityAtSql().' BETWEEN ? AND ?', [$filter->start, $filter->end])
            ->whereNotExists($this->appointmentExistsSubquery())
            ->count('carts.id');
    }

    private function appointmentConfirmedWithPurchaseCount(CartsDashboardFilter $filter): int
    {
        return (int) $this->baseCartsQuery($filter)
            ->where('carts.type', MonitoringCartType::Lab->value)
            ->whereRaw($this->lastUserActivityAtSql().' BETWEEN ? AND ?', [$filter->start, $filter->end])
            ->whereExists($this->appointmentExistsSubquery(function (Builder $appointment) {
                $appointment->whereNotNull('la.confirmed_at')
                    ->whereNotNull('la.laboratory_purchase_id');
            }))
            ->count('carts.id');
    }

    private function abandonedStagePaymentCount(CartsDashboardFilter $filter): int
    {
        return (int) $this->eloquentBaseCartsQuery($filter)
            ->where('carts.status', MonitoringCartStatus::Active->value)
            ->where('carts.updated_at', '<', $this->staleBefore())
            ->whereBetween('carts.updated_at', [$filter->start, $filter->end])
            ->operationalPaymentBucket()
            ->count('carts.id');
    }

    private function abandonedStageAppointmentCount(CartsDashboardFilter $filter): int
    {
        return (int) $this->eloquentBaseCartsQuery($filter)
            ->where('carts.status', MonitoringCartStatus::Active->value)
            ->where('carts.updated_at', '<', $this->staleBefore())
            ->whereBetween('carts.updated_at', [$filter->start, $filter->end])
            ->where(fn (EloquentBuilder $query) => $query->operationalAppointmentBucket())
            ->count('carts.id');
    }

    private function abandonedStageCheckoutCount(CartsDashboardFilter $filter): int
    {
        return (int) $this->abandonedCartsQuery($filter, $filter->start, $filter->end)
            ->whereExists($this->checkoutDraftExistsSubquery())
            ->whereNotExists($this->paymentStatusExistsSubquery([
                PaymentAttempt::STATUS_PENDING,
                PaymentAttempt::STATUS_PROCESSING,
                PaymentAttempt::STATUS_DECLINED,
                PaymentAttempt::STATUS_ERROR,
                PaymentAttempt::STATUS_APPROVED,
            ]))
            ->whereNotExists($this->appointmentExistsSubquery())
            ->count('carts.id');
    }

    private function abandonedStageCartCount(CartsDashboardFilter $filter): int
    {
        return (int) $this->abandonedCartsQuery($filter, $filter->start, $filter->end)
            ->whereNotExists($this->checkoutDraftExistsSubquery())
            ->whereNotExists($this->paymentStatusExistsSubquery([
                PaymentAttempt::STATUS_PENDING,
                PaymentAttempt::STATUS_PROCESSING,
                PaymentAttempt::STATUS_DECLINED,
                PaymentAttempt::STATUS_ERROR,
                PaymentAttempt::STATUS_APPROVED,
            ]))
            ->whereNotExists($this->appointmentExistsSubquery())
            ->count('carts.id');
    }

    /**
     * @return array<string, array{amount: float, count: int}>
     */
    private function dailyCreatedTotals(CartsDashboardFilter $filter): array
    {
        return $this->dailyTotals($this->baseCartsQuery($filter)
            ->whereBetween('carts.created_at', [$filter->start, $filter->end]), 'carts.created_at');
    }

    /**
     * @return array<string, array{amount: float, count: int}>
     */
    private function dailyCompletedTotals(CartsDashboardFilter $filter): array
    {
        return $this->dailyTotals($this->completedCartsQuery($filter, $filter->start, $filter->end), 'COALESCE(carts.completed_at, carts.updated_at)');
    }

    /**
     * @return array<string, array{amount: float, count: int}>
     */
    private function dailyAbandonedTotals(CartsDashboardFilter $filter): array
    {
        return $this->dailyTotals($this->abandonedCartsQuery($filter, $filter->start, $filter->end), $this->lastUserActivityAtSql());
    }

    /**
     * @return array<string, int>
     */
    private function dailyPaymentStatusCounts(CartsDashboardFilter $filter, string $status): array
    {
        $driver = DB::connection()->getDriverName();
        $dateExpr = $this->localDateExpression($this->lastUserActivityAtSql(), $driver);

        return $this->eloquentBaseCartsQuery($filter)
            ->whereRaw($this->lastUserActivityAtSql().' BETWEEN ? AND ?', [$filter->start, $filter->end])
            ->relatedPaymentAttemptStatus($status)
            ->selectRaw("{$dateExpr} as day_key, COUNT(DISTINCT carts.id) as aggregate")
            ->groupBy('day_key')
            ->pluck('aggregate', 'day_key')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function dailyContactCounts(CartsDashboardFilter $filter, \Closure $signalSubquery): array
    {
        $driver = DB::connection()->getDriverName();
        $dateExpr = $this->localDateExpression($this->lastUserActivityAtSql(), $driver);

        return $this->baseCartsQuery($filter)
            ->where('carts.status', '!=', MonitoringCartStatus::Completed->value)
            ->whereRaw($this->lastUserActivityAtSql().' BETWEEN ? AND ?', [$filter->start, $filter->end])
            ->whereExists($signalSubquery)
            ->selectRaw("{$dateExpr} as day_key, COUNT(DISTINCT carts.id) as aggregate")
            ->groupBy('day_key')
            ->pluck('aggregate', 'day_key')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @return array<string, array{amount: float, count: int}>
     */
    private function dailyTotals(Builder $query, string $dateColumn): array
    {
        $driver = DB::connection()->getDriverName();
        $dateExpr = $this->localDateExpression($dateColumn, $driver);

        return $query
            ->selectRaw("{$dateExpr} as day_key, SUM(carts.total) as amount, COUNT(DISTINCT carts.id) as aggregate")
            ->groupBy('day_key')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (string) $row->day_key => [
                    'amount' => (float) $row->amount,
                    'count' => (int) $row->aggregate,
                ],
            ])
            ->all();
    }

    private function laboratoryRow(CartsDashboardFilter $filter, ?string $brand, string $label, bool $unknown = false): array
    {
        $created = $this->brandScopedQuery($filter, $brand, $unknown)
            ->whereBetween('carts.created_at', [$filter->start, $filter->end]);
        $abandoned = $this->brandScopedQuery($filter, $brand, $unknown)
            ->where('carts.status', MonitoringCartStatus::Active->value)
            ->whereRaw($this->lastUserActivityAtSql().' < ?', [$this->staleBefore()])
            ->whereRaw($this->lastUserActivityAtSql().' BETWEEN ? AND ?', [$filter->start, $filter->end])
            ->whereNotExists($this->appointmentExistsSubquery(function (Builder $appointment) {
                $appointment->whereNull('la.confirmed_at');
            }));
        $completed = $this->brandScopedQuery($filter, $brand, $unknown)
            ->where('carts.status', MonitoringCartStatus::Completed->value)
            ->where(function (Builder $query) use ($filter) {
                $query->whereBetween('carts.completed_at', [$filter->start, $filter->end])
                    ->orWhere(function (Builder $fallback) use ($filter) {
                        $fallback->whereNull('carts.completed_at')
                            ->whereBetween('carts.updated_at', [$filter->start, $filter->end]);
                    });
            });

        $completedCount = (int) (clone $completed)->count('carts.id');
        $abandonedCount = (int) (clone $abandoned)->count('carts.id');
        $eligible = $completedCount + $abandonedCount;

        return [
            'brand' => $brand ?? 'unknown',
            'brand_label' => $label,
            'carts_count' => (int) (clone $created)->count('carts.id'),
            'abandoned_count' => $abandonedCount,
            'completed_count' => $completedCount,
            'conversion_percent' => $eligible > 0 ? round(100 * $completedCount / $eligible, 1) : null,
            'abandoned_value' => round((float) (clone $abandoned)->sum('carts.total'), 2),
        ];
    }

    /**
     * @return list<array{id: string, name: string, brand: string, carts: int, quantity: int}>
     */
    private function topAbandonedStudies(CartsDashboardFilter $filter, int $limit): array
    {
        if ($filter->type === MonitoringCartType::Pharmacy->value) {
            return [];
        }

        return $this->abandonedCartsQuery($filter, $filter->start, $filter->end)
            ->join('cart_items', 'cart_items.cart_id', '=', 'carts.id')
            ->leftJoin('laboratory_tests', function ($join) {
                $join->whereRaw('laboratory_tests.id = cart_items.product_id');
            })
            ->selectRaw('cart_items.product_id as study_id, cart_items.name as study_name, COALESCE(laboratory_tests.brand, ?) as brand, COUNT(DISTINCT carts.id) as carts, SUM(cart_items.quantity) as quantity', ['unknown'])
            ->groupBy('cart_items.product_id', 'cart_items.name', 'brand')
            ->orderByDesc('carts')
            ->orderByDesc('quantity')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (string) ($row->study_id ?: $row->study_name),
                'name' => (string) $row->study_name,
                'brand' => $this->brandLabel((string) $row->brand),
                'carts' => (int) $row->carts,
                'quantity' => (int) $row->quantity,
            ])
            ->values()
            ->all();
    }

    private function baseCartsQuery(CartsDashboardFilter $filter, ?string $forceBrand = null): Builder
    {
        $query = DB::table('carts');

        $this->applyOperationalMonitoringScope($query);

        if ($filter->type) {
            $query->where('carts.type', $filter->type);
        }

        $brand = $forceBrand ?? $filter->brand;
        if ($brand) {
            $this->applyKnownBrand($query, $brand);
        }

        return $query;
    }

    private function eloquentBaseCartsQuery(CartsDashboardFilter $filter): EloquentBuilder
    {
        $query = Cart::query()->from('carts')->operationalMonitoring();

        if ($filter->type) {
            $query->where('carts.type', $filter->type);
        }

        if ($filter->brand) {
            $query->where('carts.type', MonitoringCartType::Lab->value)
                ->whereExists(function (Builder $sub) use ($filter) {
                    $sub->select(DB::raw(1))
                        ->from('cart_items')
                        ->join('laboratory_tests', function ($join) {
                            $join->whereRaw('laboratory_tests.id = cart_items.product_id');
                        })
                        ->whereColumn('cart_items.cart_id', 'carts.id')
                        ->where('laboratory_tests.brand', $filter->brand);
                });
        }

        return $query;
    }

    private function abandonedCartsQuery(CartsDashboardFilter $filter, Carbon $start, Carbon $end): Builder
    {
        return $this->baseCartsQuery($filter)
            ->where('carts.status', MonitoringCartStatus::Active->value)
            ->whereRaw($this->lastUserActivityAtSql().' < ?', [$this->staleBefore()])
            ->whereRaw($this->lastUserActivityAtSql().' BETWEEN ? AND ?', [$start, $end])
            ->whereNotExists($this->appointmentExistsSubquery(function (Builder $appointment) {
                $appointment->whereNull('la.confirmed_at');
            }));
    }

    private function completedCartsQuery(CartsDashboardFilter $filter, Carbon $start, Carbon $end): Builder
    {
        return $this->baseCartsQuery($filter)
            ->where('carts.status', MonitoringCartStatus::Completed->value)
            ->where(function (Builder $query) use ($start, $end) {
                $query->whereBetween('carts.completed_at', [$start, $end])
                    ->orWhere(function (Builder $fallback) use ($start, $end) {
                        $fallback->whereNull('carts.completed_at')
                            ->whereBetween('carts.updated_at', [$start, $end]);
                    });
            });
    }

    private function brandScopedQuery(CartsDashboardFilter $filter, ?string $brand, bool $unknown): Builder
    {
        $query = $this->baseCartsQuery($filter)
            ->where('carts.type', MonitoringCartType::Lab->value);

        if ($unknown) {
            $query->whereNotExists($this->knownBrandExistsSubquery());
        } elseif ($brand) {
            $this->applyKnownBrand($query, $brand);
        }

        return $query;
    }

    private function applyKnownBrand(Builder $query, string $brand): void
    {
        $query->where('carts.type', MonitoringCartType::Lab->value)
            ->whereExists(function (Builder $sub) use ($brand) {
                $sub->select(DB::raw(1))
                    ->from('cart_items')
                    ->join('laboratory_tests', function ($join) {
                        $join->whereRaw('laboratory_tests.id = cart_items.product_id');
                    })
                    ->whereColumn('cart_items.cart_id', 'carts.id')
                    ->where('laboratory_tests.brand', $brand);
            });
    }

    private function customerSegmentQuery(CartsDashboardFilter $filter, string $segment): Builder
    {
        $query = $this->baseCartsQuery($filter);

        $query->whereExists(function (Builder $sub) use ($segment) {
            $sub->select(DB::raw(1))
                ->from('customers as profile_customers')
                ->whereColumn('profile_customers.user_id', 'carts.user_id');

            if ($segment === 'new') {
                $sub->whereNotExists(function (Builder $purchase) {
                    $purchase->select(DB::raw(1))
                        ->from('laboratory_purchases')
                        ->whereColumn('laboratory_purchases.customer_id', 'profile_customers.id')
                        ->whereNull('laboratory_purchases.deleted_at')
                        ->whereColumn('laboratory_purchases.created_at', '<', 'carts.created_at');
                })->whereNotExists(function (Builder $purchase) {
                    $purchase->select(DB::raw(1))
                        ->from('online_pharmacy_purchases')
                        ->whereColumn('online_pharmacy_purchases.customer_id', 'profile_customers.id')
                        ->whereNull('online_pharmacy_purchases.deleted_at')
                        ->whereColumn('online_pharmacy_purchases.created_at', '<', 'carts.created_at');
                });
            } elseif ($segment === 'recurring') {
                $sub->whereRaw('((
                    SELECT COUNT(*)
                    FROM laboratory_purchases
                    WHERE laboratory_purchases.customer_id = profile_customers.id
                        AND laboratory_purchases.deleted_at IS NULL
                        AND laboratory_purchases.created_at < carts.created_at
                ) + (
                    SELECT COUNT(*)
                    FROM online_pharmacy_purchases
                    WHERE online_pharmacy_purchases.customer_id = profile_customers.id
                        AND online_pharmacy_purchases.deleted_at IS NULL
                        AND online_pharmacy_purchases.created_at < carts.created_at
                )) >= 2');
            } else {
                $sub->whereRaw('((
                    SELECT COUNT(*)
                    FROM laboratory_purchases
                    WHERE laboratory_purchases.customer_id = profile_customers.id
                        AND laboratory_purchases.deleted_at IS NULL
                        AND laboratory_purchases.created_at < carts.created_at
                ) + (
                    SELECT COUNT(*)
                    FROM online_pharmacy_purchases
                    WHERE online_pharmacy_purchases.customer_id = profile_customers.id
                        AND online_pharmacy_purchases.deleted_at IS NULL
                        AND online_pharmacy_purchases.created_at < carts.created_at
                )) = 1');
            }
        });

        return $query;
    }

    private function knownBrandExistsSubquery(): \Closure
    {
        return function (Builder $sub) {
            $sub->select(DB::raw(1))
                ->from('cart_items')
                ->join('laboratory_tests', function ($join) {
                    $join->whereRaw('laboratory_tests.id = cart_items.product_id');
                })
                ->whereColumn('cart_items.cart_id', 'carts.id')
                ->whereNotNull('laboratory_tests.brand');
        };
    }

    private function appointmentExistsSubquery(?callable $constraint = null): \Closure
    {
        return function (Builder $sub) use ($constraint) {
            $sub->select(DB::raw(1))
                ->from('laboratory_appointments as la')
                ->whereNull('la.deleted_at')
                ->where(function (Builder $match) {
                    if ($this->laboratoryAppointmentsHaveCartId()) {
                        $match
                            ->whereColumn('la.cart_id', 'carts.id')
                            ->orWhere(function (Builder $legacy) {
                                $legacy
                                    ->whereNull('la.cart_id')
                                    ->whereExists(function (Builder $legacySub) {
                                        $legacySub->selectRaw('1')
                                            ->from('customers as c')
                                            ->whereColumn('c.user_id', 'carts.user_id')
                                            ->whereColumn('la.customer_id', 'c.id');
                                    });
                            });

                        return;
                    }

                    $match->whereExists(function (Builder $legacySub) {
                        $legacySub->selectRaw('1')
                            ->from('customers as c')
                            ->whereColumn('c.user_id', 'carts.user_id')
                            ->whereColumn('la.customer_id', 'c.id');
                    });
                });

            if ($constraint) {
                $constraint($sub);
            }
        };
    }

    private function callbackExistsSubquery(): \Closure
    {
        return $this->appointmentCartSignalExistsSubquery(function (Builder $appointment) {
            $appointment->where(function (Builder $callback) {
                $callback->whereNotNull('la.callback_availability_starts_at')
                    ->orWhereNotNull('la.callback_availability_ends_at')
                    ->orWhere(function (Builder $comment) {
                        $comment->whereNotNull('la.patient_callback_comment')
                            ->where('la.patient_callback_comment', '!=', '');
                    });
            });
        });
    }

    private function phoneIntentExistsSubquery(): \Closure
    {
        return $this->appointmentCartSignalExistsSubquery(fn (Builder $appointment) => $appointment->whereNotNull('la.phone_call_intent_at'));
    }

    private function appointmentCartSignalExistsSubquery(callable $constraint): \Closure
    {
        return function (Builder $sub) use ($constraint) {
            $sub->select(DB::raw(1))
                ->from('laboratory_appointments as la')
                ->whereNull('la.deleted_at')
                ->where(function (Builder $match) {
                    if ($this->laboratoryAppointmentsHaveCartId()) {
                        $match
                            ->whereColumn('la.cart_id', 'carts.id')
                            ->orWhere(function (Builder $legacy) {
                                $legacy
                                    ->whereNull('la.cart_id')
                                    ->whereExists($this->legacyAppointmentCartMatchSubquery());
                            });

                        return;
                    }

                    $match->whereExists($this->legacyAppointmentCartMatchSubquery());
                });

            $constraint($sub);
        };
    }

    private function legacyAppointmentCartMatchSubquery(): \Closure
    {
        return function (Builder $sub) {
            $sub->selectRaw('1')
                ->from('customers as c')
                ->join('laboratory_cart_items as lci', 'lci.customer_id', '=', 'c.id')
                ->join('laboratory_tests as lt', 'lt.id', '=', 'lci.laboratory_test_id')
                ->whereColumn('c.user_id', 'carts.user_id')
                ->whereColumn('la.customer_id', 'c.id')
                ->where('lt.requires_appointment', true)
                ->whereColumn('la.brand', 'lt.brand')
                ->whereNull('lci.deleted_at');
        };
    }

    private function checkoutDraftExistsSubquery(): \Closure
    {
        return function (Builder $sub) {
            $sub->select(DB::raw(1))
                ->from('users as draft_users')
                ->join('customers as draft_customers', 'draft_customers.user_id', '=', 'draft_users.id')
                ->join('laboratory_checkout_drafts as drafts', 'drafts.customer_id', '=', 'draft_customers.id')
                ->whereColumn('draft_users.id', 'carts.user_id');
        };
    }

    private function paymentStatusExistsSubquery(array $statuses): \Closure
    {
        if (! $this->paymentAttemptsHaveCartId()) {
            return $this->legacyPaymentStatusExistsSubquery($statuses);
        }

        return function (Builder $sub) use ($statuses) {
            $sub->selectRaw('1')
                ->from('carts as payment_cart')
                ->whereColumn('payment_cart.id', 'carts.id')
                ->where(function (Builder $payment) use ($statuses) {
                    $payment
                        ->whereExists($this->explicitPaymentStatusExistsSubquery($statuses))
                        ->orWhere(function (Builder $legacy) use ($statuses) {
                            $legacy
                                ->whereNotExists($this->explicitPaymentAttemptExistsSubquery())
                                ->whereExists($this->legacyPaymentStatusExistsSubquery($statuses));
                        });
                });
        };
    }

    private function legacyPaymentStatusExistsSubquery(array $statuses): \Closure
    {
        return function (Builder $sub) use ($statuses) {
            $sub->selectRaw('1')
                ->from('customers as payment_customers')
                ->join('payment_attempts as pa', 'pa.customer_id', '=', 'payment_customers.id')
                ->whereColumn('payment_customers.user_id', 'carts.user_id')
                ->where('pa.gateway', 'efevoopay')
                ->when($this->paymentAttemptsHaveCartId(), fn (Builder $query) => $query->whereNull('pa.cart_id'))
                ->whereIn('pa.status', $statuses)
                ->whereRaw('ABS(pa.amount_cents - ROUND(carts.total * 100)) <= 100')
                ->whereRaw('pa.created_at >= '.$this->paymentAttemptWindowStartSql('carts'))
                ->whereRaw('pa.created_at <= '.$this->paymentAttemptWindowEndSql('carts'))
                ->whereRaw('pa.id = '.$this->latestPaymentAttemptIdSql('carts', 'payment_customers'))
                ->whereNotExists($this->ambiguousPaymentAttemptExistsSubquery('pa'));
        };
    }

    private function explicitPaymentStatusExistsSubquery(array $statuses): \Closure
    {
        return function (Builder $sub) use ($statuses) {
            $sub->selectRaw('1')
                ->from('payment_attempts as pa_explicit')
                ->whereColumn('pa_explicit.cart_id', 'carts.id')
                ->where('pa_explicit.gateway', 'efevoopay')
                ->whereIn('pa_explicit.status', $statuses)
                ->whereRaw('pa_explicit.id = '.$this->latestExplicitPaymentAttemptIdSql('carts'));
        };
    }

    private function explicitPaymentAttemptExistsSubquery(): \Closure
    {
        return function (Builder $sub) {
            $sub->selectRaw('1')
                ->from('payment_attempts as pa_any_explicit')
                ->whereColumn('pa_any_explicit.cart_id', 'carts.id')
                ->where('pa_any_explicit.gateway', 'efevoopay');
        };
    }

    private function paymentAttemptWindowStartSql(string $cartAlias): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return "datetime({$cartAlias}.created_at, '-5 minutes')";
        }

        if ($driver === 'pgsql') {
            return "{$cartAlias}.created_at - INTERVAL '5 minutes'";
        }

        return "DATE_SUB({$cartAlias}.created_at, INTERVAL 5 MINUTE)";
    }

    private function paymentAttemptWindowEndSql(string $cartAlias): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return "datetime(COALESCE({$cartAlias}.completed_at, {$cartAlias}.updated_at), '+2 hours')";
        }

        if ($driver === 'pgsql') {
            return "COALESCE({$cartAlias}.completed_at, {$cartAlias}.updated_at) + INTERVAL '2 hours'";
        }

        return "DATE_ADD(COALESCE({$cartAlias}.completed_at, {$cartAlias}.updated_at), INTERVAL 2 HOUR)";
    }

    private function latestPaymentAttemptIdSql(string $cartAlias, string $customerAlias): string
    {
        $explicitGuard = $this->paymentAttemptsHaveCartId() ? 'AND pa_latest.cart_id IS NULL' : '';

        return "(SELECT pa_latest.id
            FROM payment_attempts pa_latest
            WHERE pa_latest.customer_id = {$customerAlias}.id
                AND pa_latest.gateway = 'efevoopay'
                {$explicitGuard}
                AND ABS(pa_latest.amount_cents - ROUND({$cartAlias}.total * 100)) <= 100
                AND pa_latest.created_at >= ".$this->paymentAttemptWindowStartSql($cartAlias)."
                AND pa_latest.created_at <= ".$this->paymentAttemptWindowEndSql($cartAlias)."
            ORDER BY COALESCE(pa_latest.processed_at, pa_latest.updated_at, pa_latest.created_at) DESC, pa_latest.id DESC
            LIMIT 1)";
    }

    private function latestExplicitPaymentAttemptIdSql(string $cartAlias): string
    {
        return "(SELECT pa_latest.id
            FROM payment_attempts pa_latest
            WHERE pa_latest.cart_id = {$cartAlias}.id
                AND pa_latest.gateway = 'efevoopay'
            ORDER BY COALESCE(pa_latest.processed_at, pa_latest.updated_at, pa_latest.created_at) DESC, pa_latest.id DESC
            LIMIT 1)";
    }

    private function ambiguousPaymentAttemptExistsSubquery(string $paymentAttemptAlias): \Closure
    {
        return function (Builder $sub) use ($paymentAttemptAlias) {
            $sub->selectRaw('1')
                ->from('carts as competing_carts')
                ->join('customers as competing_customers', 'competing_customers.user_id', '=', 'competing_carts.user_id')
                ->whereColumn('competing_carts.id', '!=', 'carts.id')
                ->whereColumn('competing_customers.id', "{$paymentAttemptAlias}.customer_id")
                ->whereRaw("ABS({$paymentAttemptAlias}.amount_cents - ROUND(competing_carts.total * 100)) <= 100")
                ->whereRaw("{$paymentAttemptAlias}.created_at >= ".$this->paymentAttemptWindowStartSql('competing_carts'))
                ->whereRaw("{$paymentAttemptAlias}.created_at <= ".$this->paymentAttemptWindowEndSql('competing_carts'));
        };
    }

    private function staleBefore(): Carbon
    {
        return now()->subMinutes(Cart::ABANDONED_AFTER_MINUTES);
    }

    private function lastUserActivityAtSql(): string
    {
        return CartUserActivityResolver::lastActivityAtSql('carts');
    }

    private function storedCheckoutFlowExistsSubquery(string $flow): \Closure
    {
        return function (Builder $sub) use ($flow) {
            $sub->select(DB::raw(1))
                ->from('cart_events as cef')
                ->whereColumn('cef.cart_id', 'carts.id')
                ->where('cef.event', CartEventType::CheckoutFlowDetermined->value)
                ->where('cef.metadata->checkout_flow', $flow);
        };
    }

    private function checkoutDraftFieldExistsSubquery(string $field): \Closure
    {
        return function (Builder $sub) use ($field) {
            $sub->select(DB::raw(1))
                ->from('users as draft_users')
                ->join('customers as draft_customers', 'draft_customers.user_id', '=', 'draft_users.id')
                ->join('laboratory_checkout_drafts as drafts', 'drafts.customer_id', '=', 'draft_customers.id')
                ->whereColumn('draft_users.id', 'carts.user_id')
                ->whereNotNull("drafts.{$field}");
        };
    }

    private function paymentAttemptsHaveCartId(): bool
    {
        return Schema::hasColumn('payment_attempts', 'cart_id');
    }

    private function laboratoryAppointmentsHaveCartId(): bool
    {
        return Schema::hasColumn('laboratory_appointments', 'cart_id');
    }

    private function localDateExpression(string $column, string $driver): string
    {
        return match ($driver) {
            'sqlite' => "strftime('%Y-%m-%d', datetime({$column}, '-6 hours'))",
            default => "DATE(CONVERT_TZ({$column}, '+00:00', '-06:00'))",
        };
    }

    /**
     * @return list<string>
     */
    private function localDateKeys(Carbon $startLocal, Carbon $endLocal): array
    {
        return collect(CarbonPeriod::create($startLocal->copy()->startOfDay(), $endLocal->copy()->startOfDay()))
            ->map(fn (Carbon $day) => $day->toDateString())
            ->values()
            ->all();
    }

    private function brandLabel(string $brand): string
    {
        $enum = LaboratoryBrand::tryFrom($brand);

        return $enum?->label() ?? 'Sin identificar';
    }

    private function applyOperationalMonitoringScope(Builder $query): void
    {
        $query->where(function (Builder $inner) {
            $inner->where('carts.status', MonitoringCartStatus::Completed->value)
                ->orWhereExists(function (Builder $sub) {
                    $sub->selectRaw('1')
                        ->from('cart_items')
                        ->whereColumn('cart_items.cart_id', 'carts.id');
                });
        });
    }
}
