<?php

namespace App\Services\Monitoring;

use App\Actions\OnlinePharmacy\FetchProductAction;
use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\OnlinePharmacyCartItem;
use App\Services\Carts\CartEventRecorder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncMonitoringCartService
{
    public function __construct(
        private FetchProductAction $fetchProductAction,
        private CartEventRecorder $cartEventRecorder,
    ) {
    }

    public function syncLaboratory(Customer $customer): void
    {
        $userId = $customer->user_id;
        if (! $userId) {
            return;
        }

        $items = $customer->laboratoryCartItems()->with('laboratoryTest')->get();

        if ($items->isEmpty()) {
            $this->deleteActiveCartIfEmpty($userId, MonitoringCartType::Lab);
            $customer = Customer::query()->where('user_id', $userId)->first();
            if ($customer) {
                LaboratoryCheckoutDraft::query()->where('customer_id', $customer->id)->delete();
            }

            return;
        }

        DB::transaction(function () use ($userId, $items) {
            $itemsByBrand = $items->groupBy(
                fn (LaboratoryCartItem $row) => $row->laboratoryTest?->brand?->value ?? '__unknown',
            );

            if (! $this->reconcileActiveLaboratoryCartsByBrand($userId, $itemsByBrand)) {
                return;
            }

            foreach ($itemsByBrand as $brandValue => $brandItems) {
                $cart = $brandValue !== '__unknown'
                    ? $this->firstOrCreateActiveLaboratoryCartForBrand($userId, LaboratoryBrand::from($brandValue))
                    : $this->firstOrCreateBrandlessActiveLaboratoryCart($userId);
                $this->replaceLaboratorySnapshot($cart, $brandItems);
            }

            $this->deleteLaboratoryCartsForMissingBrands($userId, $itemsByBrand->keys()->all());
        });
    }

    public function syncPharmacy(Customer $customer): void
    {
        $userId = $customer->user_id;
        if (! $userId) {
            return;
        }

        $items = $customer->onlinePharmacyCartItems()->get();

        if ($items->isEmpty()) {
            $this->deleteActiveCartIfEmpty($userId, MonitoringCartType::Pharmacy);

            return;
        }

        DB::transaction(function () use ($userId, $items) {
            $cart = $this->firstOrCreateActiveCart($userId, MonitoringCartType::Pharmacy);
            $cart->items()->delete();

            $total = 0;
            foreach ($items as $row) {
                /** @var OnlinePharmacyCartItem $row */
                $name = 'Producto #' . $row->vitau_product_id;
                $unit = 0.0;
                try {
                    $product = ($this->fetchProductAction)((string) $row->vitau_product_id);
                    $name = $product['name'] ?? $name;
                    $unit = isset($product['price']) ? (float) $product['price'] : 0.0;
                } catch (Throwable) {
                }

                $qty = max(1, (int) $row->quantity);
                $line = round($unit * $qty, 2);
                $total += $line;

                $cart->items()->create([
                    'product_id' => (string) $row->vitau_product_id,
                    'name' => $name,
                    'price' => $unit,
                    'quantity' => $qty,
                ]);
            }

            $cart->update([
                'total' => round($total, 2),
                'status' => MonitoringCartStatus::Active,
            ]);
        });
    }

    public function markLaboratoryCartCompleted(Customer $customer, ?LaboratoryBrand $brand = null): ?Cart
    {
        $this->syncLaboratory($customer);
        $userId = $customer->user_id;
        if (! $userId) {
            return null;
        }

        $cart = $brand
            ? $this->activeLaboratoryCart($customer, $brand)
            : $this->activeCartForCustomer($customer, MonitoringCartType::Lab);

        if ($cart && $cart->items()->exists()) {
            $cart->update([
                'status' => MonitoringCartStatus::Completed,
                'completed_at' => now(),
            ]);

            $this->cartEventRecorder->recordOnce(
                $cart->refresh(),
                CartEventType::CartCompleted,
                "cart:{$cart->id}:completed",
                source: 'monitoring_cart_sync',
            );
        }

        return $cart;
    }

    public function markPharmacyCartCompleted(Customer $customer): void
    {
        $this->syncPharmacy($customer);
        $userId = $customer->user_id;
        if (! $userId) {
            return;
        }

        $cart = Cart::query()
            ->where('user_id', $userId)
            ->where('type', MonitoringCartType::Pharmacy)
            ->where('status', MonitoringCartStatus::Active)
            ->first();

        if ($cart && $cart->items()->exists()) {
            $cart->update([
                'status' => MonitoringCartStatus::Completed,
                'completed_at' => now(),
            ]);
        }
    }

    public function touchLaboratoryCartActivity(Customer $customer): void
    {
        if (! $customer->user_id) {
            return;
        }

        Cart::query()
            ->where('user_id', $customer->user_id)
            ->where('type', MonitoringCartType::Lab)
            ->where('status', MonitoringCartStatus::Active)
            ->update(['updated_at' => now()]);
    }

    public function activeLaboratoryCart(Customer $customer, ?LaboratoryBrand $brand = null): ?Cart
    {
        if ($brand !== null && $customer->user_id) {
            return $this->activeLaboratoryCartForBrand((int) $customer->user_id, $brand);
        }

        return $this->activeCartForCustomer($customer, MonitoringCartType::Lab);
    }

    public function activeCartForCustomer(Customer $customer, MonitoringCartType $type): ?Cart
    {
        if (! $customer->user_id) {
            return null;
        }

        return Cart::query()
            ->where('user_id', $customer->user_id)
            ->where('type', $type)
            ->where('status', MonitoringCartStatus::Active)
            ->first();
    }

    private function firstOrCreateActiveCart(int $userId, MonitoringCartType $type): Cart
    {
        $existing = Cart::query()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('status', MonitoringCartStatus::Active)
            ->first();

        if ($existing) {
            return $existing;
        }

        return $this->createActiveCart($userId, $type);
    }

    private function createActiveCart(int $userId, MonitoringCartType $type): Cart
    {
        $cart = Cart::create([
            'user_id' => $userId,
            'type' => $type,
            'status' => MonitoringCartStatus::Active,
            'total' => 0,
        ]);

        $this->cartEventRecorder->recordOnce(
            $cart,
            CartEventType::CartCreated,
            "cart:{$cart->id}:created",
            ['type' => $type->value],
            source: 'monitoring_cart_sync',
        );

        return $cart;
    }

    private function firstOrCreateActiveLaboratoryCartForBrand(int $userId, LaboratoryBrand $brand): Cart
    {
        $existing = $this->activeLaboratoryCartForBrand($userId, $brand);

        if ($existing) {
            return $existing;
        }

        return $this->createActiveCart($userId, MonitoringCartType::Lab);
    }

    private function firstOrCreateBrandlessActiveLaboratoryCart(int $userId): Cart
    {
        $existing = Cart::query()
            ->with('items')
            ->where('user_id', $userId)
            ->where('type', MonitoringCartType::Lab)
            ->where('status', MonitoringCartStatus::Active)
            ->get()
            ->first(fn (Cart $cart) => collect($cart->labBrands())->isEmpty());

        return $existing ?? $this->createActiveCart($userId, MonitoringCartType::Lab);
    }

    private function activeLaboratoryCartForBrand(int $userId, LaboratoryBrand $brand): ?Cart
    {
        return Cart::query()
            ->with('items')
            ->where('user_id', $userId)
            ->where('type', MonitoringCartType::Lab)
            ->where('status', MonitoringCartStatus::Active)
            ->get()
            ->first(function (Cart $cart) use ($brand) {
                $brands = collect($cart->labBrands())->pluck('value');

                return $brands->count() === 1 && $brands->contains($brand->value);
            });
    }

    /**
     * @param  Collection<string, Collection<int, LaboratoryCartItem>>  $itemsByBrand
     */
    private function reconcileActiveLaboratoryCartsByBrand(int $userId, Collection $itemsByBrand): bool
    {
        $activeCarts = Cart::query()
            ->with(['items', 'paymentAttempts', 'laboratoryAppointments', 'laboratoryPurchases', 'events'])
            ->where('user_id', $userId)
            ->where('type', MonitoringCartType::Lab)
            ->where('status', MonitoringCartStatus::Active)
            ->get();

        foreach ($activeCarts as $cart) {
            $brands = collect($cart->labBrands())->pluck('value')->filter()->unique()->values();

            if ($brands->count() <= 1) {
                continue;
            }

            $brandToKeep = $this->brandThatKeepsLegacyCartId($cart, $itemsByBrand);

            if ($brandToKeep === null) {
                Log::warning('[CartMonitoring] Active laboratory cart split skipped because explicit relations are mixed or ambiguous', [
                    'cart_id' => $cart->id,
                    'user_id' => $userId,
                    'brands' => $brands->all(),
                ]);

                return false;
            }

            foreach ($brands as $brandValue) {
                $brandItems = $itemsByBrand->get($brandValue, collect());
                if ($brandItems->isEmpty()) {
                    continue;
                }

                $targetCart = $brandValue === $brandToKeep
                    ? $cart
                    : $this->singleBrandActiveCart($userId, $brandValue, $cart->id)
                        ?? $this->createActiveCart($userId, MonitoringCartType::Lab);

                $this->replaceLaboratorySnapshot($targetCart, $brandItems);
            }
        }

        return true;
    }

    /**
     * @param  Collection<string, Collection<int, LaboratoryCartItem>>  $itemsByBrand
     */
    private function brandThatKeepsLegacyCartId(Cart $cart, Collection $itemsByBrand): ?string
    {
        $explicitBrands = $this->explicitRelationBrands($cart, $itemsByBrand);

        if ($explicitBrands === null || $explicitBrands->count() > 1) {
            return null;
        }

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

    /**
     * @param  Collection<string, Collection<int, LaboratoryCartItem>>  $itemsByBrand
     */
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

    private function singleBrandActiveCart(int $userId, string $brandValue, int $exceptCartId): ?Cart
    {
        return Cart::query()
            ->with('items')
            ->where('user_id', $userId)
            ->where('type', MonitoringCartType::Lab)
            ->where('status', MonitoringCartStatus::Active)
            ->whereKeyNot($exceptCartId)
            ->get()
            ->first(function (Cart $cart) use ($brandValue) {
                $brands = collect($cart->labBrands())->pluck('value')->filter()->values();

                return $brands->count() === 1 && $brands->first() === $brandValue;
            });
    }

    /**
     * @param  Collection<int, LaboratoryCartItem>  $brandItems
     */
    private function replaceLaboratorySnapshot(Cart $cart, Collection $brandItems): void
    {
        $cart->items()->delete();

        foreach ($brandItems as $row) {
            /** @var LaboratoryCartItem $row */
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
     * @param  list<string>  $currentBrandValues
     */
    private function deleteLaboratoryCartsForMissingBrands(int $userId, array $currentBrandValues): void
    {
        $current = collect($currentBrandValues)->filter(fn ($value) => $value !== '__unknown')->values();

        Cart::query()
            ->with('items')
            ->where('user_id', $userId)
            ->where('type', MonitoringCartType::Lab)
            ->where('status', MonitoringCartStatus::Active)
            ->get()
            ->each(function (Cart $cart) use ($current) {
                $brands = collect($cart->labBrands())->pluck('value')->filter()->values();

                if ($brands->isNotEmpty() && $brands->intersect($current)->isEmpty()) {
                    $cart->delete();
                }
            });
    }

    private function deleteActiveCartIfEmpty(int $userId, MonitoringCartType $type): void
    {
        Cart::query()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('status', MonitoringCartStatus::Active)
            ->delete();
    }
}
