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
use App\Services\Carts\CartAbandonmentService;
use App\Services\Carts\CartEventRecorder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncMonitoringCartService
{
    public function __construct(
        private FetchProductAction $fetchProductAction,
        private CartEventRecorder $cartEventRecorder,
        private MultibrandCartReconciler $multibrandCartReconciler,
        private CartAbandonmentService $cartAbandonmentService,
    ) {}

    /**
     * @param  array<string, mixed>|null  $clientContext
     */
    public function syncLaboratory(Customer $customer, ?array $clientContext = null): void
    {
        $userId = $customer->user_id;
        if (! $userId) {
            return;
        }

        $items = $customer->laboratoryCartItems()->with('laboratoryTest')->get();

        if ($items->isNotEmpty()) {
            $this->cartAbandonmentService->maybeRecordResumedForCustomer(
                $customer,
                MonitoringCartType::Lab,
                $clientContext,
            );
        }

        if ($items->isEmpty()) {
            $this->emptyActiveMonitoringCarts($userId, MonitoringCartType::Lab);
            $customer = Customer::query()->where('user_id', $userId)->first();
            if ($customer) {
                LaboratoryCheckoutDraft::query()->where('customer_id', $customer->id)->delete();
            }

            return;
        }

        DB::transaction(function () use ($userId, $items, $clientContext) {
            $itemsByBrand = $items->groupBy(
                fn (LaboratoryCartItem $row) => $row->laboratoryTest?->brand?->value ?? '__unknown',
            );

            if (! $this->multibrandCartReconciler->reconcileActiveCartsForUser($userId, $itemsByBrand)) {
                return;
            }

            foreach ($itemsByBrand as $brandValue => $brandItems) {
                $cart = $brandValue !== '__unknown'
                    ? $this->firstOrCreateActiveLaboratoryCartForBrand($userId, LaboratoryBrand::from($brandValue), $clientContext)
                    : $this->firstOrCreateBrandlessActiveLaboratoryCart($userId, $clientContext);
                $this->replaceLaboratorySnapshot($cart, $brandItems);
            }

            $this->deleteLaboratoryCartsForMissingBrands($userId, $itemsByBrand->keys()->all());
        });
    }

    /**
     * @param  array<string, mixed>|null  $clientContext
     */
    public function syncPharmacy(Customer $customer, ?array $clientContext = null): void
    {
        $userId = $customer->user_id;
        if (! $userId) {
            return;
        }

        $items = $customer->onlinePharmacyCartItems()->get();

        if ($items->isNotEmpty()) {
            $this->cartAbandonmentService->maybeRecordResumedForCustomer(
                $customer,
                MonitoringCartType::Pharmacy,
                $clientContext,
            );
        }

        if ($items->isEmpty()) {
            $this->emptyActiveMonitoringCarts($userId, MonitoringCartType::Pharmacy);

            return;
        }

        DB::transaction(function () use ($userId, $items, $clientContext) {
            $cart = $this->firstOrCreateActiveCart($userId, MonitoringCartType::Pharmacy, $clientContext);
            $cart->items()->delete();

            $total = 0;
            foreach ($items as $row) {
                /** @var OnlinePharmacyCartItem $row */
                $name = 'Producto #'.$row->vitau_product_id;
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

    /**
     * @param  array<string, mixed>|null  $clientContext
     */
    public function markLaboratoryCartCompleted(Customer $customer, ?LaboratoryBrand $brand = null, ?array $clientContext = null): ?Cart
    {
        $this->syncLaboratory($customer, $clientContext);
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
                $this->withClientContext([], $clientContext),
                source: 'monitoring_cart_sync',
            );

            $this->cartAbandonmentService->recordRecoveredIfEligible(
                $cart->refresh(),
                $cart->laboratoryPurchases()->latest('id')->value('id'),
                $clientContext,
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

            $this->cartAbandonmentService->recordRecoveredIfEligible($cart->refresh());
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

    /**
     * @param  array<string, mixed>|null  $clientContext
     */
    private function firstOrCreateActiveCart(int $userId, MonitoringCartType $type, ?array $clientContext = null): Cart
    {
        $existing = Cart::query()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('status', MonitoringCartStatus::Active)
            ->first();

        if ($existing) {
            return $existing;
        }

        return $this->createActiveCart($userId, $type, $clientContext);
    }

    /**
     * @param  array<string, mixed>|null  $clientContext
     */
    private function createActiveCart(int $userId, MonitoringCartType $type, ?array $clientContext = null): Cart
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
            $this->withClientContext(['type' => $type->value], $clientContext),
            source: 'monitoring_cart_sync',
        );

        return $cart;
    }

    /**
     * @param  array<string, mixed>|null  $clientContext
     */
    private function firstOrCreateActiveLaboratoryCartForBrand(int $userId, LaboratoryBrand $brand, ?array $clientContext = null): Cart
    {
        $existing = $this->activeLaboratoryCartForBrand($userId, $brand);

        if ($existing) {
            return $existing;
        }

        return $this->createActiveCart($userId, MonitoringCartType::Lab, $clientContext);
    }

    /**
     * @param  array<string, mixed>|null  $clientContext
     */
    private function firstOrCreateBrandlessActiveLaboratoryCart(int $userId, ?array $clientContext = null): Cart
    {
        $existing = Cart::query()
            ->with('items')
            ->where('user_id', $userId)
            ->where('type', MonitoringCartType::Lab)
            ->where('status', MonitoringCartStatus::Active)
            ->get()
            ->first(fn (Cart $cart) => collect($cart->labBrands())->isEmpty());

        return $existing ?? $this->createActiveCart($userId, MonitoringCartType::Lab, $clientContext);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>|null  $clientContext
     * @return array<string, mixed>
     */
    private function withClientContext(array $metadata, ?array $clientContext): array
    {
        if ($clientContext === null || $clientContext === []) {
            return $metadata;
        }

        return array_merge($metadata, ['client' => $clientContext]);
    }

    private function activeLaboratoryCartForBrand(int $userId, LaboratoryBrand $brand): ?Cart
    {
        $withItems = Cart::query()
            ->with('items')
            ->where('user_id', $userId)
            ->where('type', MonitoringCartType::Lab)
            ->where('status', MonitoringCartStatus::Active)
            ->get()
            ->first(function (Cart $cart) use ($brand) {
                $brands = collect($cart->labBrands())->pluck('value');

                return $brands->count() === 1 && $brands->contains($brand->value);
            });

        if ($withItems) {
            return $withItems;
        }

        return Cart::query()
            ->where('user_id', $userId)
            ->where('type', MonitoringCartType::Lab)
            ->where('status', MonitoringCartStatus::Active)
            ->whereDoesntHave('items')
            ->whereHas('events', function ($query) use ($brand) {
                $query->whereIn('event', [
                    CartEventType::CartItemAdded->value,
                    CartEventType::CartItemRemoved->value,
                    CartEventType::CartEmptied->value,
                ])->where('metadata->brand', $brand->value);
            })
            ->orderByDesc('updated_at')
            ->first();
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
                    $this->emptyMonitoringCart($cart);
                }
            });
    }

    private function emptyMonitoringCart(Cart $cart): void
    {
        $cart->items()->delete();
        $cart->update([
            'total' => 0,
            'status' => MonitoringCartStatus::Active,
        ]);
    }

    private function emptyActiveMonitoringCarts(int $userId, MonitoringCartType $type): void
    {
        Cart::query()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('status', MonitoringCartStatus::Active)
            ->each(fn (Cart $cart) => $this->emptyMonitoringCart($cart));
    }
}
