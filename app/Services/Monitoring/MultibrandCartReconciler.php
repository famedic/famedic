<?php

namespace App\Services\Monitoring;

use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\LaboratoryCartItem;
use App\Services\Carts\CartEventRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class MultibrandCartReconciler
{
    public function __construct(
        private CartEventRecorder $cartEventRecorder,
    ) {}

    /**
     * @return Collection<int, Cart>
     */
    public function candidates(?int $cartId = null, ?int $customerId = null, ?int $limit = null): Collection
    {
        $query = Cart::query()
            ->with(['items.laboratoryTest', 'paymentAttempts', 'laboratoryAppointments', 'laboratoryPurchases', 'events', 'user.customer.laboratoryCartItems.laboratoryTest'])
            ->where('type', MonitoringCartType::Lab)
            ->where('status', MonitoringCartStatus::Active)
            ->whereHas('items.laboratoryTest')
            ->when($cartId, fn (Builder $q) => $q->whereKey($cartId))
            ->when($customerId, fn (Builder $q) => $q->whereHas('user.customer', fn (Builder $customer) => $customer->whereKey($customerId)))
            ->orderBy('id');

        $carts = $query
            ->get()
            ->filter(fn (Cart $cart) => $cartId !== null || $this->cartBrands($cart)->count() > 1)
            ->values();

        return $limit !== null && $limit > 0 ? $carts->take($limit)->values() : $carts;
    }

    /**
     * @return Collection<int, Cart>
     */
    public function completedMultibrand(?int $cartId = null, ?int $customerId = null, ?int $limit = null): Collection
    {
        $query = Cart::query()
            ->with(['items.laboratoryTest', 'user.customer'])
            ->where('type', MonitoringCartType::Lab)
            ->where('status', MonitoringCartStatus::Completed)
            ->whereHas('items.laboratoryTest')
            ->when($cartId, fn (Builder $q) => $q->whereKey($cartId))
            ->when($customerId, fn (Builder $q) => $q->whereHas('user.customer', fn (Builder $customer) => $customer->whereKey($customerId)))
            ->orderBy('id');

        $carts = $query
            ->get()
            ->filter(fn (Cart $cart) => $this->cartBrands($cart)->count() > 1)
            ->values();

        return $limit !== null && $limit > 0 ? $carts->take($limit)->values() : $carts;
    }

    /**
     * @return array<string, mixed>
     */
    public function plan(Cart $cart): array
    {
        $cart->loadMissing(['items.laboratoryTest', 'paymentAttempts', 'laboratoryAppointments', 'laboratoryPurchases', 'events', 'user.customer.laboratoryCartItems.laboratoryTest']);

        $brands = $this->cartBrands($cart);
        $itemsByBrand = $this->currentItemsByBrand($cart);
        if ($brands->count() <= 1) {
            return [
                'cart_id' => $cart->id,
                'customer_id' => $cart->user?->customer?->id,
                'user_id' => $cart->user_id,
                'status' => $cart->status?->value ?? (string) $cart->status,
                'type' => $cart->type?->value ?? (string) $cart->type,
                'total' => (float) $cart->total,
                'brands' => $brands->all(),
                'items_by_brand' => $this->itemsSummaryByBrand($cart),
                'source_items_by_brand' => $this->sourceItemsSummaryByBrand($cart),
                'explicit_relations' => $this->explicitRelationSummary($cart, $itemsByBrand),
                'brand_to_keep' => null,
                'target_carts' => [],
                'risks' => [],
                'expected_result' => 'NO_CHANGES',
            ];
        }

        $explicitBrands = $this->explicitRelationBrands($cart, $itemsByBrand);
        $conflict = $explicitBrands === null || $explicitBrands->count() > 1;
        $brandToKeep = $conflict ? null : $this->brandThatKeepsLegacyCartId($cart, $itemsByBrand, $explicitBrands);
        $targetCarts = $brandToKeep
            ? $brands->mapWithKeys(fn (string $brand) => [$brand => $this->targetCartForBrand($cart, $brand, $brandToKeep)?->id])
            : collect();

        return [
            'cart_id' => $cart->id,
            'customer_id' => $cart->user?->customer?->id,
            'user_id' => $cart->user_id,
            'status' => $cart->status?->value ?? (string) $cart->status,
            'type' => $cart->type?->value ?? (string) $cart->type,
            'total' => (float) $cart->total,
            'brands' => $brands->all(),
            'items_by_brand' => $this->itemsSummaryByBrand($cart),
            'source_items_by_brand' => $this->sourceItemsSummaryByBrand($cart),
            'explicit_relations' => $this->explicitRelationSummary($cart, $itemsByBrand),
            'brand_to_keep' => $brandToKeep,
            'target_carts' => $targetCarts->all(),
            'risks' => $conflict ? ['Relaciones explicitas contradictorias o ambiguas'] : [],
            'expected_result' => $conflict ? 'SKIPPED_CONFLICT' : 'RECONCILED',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(Cart $cart): array
    {
        $before = $this->plan($cart);

        if (($before['expected_result'] ?? null) === 'SKIPPED_CONFLICT') {
            $this->logResult($before, 'skipped_conflict', []);

            return array_merge($before, ['result' => 'skipped_conflict']);
        }

        if (count($before['brands'] ?? []) <= 1) {
            $this->logResult($before, 'no_changes', []);

            return array_merge($before, ['result' => 'no_changes']);
        }

        try {
            DB::transaction(function () use ($cart) {
                $lockedCart = Cart::query()
                    ->whereKey($cart->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedCart->load(['items.laboratoryTest', 'paymentAttempts', 'laboratoryAppointments', 'laboratoryPurchases', 'events', 'user.customer.laboratoryCartItems.laboratoryTest']);

                $itemsByBrand = $this->currentItemsByBrand($lockedCart);
                $brands = $this->cartBrands($lockedCart);
                $explicitBrands = $this->explicitRelationBrands($lockedCart, $itemsByBrand);

                if ($explicitBrands === null || $explicitBrands->count() > 1) {
                    throw new MultibrandCartConflictException('Explicit relations are mixed or ambiguous.');
                }

                $brandToKeep = $this->brandThatKeepsLegacyCartId($lockedCart, $itemsByBrand, $explicitBrands);

                if ($brandToKeep === null) {
                    throw new MultibrandCartConflictException('No safe brand could keep the legacy cart id.');
                }

                foreach ($brands as $brandValue) {
                    $brandItems = $itemsByBrand->get($brandValue, collect());
                    if ($brandItems->isEmpty()) {
                        continue;
                    }

                    $targetCart = $brandValue === $brandToKeep
                        ? $lockedCart
                        : $this->singleBrandActiveCart((int) $lockedCart->user_id, $brandValue, (int) $lockedCart->id)
                            ?? $this->createActiveCart((int) $lockedCart->user_id);

                    $this->replaceLaboratorySnapshot($targetCart, $brandItems);
                }
            });
        } catch (MultibrandCartConflictException) {
            $this->logResult($before, 'skipped_conflict', []);

            return array_merge($before, ['result' => 'skipped_conflict']);
        } catch (Throwable $throwable) {
            $this->logResult($before, 'error', [], $throwable->getMessage());

            return array_merge($before, [
                'result' => 'error',
                'error' => $throwable->getMessage(),
            ]);
        }

        $afterCarts = Cart::query()
            ->with('items.laboratoryTest')
            ->where('user_id', $cart->user_id)
            ->where('type', MonitoringCartType::Lab)
            ->where('status', MonitoringCartStatus::Active)
            ->orderBy('id')
            ->get()
            ->map(fn (Cart $row) => [
                'cart_id' => $row->id,
                'brands' => $this->cartBrands($row)->all(),
                'total' => (float) $row->total,
            ])
            ->all();

        $this->logResult($before, 'reconciled', $afterCarts);

        return array_merge($before, [
            'result' => 'reconciled',
            'after_carts' => $afterCarts,
        ]);
    }

    /**
     * @param  Collection<string, Collection<int, LaboratoryCartItem>>  $itemsByBrand
     */
    public function reconcileActiveCartsForUser(int $userId, Collection $itemsByBrand): bool
    {
        $activeCarts = Cart::query()
            ->with(['items.laboratoryTest', 'paymentAttempts', 'laboratoryAppointments', 'laboratoryPurchases', 'events', 'user.customer.laboratoryCartItems.laboratoryTest'])
            ->where('user_id', $userId)
            ->where('type', MonitoringCartType::Lab)
            ->where('status', MonitoringCartStatus::Active)
            ->get();

        foreach ($activeCarts as $cart) {
            if ($this->cartBrands($cart)->count() <= 1) {
                continue;
            }

            $result = $this->apply($cart);
            if (($result['result'] ?? null) === 'skipped_conflict') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return Collection<int, string>
     */
    private function cartBrands(Cart $cart): Collection
    {
        return collect($cart->labBrands())->pluck('value')->filter()->unique()->values();
    }

    /**
     * @return Collection<string, Collection<int, LaboratoryCartItem>>
     */
    private function currentItemsByBrand(Cart $cart): Collection
    {
        $customer = $cart->user?->customer;
        $items = $customer?->relationLoaded('laboratoryCartItems')
            ? $customer->laboratoryCartItems
            : ($customer?->laboratoryCartItems()->with('laboratoryTest')->get() ?? collect());

        return $items->groupBy(
            fn (LaboratoryCartItem $row) => $row->laboratoryTest?->brand?->value ?? '__unknown',
        );
    }

    private function brandThatKeepsLegacyCartId(Cart $cart, Collection $itemsByBrand, Collection $explicitBrands): ?string
    {
        if ($explicitBrands->count() === 1) {
            return $explicitBrands->first();
        }

        return $itemsByBrand
            ->map(function (Collection $items, string $brandValue) {
                return [
                    'brand' => $brandValue,
                    'count' => $items->count(),
                    'total' => $this->laboratoryItemsTotal($items),
                ];
            })
            ->filter(fn (array $group) => $group['brand'] !== '__unknown')
            ->sortBy([
                ['total', 'desc'],
                ['count', 'desc'],
                ['brand', 'asc'],
            ])
            ->first()['brand'] ?? null;
    }

    /**
     * @param  Collection<string, Collection<int, LaboratoryCartItem>>  $itemsByBrand
     * @return Collection<int, string>|null
     */
    private function explicitRelationBrands(Cart $cart, Collection $itemsByBrand): ?Collection
    {
        $brands = collect();

        $cart->laboratoryAppointments->each(function ($appointment) use ($brands) {
            if ($appointment->brand instanceof LaboratoryBrand) {
                $brands->push($appointment->brand->value);
            }
        });

        $cart->laboratoryPurchases->each(function ($purchase) use ($brands) {
            if ($purchase->brand instanceof LaboratoryBrand) {
                $brands->push($purchase->brand->value);
            }
        });

        $cart->events->each(function ($event) use ($brands) {
            $brand = $event->metadata['brand'] ?? null;
            if (is_string($brand) && LaboratoryBrand::tryFrom($brand)) {
                $brands->push($brand);
            }
        });

        foreach ($cart->paymentAttempts as $paymentAttempt) {
            $brand = $this->brandForPaymentAttempt($paymentAttempt->amount_cents, $itemsByBrand);
            if ($brand === null) {
                return null;
            }
            $brands->push($brand);
        }

        return $brands->filter()->unique()->values();
    }

    private function brandForPaymentAttempt(?int $amountCents, Collection $itemsByBrand): ?string
    {
        if ($amountCents === null) {
            return null;
        }

        $matches = $itemsByBrand
            ->map(fn (Collection $items, string $brandValue) => [
                'brand' => $brandValue,
                'amount_cents' => (int) round($this->laboratoryItemsTotal($items) * 100),
            ])
            ->filter(fn (array $group) => $group['brand'] !== '__unknown' && $group['amount_cents'] === $amountCents)
            ->pluck('brand')
            ->unique()
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function targetCartForBrand(Cart $cart, string $brandValue, string $brandToKeep): ?Cart
    {
        if ($brandValue === $brandToKeep) {
            return $cart;
        }

        return $this->singleBrandActiveCart((int) $cart->user_id, $brandValue, (int) $cart->id);
    }

    private function singleBrandActiveCart(int $userId, string $brandValue, int $exceptCartId): ?Cart
    {
        return Cart::query()
            ->with('items.laboratoryTest')
            ->where('user_id', $userId)
            ->where('type', MonitoringCartType::Lab)
            ->where('status', MonitoringCartStatus::Active)
            ->whereKeyNot($exceptCartId)
            ->get()
            ->first(function (Cart $cart) use ($brandValue) {
                $brands = $this->cartBrands($cart);

                return $brands->count() === 1 && $brands->first() === $brandValue;
            });
    }

    private function createActiveCart(int $userId): Cart
    {
        $cart = Cart::create([
            'user_id' => $userId,
            'type' => MonitoringCartType::Lab,
            'status' => MonitoringCartStatus::Active,
            'total' => 0,
        ]);

        $this->cartEventRecorder->recordOnce(
            $cart,
            CartEventType::CartCreated,
            "cart:{$cart->id}:created",
            ['type' => MonitoringCartType::Lab->value],
            source: 'monitoring_cart_reconciler',
        );

        return $cart;
    }

    /**
     * @param  Collection<int, LaboratoryCartItem>  $brandItems
     */
    private function replaceLaboratorySnapshot(Cart $cart, Collection $brandItems): void
    {
        $cart->items()->delete();

        foreach ($brandItems as $row) {
            $test = $row->laboratoryTest;
            $line = numberCents($test?->famedic_price_cents ?? 0);

            $cart->items()->create([
                'product_id' => $test ? (string) $test->id : (string) $row->laboratory_test_id,
                'name' => $test?->name ?? 'Estudio de laboratorio',
                'price' => $line,
                'quantity' => 1,
            ]);
        }

        $cart->update([
            'total' => round($this->laboratoryItemsTotal($brandItems), 2),
            'status' => MonitoringCartStatus::Active,
        ]);
    }

    /**
     * @param  Collection<int, LaboratoryCartItem>  $items
     */
    private function laboratoryItemsTotal(Collection $items): float
    {
        return (float) $items->sum(
            fn (LaboratoryCartItem $row) => numberCents($row->laboratoryTest?->famedic_price_cents ?? 0),
        );
    }

    /**
     * @return array<string, array{items: int, subtotal: float, product_ids: list<string>}>
     */
    private function itemsSummaryByBrand(Cart $cart): array
    {
        return $cart->items
            ->groupBy(fn ($item) => $item->laboratoryTest?->brand?->value ?? '__unknown')
            ->map(fn (Collection $items) => [
                'items' => $items->count(),
                'subtotal' => (float) $items->sum(fn ($item) => (float) $item->price * (int) $item->quantity),
                'product_ids' => $items->pluck('product_id')->map(fn ($id) => (string) $id)->values()->all(),
            ])
            ->all();
    }

    /**
     * @return array<string, array{items: int, subtotal: float, product_ids: list<string>}>
     */
    private function sourceItemsSummaryByBrand(Cart $cart): array
    {
        return $this->currentItemsByBrand($cart)
            ->map(fn (Collection $items) => [
                'items' => $items->count(),
                'subtotal' => $this->laboratoryItemsTotal($items),
                'product_ids' => $items->pluck('laboratory_test_id')->map(fn ($id) => (string) $id)->values()->all(),
            ])
            ->all();
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function explicitRelationSummary(Cart $cart, Collection $itemsByBrand): array
    {
        return [
            'payment_attempts' => $cart->paymentAttempts
                ->map(fn ($row) => [
                    'id' => $row->id,
                    'amount_cents' => $row->amount_cents,
                    'brand' => $this->brandForPaymentAttempt($row->amount_cents, $itemsByBrand),
                ])
                ->values()
                ->all(),
            'purchases' => $cart->laboratoryPurchases
                ->map(fn ($row) => ['id' => $row->id, 'brand' => $row->brand?->value ?? (string) $row->brand])
                ->values()
                ->all(),
            'appointments' => $cart->laboratoryAppointments
                ->map(fn ($row) => ['id' => $row->id, 'brand' => $row->brand?->value ?? (string) $row->brand])
                ->values()
                ->all(),
            'cart_events' => $cart->events
                ->map(fn ($row) => ['id' => $row->id, 'event' => $row->event?->value ?? (string) $row->event, 'brand' => is_array($row->metadata) ? ($row->metadata['brand'] ?? null) : null])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $afterCarts
     */
    private function logResult(array $plan, string $result, array $afterCarts, ?string $error = null): void
    {
        Log::info('[CartMonitoring] Multibrand cart reconciliation result', [
            'cart_id' => $plan['cart_id'] ?? null,
            'customer_id' => $plan['customer_id'] ?? null,
            'brands_before' => $plan['brands'] ?? [],
            'cart_ids_after' => collect($afterCarts)->pluck('cart_id')->values()->all(),
            'result' => $result,
            'error' => $error,
        ]);
    }
}

class MultibrandCartConflictException extends \RuntimeException {}
