<?php

namespace App\Console\Commands;

use App\Enums\CartEventType;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\CartEvent;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryPurchase;
use App\Models\PaymentAttempt;
use App\Services\Carts\CartPaymentAttemptCorrelator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AuditCartTraceabilityCommand extends Command
{
    protected $signature = 'carts:traceability-audit
                            {--days=7 : Dias hacia atras a inspeccionar}
                            {--from= : Fecha inicial local YYYY-MM-DD}
                            {--to= : Fecha final local YYYY-MM-DD}
                            {--strict : Regresa codigo distinto de cero si hay inconsistencias criticas}';

    protected $description = 'Audita cobertura e integridad de trazabilidad explicita de carritos (solo lectura)';

    private Carbon $start;

    private Carbon $end;

    private string $timezone = 'America/Monterrey';

    private int $criticalIssues = 0;

    public function handle(CartPaymentAttemptCorrelator $correlator): int
    {
        [$this->start, $this->end] = $this->resolvePeriod();

        $this->info('TRACEABILITY AUDIT');
        $this->line('Period: '.$this->start->copy()->timezone($this->timezone)->toDateString().' -> '.$this->end->copy()->timezone($this->timezone)->toDateString());
        $this->newLine();

        $this->printMainMetrics();
        $this->printEventCoverage();
        $this->printMissingLinkDiagnostics();
        $this->printConsistencyDiagnostics();
        $this->printPaymentCorrelationAdoption($correlator);
        $this->printPerformanceNotes();

        if ($this->option('strict') && $this->criticalIssues > 0) {
            $this->error("Strict mode: {$this->criticalIssues} inconsistencias criticas detectadas.");

            return self::FAILURE;
        }

        $this->comment('Modo auditoria: solo lectura, sin backfill ni modificaciones.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolvePeriod(): array
    {
        $from = $this->option('from');
        $to = $this->option('to');

        if (filled($from) || filled($to)) {
            $start = filled($from)
                ? Carbon::parse((string) $from, $this->timezone)->startOfDay()
                : now($this->timezone)->subDays(max(1, (int) $this->option('days')))->startOfDay();
            $end = filled($to)
                ? Carbon::parse((string) $to, $this->timezone)->endOfDay()
                : now($this->timezone)->endOfDay();

            return [$start->utc(), $end->utc()];
        }

        $days = max(1, (int) $this->option('days'));

        return [
            now($this->timezone)->subDays($days)->startOfDay()->utc(),
            now($this->timezone)->endOfDay()->utc(),
        ];
    }

    private function printMainMetrics(): void
    {
        $cartsCreated = Cart::query()->whereBetween('created_at', [$this->start, $this->end])->count();
        $paymentTotal = PaymentAttempt::query()->whereBetween('created_at', [$this->start, $this->end])->count();
        $paymentExplicit = PaymentAttempt::query()->whereBetween('created_at', [$this->start, $this->end])->whereNotNull('cart_id')->count();
        $purchaseTotal = LaboratoryPurchase::query()->whereBetween('created_at', [$this->start, $this->end])->count();
        $purchaseExplicit = LaboratoryPurchase::query()->whereBetween('created_at', [$this->start, $this->end])->whereNotNull('cart_id')->count();
        $appointmentTotal = LaboratoryAppointment::query()->whereBetween('created_at', [$this->start, $this->end])->count();
        $appointmentExplicit = LaboratoryAppointment::query()->whereBetween('created_at', [$this->start, $this->end])->whereNotNull('cart_id')->count();
        $eventsTotal = CartEvent::query()->whereBetween('occurred_at', [$this->start, $this->end])->count();
        $cartsWithEvents = CartEvent::query()
            ->whereBetween('occurred_at', [$this->start, $this->end])
            ->whereNotNull('cart_id')
            ->distinct('cart_id')
            ->count('cart_id');

        $this->line('Carts created:                         '.$this->formatInt($cartsCreated));
        $this->newLine();
        $this->line('PaymentAttempts:');
        $this->line('  Total new attempts:                 '.$this->formatInt($paymentTotal));
        $this->line('  Explicit cart_id:                   '.$this->formatMetric($paymentExplicit, $paymentTotal));
        $this->line('  Without cart_id:                    '.$this->formatMetric($paymentTotal - $paymentExplicit, $paymentTotal));
        $this->newLine();
        $this->line('Laboratory Purchases:');
        $this->line('  Total:                              '.$this->formatInt($purchaseTotal));
        $this->line('  Explicit cart_id:                   '.$this->formatMetric($purchaseExplicit, $purchaseTotal));
        $this->line('  Without cart_id:                    '.$this->formatMetric($purchaseTotal - $purchaseExplicit, $purchaseTotal));
        $this->newLine();
        $this->line('Appointments:');
        $this->line('  Total:                              '.$this->formatInt($appointmentTotal));
        $this->line('  Explicit cart_id:                   '.$this->formatMetric($appointmentExplicit, $appointmentTotal));
        $this->line('  Without cart_id:                    '.$this->formatMetric($appointmentTotal - $appointmentExplicit, $appointmentTotal));
        $this->newLine();
        $this->line('Cart Events:');
        $this->line('  Total events:                       '.$this->formatInt($eventsTotal));
        $this->line('  Carts with events:                  '.$this->formatInt($cartsWithEvents));
        $this->newLine();
    }

    private function printEventCoverage(): void
    {
        $counts = CartEvent::query()
            ->whereBetween('occurred_at', [$this->start, $this->end])
            ->select('event', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('event')
            ->pluck('aggregate', 'event');

        $this->info('Event coverage:');
        foreach (CartEventType::cases() as $event) {
            $this->line(sprintf('  %-30s %s', $event->value, $this->formatInt((int) ($counts[$event->value] ?? 0))));
        }
        $this->newLine();
    }

    private function printMissingLinkDiagnostics(): void
    {
        $this->info('Missing explicit links:');

        $paymentRows = PaymentAttempt::query()
            ->whereBetween('created_at', [$this->start, $this->end])
            ->whereNull('cart_id')
            ->latest()
            ->limit(25)
            ->get(['id', 'customer_id', 'amount_cents', 'gateway', 'status', 'created_at']);
        $this->printDiagnosticRows('PaymentAttempts without cart_id', $paymentRows, fn (PaymentAttempt $attempt) => [
            $attempt->id,
            $attempt->customer_id,
            $this->formatCents((int) $attempt->amount_cents),
            $attempt->gateway,
            $attempt->status,
            $this->localDateTime($attempt->created_at),
            $this->classifyPaymentAttempt($attempt),
        ], ['id', 'customer_id', 'amount', 'gateway', 'status', 'created_at', 'classification']);

        $purchaseRows = LaboratoryPurchase::query()
            ->whereBetween('created_at', [$this->start, $this->end])
            ->whereNull('cart_id')
            ->latest()
            ->limit(25)
            ->get(['id', 'customer_id', 'brand', 'total_cents', 'created_at']);
        $this->printDiagnosticRows('LaboratoryPurchases without cart_id', $purchaseRows, fn (LaboratoryPurchase $purchase) => [
            $purchase->id,
            $purchase->customer_id,
            $purchase->brand?->value ?? (string) $purchase->brand,
            $this->formatCents((int) $purchase->total_cents),
            $this->localDateTime($purchase->created_at),
            $this->classifyPurchase($purchase),
        ], ['id', 'customer_id', 'brand', 'amount', 'created_at', 'classification']);

        $appointmentRows = LaboratoryAppointment::query()
            ->whereBetween('created_at', [$this->start, $this->end])
            ->whereNull('cart_id')
            ->latest()
            ->limit(25)
            ->get(['id', 'customer_id', 'brand', 'confirmed_at', 'created_at']);
        $this->printDiagnosticRows('LaboratoryAppointments without cart_id', $appointmentRows, fn (LaboratoryAppointment $appointment) => [
            $appointment->id,
            $appointment->customer_id,
            $appointment->brand?->value ?? (string) $appointment->brand,
            $appointment->confirmed_at ? 'yes' : 'no',
            $this->localDateTime($appointment->created_at),
            $this->classifyAppointment($appointment),
        ], ['id', 'customer_id', 'brand', 'confirmed', 'created_at', 'classification']);
    }

    private function printConsistencyDiagnostics(): void
    {
        $this->info('Consistency diagnostics:');

        $approvedWithoutPurchase = PaymentAttempt::query()
            ->whereBetween('created_at', [$this->start, $this->end])
            ->whereNotNull('cart_id')
            ->where('status', PaymentAttempt::STATUS_APPROVED)
            ->whereDoesntHave('cart.laboratoryPurchases')
            ->whereDoesntHave('cart.events', fn ($query) => $query->where('event', CartEventType::PurchaseCreated->value))
            ->limit(25)
            ->get(['id', 'cart_id', 'customer_id', 'amount_cents', 'created_at']);
        $this->printSimpleRows('Approved payments without purchase', $approvedWithoutPurchase, fn (PaymentAttempt $attempt) => [
            $attempt->id,
            $attempt->cart_id,
            $attempt->customer_id,
            $this->formatCents((int) $attempt->amount_cents),
            $this->localDateTime($attempt->created_at),
        ], ['payment_attempt_id', 'cart_id', 'customer_id', 'amount', 'created_at'], critical: true);

        $purchaseWithoutCompleted = LaboratoryPurchase::query()
            ->with('cart')
            ->whereBetween('created_at', [$this->start, $this->end])
            ->whereNotNull('cart_id')
            ->where(function ($query) {
                $query->whereDoesntHave('cart.events', fn ($events) => $events->where('event', CartEventType::CartCompleted->value))
                    ->orWhereHas('cart', fn ($cart) => $cart->where('status', '!=', MonitoringCartStatus::Completed->value));
            })
            ->limit(25)
            ->get(['id', 'cart_id', 'customer_id', 'total_cents', 'created_at']);
        $this->printSimpleRows('Purchases without cart_completed', $purchaseWithoutCompleted, fn (LaboratoryPurchase $purchase) => [
            $purchase->id,
            $purchase->cart_id,
            $purchase->cart?->status?->value ?? (string) $purchase->cart?->status,
            $this->formatCents((int) $purchase->total_cents),
            $this->localDateTime($purchase->created_at),
        ], ['purchase_id', 'cart_id', 'cart_status', 'amount', 'created_at'], critical: true);

        $completedWithoutPurchase = Cart::query()
            ->with(['user.customer', 'laboratoryPurchases'])
            ->whereBetween('completed_at', [$this->start, $this->end])
            ->where('type', MonitoringCartType::Lab->value)
            ->where('status', MonitoringCartStatus::Completed->value)
            ->whereDoesntHave('laboratoryPurchases')
            ->limit(50)
            ->get()
            ->filter(fn (Cart $cart) => $cart->relatedLaboratoryPurchase() === null)
            ->take(25);
        $this->printSimpleRows('Completed lab carts without purchase', $completedWithoutPurchase, fn (Cart $cart) => [
            $cart->id,
            $cart->user_id,
            $this->formatMoney((float) $cart->total),
            $this->localDateTime($cart->completed_at),
        ], ['cart_id', 'user_id', 'total', 'completed_at'], critical: true);

        $confirmedWithoutEvent = LaboratoryAppointment::query()
            ->whereBetween('confirmed_at', [$this->start, $this->end])
            ->whereNotNull('cart_id')
            ->whereDoesntHave('cart.events', fn ($query) => $query->where('event', CartEventType::AppointmentConfirmed->value))
            ->limit(25)
            ->get(['id', 'cart_id', 'customer_id', 'brand', 'confirmed_at']);
        $this->printSimpleRows('Confirmed appointments without event', $confirmedWithoutEvent, fn (LaboratoryAppointment $appointment) => [
            $appointment->id,
            $appointment->cart_id,
            $appointment->customer_id,
            $appointment->brand?->value ?? (string) $appointment->brand,
            $this->localDateTime($appointment->confirmed_at),
        ], ['appointment_id', 'cart_id', 'customer_id', 'brand', 'confirmed_at']);

        $this->printDuplicateEvents();
        $this->printSequenceIssues();
    }

    private function printDuplicateEvents(): void
    {
        $events = CartEvent::query()
            ->whereBetween('occurred_at', [$this->start, $this->end])
            ->whereNotNull('cart_id')
            ->get(['id', 'cart_id', 'event', 'metadata', 'idempotency_key', 'occurred_at']);

        $duplicates = $events
            ->groupBy(fn (CartEvent $event) => $event->cart_id.'|'.$event->event->value.'|'.$this->eventBusinessKey($event))
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->map(fn (Collection $group) => [
                $group->pluck('id')->implode(','),
                $group->first()->cart_id,
                $group->first()->event->value,
                $this->eventBusinessKey($group->first()),
                $group->count(),
            ])
            ->values()
            ->take(25);

        $this->printArrayRows('Suspicious duplicate events', $duplicates, ['event_ids', 'cart_id', 'event', 'business_key', 'count'], critical: true);
    }

    private function printSequenceIssues(): void
    {
        $events = CartEvent::query()
            ->whereBetween('occurred_at', [$this->start, $this->end])
            ->whereNotNull('cart_id')
            ->orderBy('occurred_at')
            ->get(['id', 'cart_id', 'event', 'occurred_at'])
            ->groupBy('cart_id');

        $rows = collect();
        foreach ($events as $cartId => $cartEvents) {
            $first = $cartEvents->groupBy(fn (CartEvent $event) => $event->event->value)
                ->map(fn (Collection $group) => $group->sortBy('occurred_at')->first());

            if (($first[CartEventType::PaymentApproved->value] ?? null) && ($first[CartEventType::PaymentStarted->value] ?? null)) {
                if ($first[CartEventType::PaymentApproved->value]->occurred_at->lt($first[CartEventType::PaymentStarted->value]->occurred_at)) {
                    $rows->push([$cartId, 'payment_approved_before_payment_started']);
                }
            }

            if (($first[CartEventType::CartCompleted->value] ?? null) && ($first[CartEventType::PurchaseCreated->value] ?? null)) {
                if ($first[CartEventType::CartCompleted->value]->occurred_at->lt($first[CartEventType::PurchaseCreated->value]->occurred_at)) {
                    $rows->push([$cartId, 'cart_completed_before_purchase_created']);
                }
            }
        }

        $this->printArrayRows('Sequence issues', $rows->take(25), ['cart_id', 'issue'], critical: true);
    }

    private function printPaymentCorrelationAdoption(CartPaymentAttemptCorrelator $correlator): void
    {
        $carts = Cart::query()
            ->with('user.customer')
            ->whereBetween('created_at', [$this->start, $this->end])
            ->where('type', MonitoringCartType::Lab->value)
            ->limit(1000)
            ->get();

        $insights = $correlator->forCarts($carts);
        $counts = collect($insights)->countBy(fn (array $insight) => $insight['confidence'] ?? 'none');

        $this->info('Payment correlations:');
        $this->line('  Explicit:      '.$this->formatInt((int) ($counts['explicit'] ?? 0)));
        $this->line('  Legacy high:   '.$this->formatInt((int) ($counts['legacy_high'] ?? 0)));
        $this->line('  Ambiguous:     '.$this->formatInt((int) ($counts['ambiguous'] ?? 0)));
        $this->line('  Carts sampled: '.$this->formatInt($carts->count()).($carts->count() === 1000 ? ' (limit 1000)' : ''));
        $this->newLine();
    }

    private function printPerformanceNotes(): void
    {
        $this->info('Performance notes:');
        $this->line('  Audit cost: O(registros del periodo). Las muestras de detalle estan limitadas a 25 filas por diagnostico.');
        $this->line('  Indices usados: created_at, cart_id, cart_id+created_at, cart_id+occurred_at, event+occurred_at.');
        $this->line('  Dashboard: sin refactor en esta fase; medir DB::listen/Debugbar en QA antes de optimizar las queries repetidas.');
        $this->newLine();
    }

    /**
     * @template T
     * @param  EloquentCollection<int, T>|Collection<int, T>  $rows
     */
    private function printDiagnosticRows(string $title, $rows, callable $map, array $headers): void
    {
        $this->printSimpleRows($title, $rows, $map, $headers, critical: false);
    }

    /**
     * @template T
     * @param  EloquentCollection<int, T>|Collection<int, T>  $rows
     */
    private function printSimpleRows(string $title, $rows, callable $map, array $headers, bool $critical = false): void
    {
        $count = $rows->count();
        if ($critical) {
            $this->criticalIssues += $count;
        }

        $this->line("  {$title}: ".$this->formatInt($count));
        if ($count > 0) {
            $this->table($headers, $rows->map($map)->values()->all());
        }
    }

    private function printArrayRows(string $title, Collection $rows, array $headers, bool $critical = false): void
    {
        $count = $rows->count();
        if ($critical) {
            $this->criticalIssues += $count;
        }

        $this->line("  {$title}: ".$this->formatInt($count));
        if ($count > 0) {
            $this->table($headers, $rows->values()->all());
        }
    }

    private function classifyPaymentAttempt(PaymentAttempt $attempt): string
    {
        return $this->hasCompatibleCartForCustomer(
            (int) $attempt->customer_id,
            (int) $attempt->amount_cents,
            $attempt->created_at,
        ) ? 'sospechoso' : 'esperado';
    }

    private function classifyPurchase(LaboratoryPurchase $purchase): string
    {
        return $this->hasCompatibleCartForCustomer(
            (int) $purchase->customer_id,
            (int) $purchase->total_cents,
            $purchase->created_at,
            $purchase->brand?->value ?? (string) $purchase->brand,
        ) ? 'sospechoso' : 'esperado';
    }

    private function classifyAppointment(LaboratoryAppointment $appointment): string
    {
        return $this->hasCompatibleCartForCustomer(
            (int) $appointment->customer_id,
            null,
            $appointment->created_at,
            $appointment->brand?->value ?? (string) $appointment->brand,
        ) ? 'sospechoso' : 'esperado';
    }

    private function hasCompatibleCartForCustomer(int $customerId, ?int $amountCents, ?Carbon $createdAt, ?string $brand = null): bool
    {
        if (! $createdAt) {
            return false;
        }

        $query = Cart::query()
            ->join('customers', 'customers.user_id', '=', 'carts.user_id')
            ->where('customers.id', $customerId)
            ->where('carts.type', MonitoringCartType::Lab->value)
            ->where('carts.created_at', '<=', $createdAt->copy()->addMinutes(5))
            ->where(function ($window) use ($createdAt) {
                $window->where('carts.updated_at', '>=', $createdAt->copy()->subHours(2))
                    ->orWhere('carts.completed_at', '>=', $createdAt->copy()->subHours(2));
            });

        if ($amountCents !== null) {
            $query->whereRaw('ABS(? - ROUND(carts.total * 100)) <= 100', [$amountCents]);
        }

        if ($brand !== null && $brand !== '') {
            $query->whereExists(function ($sub) use ($brand) {
                $sub->selectRaw('1')
                    ->from('cart_items')
                    ->join('laboratory_tests', function ($join) {
                        $join->whereRaw('laboratory_tests.id = cart_items.product_id');
                    })
                    ->whereColumn('cart_items.cart_id', 'carts.id')
                    ->where('laboratory_tests.brand', $brand);
            });
        }

        return $query->exists();
    }

    private function eventBusinessKey(CartEvent $event): string
    {
        $metadata = $event->metadata ?? [];

        foreach (['laboratory_purchase_id', 'payment_attempt_id', 'laboratory_appointment_id', 'contact_id', 'address_id'] as $key) {
            if (isset($metadata[$key])) {
                return "{$key}:{$metadata[$key]}";
            }
        }

        return (string) ($event->idempotency_key ?? 'no-key');
    }

    private function formatMetric(int $value, int $total): string
    {
        $percent = $total > 0 ? round(100 * $value / $total, 1) : 0.0;

        return sprintf('%s (%s%%)', $this->formatInt($value), number_format($percent, 1));
    }

    private function formatInt(int $value): string
    {
        return number_format($value);
    }

    private function formatCents(int $cents): string
    {
        return $this->formatMoney($cents / 100);
    }

    private function formatMoney(float $amount): string
    {
        return '$'.number_format($amount, 2);
    }

    private function localDateTime(?Carbon $date): ?string
    {
        return $date?->copy()->timezone($this->timezone)->format('Y-m-d H:i');
    }
}
