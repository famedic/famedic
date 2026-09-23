<?php

namespace App\Actions\Admin\Customers;

use App\Enums\CartEventType;
use App\Models\CartEvent;
use App\Models\Customer;
use App\Models\PaymentAttempt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BuildCustomerInteractionChartAction
{
    private const TZ = 'America/Monterrey';

    /**
     * @return array{
     *     chart: list<array{label: string, purchases: int, payments: int, carts: int, appointments: int, marketing: int, notifications: int, total: int}>,
     *     summary: array{purchases: int, payments: int, carts: int, appointments: int, marketing: int, notifications: int, total: int},
     *     recentActivity: list<array{category: string, category_label: string, label: string, at: string|null, detail: string|null}>
     * }
     */
    public function __invoke(Customer $customer, ?User $user, int $days = 30): array
    {
        $end = Carbon::now(self::TZ)->endOfDay();
        $start = $end->copy()->subDays(max(1, $days - 1))->startOfDay();

        $events = collect();

        $events = $events->merge($this->purchaseEvents($customer, $start, $end));
        $events = $events->merge($this->paymentEvents($customer, $start, $end));
        $events = $events->merge($this->appointmentEvents($customer, $start, $end));
        $events = $events->merge($this->marketingEvents($customer, $start, $end));
        $events = $events->merge($this->notificationEvents($customer, $user, $start, $end));

        if ($user) {
            $events = $events->merge($this->cartEvents($user, $start, $end));
        }

        $chart = $this->buildChartSeries($events, $start, $end);
        $summary = $this->buildSummary($events);
        $recentActivity = $events
            ->sortByDesc('sort')
            ->take(20)
            ->map(fn (array $event) => [
                'category' => $event['category'],
                'category_label' => $event['category_label'],
                'label' => $event['label'],
                'at' => $event['at'],
                'detail' => $event['detail'],
            ])
            ->values()
            ->all();

        return [
            'chart' => $chart,
            'summary' => $summary,
            'recentActivity' => $recentActivity,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function purchaseEvents(Customer $customer, Carbon $start, Carbon $end): Collection
    {
        $events = collect();

        foreach ($customer->laboratoryPurchases()->whereBetween('created_at', [$start, $end])->latest()->limit(50)->get() as $purchase) {
            $events->push($this->makeEvent(
                category: 'purchases',
                categoryLabel: 'Compras',
                label: 'Compra laboratorio',
                at: $purchase->created_at,
                detail: '#'.$purchase->id,
            ));
        }

        foreach ($customer->onlinePharmacyPurchases()->whereBetween('created_at', [$start, $end])->latest()->limit(50)->get() as $purchase) {
            $events->push($this->makeEvent(
                category: 'purchases',
                categoryLabel: 'Compras',
                label: 'Compra farmacia',
                at: $purchase->created_at,
                detail: '#'.$purchase->id,
            ));
        }

        foreach ($customer->medicalAttentionSubscriptions()->whereBetween('created_at', [$start, $end])->latest()->limit(50)->get() as $subscription) {
            $events->push($this->makeEvent(
                category: 'purchases',
                categoryLabel: 'Compras',
                label: 'Suscripción médica',
                at: $subscription->created_at,
                detail: '#'.$subscription->id,
            ));
        }

        return $events;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function paymentEvents(Customer $customer, Carbon $start, Carbon $end): Collection
    {
        return PaymentAttempt::query()
            ->where('customer_id', $customer->id)
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('processed_at', [$start, $end])
                    ->orWhere(function ($nested) use ($start, $end) {
                        $nested->whereNull('processed_at')
                            ->whereBetween('created_at', [$start, $end]);
                    });
            })
            ->latest('processed_at')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn ($attempt) => $this->makeEvent(
                category: 'payments',
                categoryLabel: 'Pagos',
                label: 'Intento de pago',
                at: $attempt->processed_at ?? $attempt->created_at,
                detail: ($attempt->status ?? 'N/D').' · $'.number_format(($attempt->amount_cents ?? 0) / 100, 2),
            ));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function appointmentEvents(Customer $customer, Carbon $start, Carbon $end): Collection
    {
        return $customer->laboratoryAppointments()
            ->whereBetween('created_at', [$start, $end])
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn ($appointment) => $this->makeEvent(
                category: 'appointments',
                categoryLabel: 'Citas',
                label: $appointment->laboratory_purchase_id ? 'Cita comprada' : 'Cita registrada',
                at: $appointment->created_at,
                detail: $appointment->patient_full_name ?? $appointment->brand?->value,
            ));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function marketingEvents(Customer $customer, Carbon $start, Carbon $end): Collection
    {
        return $customer->activeCampaignWebActivities()
            ->whereBetween('occurred_at', [$start, $end])
            ->latest('occurred_at')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn ($activity) => $this->makeEvent(
                category: 'marketing',
                categoryLabel: 'Marketing',
                label: $activity->label ?? $activity->title ?? 'Actividad web',
                at: $activity->occurred_at ?? $activity->created_at,
                detail: $activity->path,
            ));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function notificationEvents(Customer $customer, ?User $user, Carbon $start, Carbon $end): Collection
    {
        $query = \App\Models\LaboratoryNotification::query()
            ->whereBetween('created_at', [$start, $end])
            ->where(function ($nested) use ($customer, $user) {
                $nested->whereHas('laboratoryPurchase', fn ($purchase) => $purchase->where('customer_id', $customer->id));

                if ($user) {
                    $nested->orWhere('user_id', $user->id)
                        ->orWhere('email_recipient_id', $user->id);
                }
            })
            ->latest()
            ->limit(50);

        return $query->get()->map(fn ($notification) => $this->makeEvent(
            category: 'notifications',
            categoryLabel: 'Notificaciones',
            label: $notification->notification_type ?? 'Notificación lab.',
            at: $notification->created_at,
            detail: $notification->gda_order_id,
        ));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function cartEvents(User $user, Carbon $start, Carbon $end): Collection
    {
        return CartEvent::query()
            ->whereHas('cart', fn ($query) => $query->where('user_id', $user->id))
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('occurred_at', [$start, $end])
                    ->orWhere(function ($nested) use ($start, $end) {
                        $nested->whereNull('occurred_at')
                            ->whereBetween('created_at', [$start, $end]);
                    });
            })
            ->latest('occurred_at')
            ->latest()
            ->limit(50)
            ->get()
            ->map(function ($event) {
                $eventLabel = $event->event instanceof CartEventType
                    ? $this->cartEventLabel($event->event)
                    : (string) $event->event;

                return $this->makeEvent(
                    category: 'carts',
                    categoryLabel: 'Carritos',
                    label: $eventLabel ?: 'Evento de carrito',
                    at: $event->occurred_at ?? $event->created_at,
                    detail: isset($event->cart_id) ? 'Carrito #'.$event->cart_id : null,
                );
            });
    }

    /**
     * @return array{category: string, category_label: string, label: string, at: string|null, detail: string|null, sort: int, day: string}
     */
    private function makeEvent(
        string $category,
        string $categoryLabel,
        string $label,
        mixed $at,
        ?string $detail = null,
    ): array {
        $timestamp = $at instanceof Carbon
            ? $at->copy()->setTimezone(self::TZ)
            : ($at ? Carbon::parse($at)->setTimezone(self::TZ) : null);

        return [
            'category' => $category,
            'category_label' => $categoryLabel,
            'label' => $label,
            'at' => $timestamp?->isoFormat('D MMM Y h:mm a'),
            'detail' => $detail,
            'sort' => $timestamp?->timestamp ?? 0,
            'day' => $timestamp?->toDateString() ?? 'unknown',
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $events
     * @return list<array{label: string, purchases: int, payments: int, carts: int, appointments: int, marketing: int, notifications: int, total: int}>
     */
    private function buildChartSeries(Collection $events, Carbon $start, Carbon $end): array
    {
        $countsByDay = $events
            ->filter(fn (array $event) => $event['day'] !== 'unknown')
            ->groupBy('day')
            ->map(function (Collection $dayEvents) {
                return [
                    'purchases' => $dayEvents->where('category', 'purchases')->count(),
                    'payments' => $dayEvents->where('category', 'payments')->count(),
                    'carts' => $dayEvents->where('category', 'carts')->count(),
                    'appointments' => $dayEvents->where('category', 'appointments')->count(),
                    'marketing' => $dayEvents->where('category', 'marketing')->count(),
                    'notifications' => $dayEvents->where('category', 'notifications')->count(),
                ];
            });

        return collect($start->toPeriod($end, '1 day'))
            ->map(function (Carbon $date) use ($countsByDay, $start, $end) {
                $key = $date->toDateString();
                $counts = $countsByDay->get($key, [
                    'purchases' => 0,
                    'payments' => 0,
                    'carts' => 0,
                    'appointments' => 0,
                    'marketing' => 0,
                    'notifications' => 0,
                ]);

                $total = array_sum($counts);

                return [
                    'label' => $start->year !== $end->year
                        ? $date->isoFormat('D MMM YY')
                        : $date->isoFormat('D MMM'),
                    ...$counts,
                    'total' => $total,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $events
     * @return array{purchases: int, payments: int, carts: int, appointments: int, marketing: int, notifications: int, total: int}
     */
    private function buildSummary(Collection $events): array
    {
        $summary = [
            'purchases' => $events->where('category', 'purchases')->count(),
            'payments' => $events->where('category', 'payments')->count(),
            'carts' => $events->where('category', 'carts')->count(),
            'appointments' => $events->where('category', 'appointments')->count(),
            'marketing' => $events->where('category', 'marketing')->count(),
            'notifications' => $events->where('category', 'notifications')->count(),
        ];

        $summary['total'] = array_sum($summary);

        return $summary;
    }

    private function cartEventLabel(CartEventType $event): string
    {
        return match ($event) {
            CartEventType::CartCreated => 'Carrito creado',
            CartEventType::CartItemAdded => 'Producto agregado',
            CartEventType::CartItemRemoved => 'Producto removido',
            CartEventType::CartItemQuantityChanged => 'Cantidad actualizada',
            CartEventType::CartEmptied => 'Carrito vaciado',
            CartEventType::CartAbandoned => 'Carrito abandonado',
            CartEventType::CartResumed => 'Carrito retomado',
            CartEventType::CartRecovered => 'Carrito recuperado',
            CartEventType::CheckoutStarted => 'Checkout iniciado',
            CartEventType::CheckoutVisited => 'Checkout visitado',
            CartEventType::CheckoutFlowDetermined => 'Flujo de checkout',
            CartEventType::PatientSelected => 'Paciente seleccionado',
            CartEventType::AddressSelected => 'Dirección seleccionada',
            CartEventType::PaymentMethodSelected => 'Método de pago seleccionado',
            CartEventType::AppointmentRequested => 'Cita solicitada',
            CartEventType::AppointmentPending5m => 'Cita pendiente 5m',
            CartEventType::AppointmentConfirmed => 'Cita confirmada',
            CartEventType::CallRequested => 'Llamada solicitada',
            CartEventType::CallAttempted => 'Llamada intentada',
            CartEventType::PaymentStarted => 'Pago iniciado',
            CartEventType::PaymentDeclined => 'Pago rechazado',
            CartEventType::PaymentError => 'Error de pago',
            CartEventType::PaymentApproved => 'Pago aprobado',
            CartEventType::PurchaseCreated => 'Compra creada',
            CartEventType::CartCompleted => 'Carrito completado',
        };
    }
}
