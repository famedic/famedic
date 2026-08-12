<?php

namespace App\Services\UserPurchases;

use App\Actions\Laboratories\CalculateTotalsAndDiscountAction;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryCheckoutDraft;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class PendingPurchasesQuery
{
    public function __construct(
        private CalculateTotalsAndDiscountAction $calculateTotalsAndDiscount,
    ) {}

    /**
     * @return array{pendingPurchases: list<array<string, mixed>>, summary: array{total: int, carts: int, checkouts: int, items: int}}
     */
    public function forCustomer(Customer $customer): array
    {
        $items = $customer->laboratoryCartItems()
            ->with('laboratoryTest')
            ->get()
            ->filter(fn (LaboratoryCartItem $item) => $item->laboratoryTest?->brand instanceof LaboratoryBrand)
            ->values();

        $drafts = $customer->laboratoryCheckoutDrafts()
            ->get()
            ->keyBy(fn (LaboratoryCheckoutDraft $draft) => $draft->laboratory_brand->value);

        $appointments = $customer->laboratoryAppointments()
            ->whereNull('laboratory_purchase_id')
            ->get()
            ->groupBy(fn (LaboratoryAppointment $appointment) => $appointment->brand->value);

        $activeMonitoringCart = $this->activeLaboratoryMonitoringCart($customer);
        $brandGroups = $items->groupBy(fn (LaboratoryCartItem $item) => $item->laboratoryTest->brand->value);
        $includeMonitoringActivity = $brandGroups->count() === 1;

        $pendingPurchases = $brandGroups
            ->map(function (Collection $brandItems, string $brandValue) use ($drafts, $appointments, $activeMonitoringCart, $includeMonitoringActivity) {
                $brand = LaboratoryBrand::from($brandValue);
                $draft = $drafts->get($brandValue);
                $brandAppointments = $appointments->get($brandValue, collect());

                return $this->laboratoryPurchase(
                    $brand,
                    new EloquentCollection($brandItems->all()),
                    $draft,
                    $brandAppointments,
                    $includeMonitoringActivity ? $activeMonitoringCart : null,
                );
            })
            ->sortByDesc(fn (array $purchase) => $purchase['activity']['last_activity_at'] ?? '')
            ->values()
            ->all();

        return [
            'pendingPurchases' => $pendingPurchases,
            'summary' => $this->summary($pendingPurchases),
        ];
    }

    private function laboratoryPurchase(
        LaboratoryBrand $brand,
        EloquentCollection $items,
        ?LaboratoryCheckoutDraft $draft,
        Collection $appointments,
        ?Cart $activeMonitoringCart,
    ): array {
        $requiresAppointment = $items->contains(
            fn (LaboratoryCartItem $item) => (bool) $item->laboratoryTest?->requires_appointment,
        );
        $confirmedAppointment = $appointments
            ->filter(fn (LaboratoryAppointment $appointment) => $appointment->confirmed_at !== null)
            ->sortByDesc('updated_at')
            ->first();
        $pendingAppointment = $appointments
            ->filter(fn (LaboratoryAppointment $appointment) => $appointment->confirmed_at === null)
            ->sortByDesc('updated_at')
            ->first();
        $appointment = $confirmedAppointment ?? $pendingAppointment;

        $checkout = $this->checkout($draft?->checkout_step, $requiresAppointment);
        $status = $this->status($draft, $checkout['step'], $requiresAppointment, $confirmedAppointment);
        $pricing = $this->pricing($items);
        $activity = $this->activity($items, $draft, $appointment, $activeMonitoringCart);
        $urls = $this->urls($brand, $checkout['step'], $status);

        return [
            'key' => 'laboratory:'.$brand->value,
            'type' => 'laboratory',
            'brand' => [
                'value' => $brand->value,
                'label' => $brand->label(),
            ],
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'items_count' => $items->count(),
            'items' => $items
                ->map(fn (LaboratoryCartItem $item) => $this->item($item))
                ->values()
                ->all(),
            'requires_appointment' => $requiresAppointment,
            'pricing' => $pricing,
            'checkout' => $checkout,
            'activity' => $activity,
            'urls' => $urls,
        ];
    }

    private function checkout(?string $draftStep, bool $requiresAppointment): array
    {
        $steps = $requiresAppointment
            ? ['patient', 'address', 'payment', 'appointment', 'confirmation']
            : ['patient', 'address', 'payment', 'confirmation'];

        $step = in_array($draftStep, $steps, true) ? $draftStep : 'patient';
        $stepNumber = array_search($step, $steps, true) + 1;
        $totalSteps = count($steps);

        return [
            'step' => $step,
            'step_number' => $stepNumber,
            'step_name' => $this->stepName($step),
            'total_steps' => $totalSteps,
            'progress' => (int) round(($stepNumber / $totalSteps) * 100),
        ];
    }

    private function status(
        ?LaboratoryCheckoutDraft $draft,
        string $step,
        bool $requiresAppointment,
        ?LaboratoryAppointment $confirmedAppointment,
    ): string {
        if ($requiresAppointment && $confirmedAppointment !== null) {
            return 'payment_pending';
        }

        if ($step === 'confirmation') {
            return 'payment_pending';
        }

        if ($requiresAppointment && $step === 'appointment') {
            return 'appointment_pending';
        }

        if ($draft !== null && in_array($step, ['address', 'payment'], true)) {
            return 'checkout_in_progress';
        }

        return 'cart_saved';
    }

    private function pricing(EloquentCollection $items): array
    {
        $totals = ($this->calculateTotalsAndDiscount)($items);
        $subtotal = (int) $items->sum(fn (LaboratoryCartItem $item) => (int) ($item->laboratoryTest?->public_price_cents ?? 0));
        $total = (int) $totals['total'];
        $discount = max(0, $subtotal - $total);

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $total,
            'formatted_subtotal' => formattedCentsPrice($subtotal),
            'formatted_discount' => formattedCentsPrice($discount),
            'formatted_total' => formattedCentsPrice($total),
        ];
    }

    private function item(LaboratoryCartItem $item): array
    {
        $test = $item->laboratoryTest;

        return [
            'id' => $test->id,
            'name' => $test->name,
            'requires_appointment' => (bool) $test->requires_appointment,
            'public_price' => (int) $test->public_price_cents,
            'famedic_price' => (int) $test->famedic_price_cents,
            'formatted_public_price' => formattedCentsPrice((int) $test->public_price_cents),
            'formatted_famedic_price' => formattedCentsPrice((int) $test->famedic_price_cents),
        ];
    }

    private function activity(
        EloquentCollection $items,
        ?LaboratoryCheckoutDraft $draft,
        ?LaboratoryAppointment $appointment,
        ?Cart $activeMonitoringCart,
    ): array {
        $createdAt = collect([
            $items->min('created_at'),
            $draft?->created_at,
            $appointment?->created_at,
        ])->filter()->min();

        $updatedAt = collect([
            $items->max('updated_at'),
            $draft?->updated_at,
            $appointment?->updated_at,
            $activeMonitoringCart?->updated_at,
        ])->filter()->max();

        $lastActivityAt = $updatedAt;
        $abandonedAt = $lastActivityAt?->copy()->addMinutes(Cart::ABANDONED_AFTER_MINUTES);
        $isAbandoned = $abandonedAt !== null && $abandonedAt->lte(now());

        return [
            'created_at' => $this->iso($createdAt),
            'updated_at' => $this->iso($updatedAt),
            'last_activity_at' => $this->iso($lastActivityAt),
            'is_abandoned' => $isAbandoned,
            'abandoned_at' => $isAbandoned ? $this->iso($abandonedAt) : null,
        ];
    }

    private function urls(LaboratoryBrand $brand, string $step, string $status): array
    {
        $cartUrl = route('laboratory.shopping-cart', [
            'laboratory_brand' => $brand,
        ]);
        $checkoutUrl = route('laboratory.checkout', [
            'laboratory_brand' => $brand,
            'step' => $step,
        ]);

        return [
            'cart' => $cartUrl,
            'checkout' => $checkoutUrl,
            'continue' => $status === 'cart_saved' ? $cartUrl : $checkoutUrl,
        ];
    }

    private function summary(array $pendingPurchases): array
    {
        $rows = collect($pendingPurchases);

        return [
            'total' => $rows->count(),
            'carts' => $rows->where('status', 'cart_saved')->count(),
            'checkouts' => $rows->where('status', '!=', 'cart_saved')->count(),
            'items' => (int) $rows->sum('items_count'),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'checkout_in_progress' => 'Checkout en progreso',
            'appointment_pending' => 'Cita pendiente',
            'payment_pending' => 'Pendiente de pago',
            default => 'Carrito guardado',
        };
    }

    private function stepName(string $step): string
    {
        return match ($step) {
            'address' => 'Dirección',
            'payment' => 'Método de pago',
            'appointment' => 'Cita',
            'confirmation' => 'Confirmación',
            default => 'Paciente',
        };
    }

    private function activeLaboratoryMonitoringCart(Customer $customer): ?Cart
    {
        if (! $customer->user_id) {
            return null;
        }

        return Cart::query()
            ->where('user_id', $customer->user_id)
            ->where('type', MonitoringCartType::Lab)
            ->where('status', MonitoringCartStatus::Active)
            ->first();
    }

    private function iso(mixed $date): ?string
    {
        if (! $date instanceof CarbonInterface) {
            return null;
        }

        return $date->toIso8601String();
    }
}
