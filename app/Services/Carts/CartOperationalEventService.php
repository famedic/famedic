<?php

namespace App\Services\Carts;

use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\LaboratoryCartItem;
use App\Models\OnlinePharmacyCartItem;
use App\Services\Monitoring\SyncMonitoringCartService;

class CartOperationalEventService
{
    public function __construct(
        private CartEventRecorder $cartEventRecorder,
        private SyncMonitoringCartService $syncMonitoringCartService,
    ) {}

    /**
     * @param  array<string, mixed>|null  $clientContext
     */
    public function recordLaboratoryItemAdded(LaboratoryCartItem $item, ?array $clientContext = null): void
    {
        $item->loadMissing('laboratoryTest', 'customer');
        $cart = $this->resolveLaboratoryCart($item->customer, $item->laboratoryTest?->brand);

        if ($cart === null) {
            return;
        }

        $this->cartEventRecorder->recordOnce(
            $cart,
            CartEventType::CartItemAdded,
            "laboratory_cart_item:{$item->id}:created",
            $this->withClientContext($this->laboratoryItemMetadata($item, $cart), $clientContext),
            source: 'laboratory_cart',
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>|null  $clientContext
     */
    public function recordLaboratoryItemRemoved(array $snapshot, ?Cart $cart, ?array $clientContext = null): void
    {
        if ($cart === null) {
            return;
        }

        $this->cartEventRecorder->recordOnce(
            $cart,
            CartEventType::CartItemRemoved,
            "laboratory_cart_item:{$snapshot['operational_item_id']}:deleted",
            $this->withClientContext($this->mergeCartTotal($snapshot, $cart), $clientContext),
            source: 'laboratory_cart',
        );
    }

    /**
     * @param  array<string, mixed>|null  $clientContext
     * @param  array<string, mixed>  $metadata
     */
    public function recordCartEmptied(
        Cart $cart,
        ?array $clientContext,
        string $idempotencyKey,
        array $metadata = [],
    ): void {
        $this->cartEventRecorder->recordOnce(
            $cart,
            CartEventType::CartEmptied,
            $idempotencyKey,
            $this->withClientContext(array_merge([
                'cart_total' => 0,
                'type' => $cart->type->value,
            ], $metadata), $clientContext),
            source: 'monitoring_cart_sync',
        );
    }

    /**
     * @param  array<string, mixed>|null  $clientContext
     */
    public function recordPharmacyItemAdded(OnlinePharmacyCartItem $item, ?array $clientContext = null): void
    {
        $item->loadMissing('customer');
        $cart = $this->syncMonitoringCartService->activeCartForCustomer($item->customer, MonitoringCartType::Pharmacy);

        if ($cart === null) {
            return;
        }

        $this->cartEventRecorder->recordOnce(
            $cart,
            CartEventType::CartItemAdded,
            "online_pharmacy_cart_item:{$item->id}:created",
            $this->withClientContext($this->pharmacyItemMetadata($item, $cart), $clientContext),
            source: 'online_pharmacy_cart',
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>|null  $clientContext
     */
    public function recordPharmacyItemRemoved(array $snapshot, ?Cart $cart, ?array $clientContext = null): void
    {
        if ($cart === null) {
            return;
        }

        $this->cartEventRecorder->recordOnce(
            $cart,
            CartEventType::CartItemRemoved,
            "online_pharmacy_cart_item:{$snapshot['operational_item_id']}:deleted",
            $this->withClientContext($this->mergeCartTotal($snapshot, $cart), $clientContext),
            source: 'online_pharmacy_cart',
        );
    }

    /**
     * @param  array<string, mixed>|null  $clientContext
     */
    public function recordPharmacyQuantityChanged(
        OnlinePharmacyCartItem $item,
        int $previousQuantity,
        ?array $clientContext = null,
    ): void {
        $item->loadMissing('customer');
        $item->refresh();

        $cart = $this->syncMonitoringCartService->activeCartForCustomer($item->customer, MonitoringCartType::Pharmacy);

        if ($cart === null) {
            return;
        }

        $operationStamp = $item->updated_at?->format('U.u') ?? (string) microtime(true);

        $this->cartEventRecorder->recordOnce(
            $cart,
            CartEventType::CartItemQuantityChanged,
            "online_pharmacy_cart_item:{$item->id}:quantity_changed:{$operationStamp}",
            $this->withClientContext(array_merge($this->pharmacyItemMetadata($item, $cart), [
                'previous_quantity' => $previousQuantity,
                'quantity' => (int) $item->quantity,
            ]), $clientContext),
            source: 'online_pharmacy_cart',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotLaboratoryItem(LaboratoryCartItem $item): array
    {
        $item->loadMissing('laboratoryTest');

        $test = $item->laboratoryTest;

        return [
            'operational_item_id' => $item->id,
            'product_id' => $test ? (string) $test->id : (string) $item->laboratory_test_id,
            'product_name' => $test?->name,
            'brand' => $test?->brand?->value,
            'brand_label' => $test?->brand?->label(),
            'quantity' => 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotPharmacyItem(OnlinePharmacyCartItem $item): array
    {
        return [
            'operational_item_id' => $item->id,
            'product_id' => (string) $item->vitau_product_id,
            'product_name' => null,
            'quantity' => max(1, (int) $item->quantity),
        ];
    }

    public function resolveLaboratoryCart(Customer $customer, ?LaboratoryBrand $brand): ?Cart
    {
        return $this->syncMonitoringCartService->activeLaboratoryCart($customer, $brand);
    }

    public function resolvePharmacyCart(Customer $customer): ?Cart
    {
        return $this->syncMonitoringCartService->activeCartForCustomer($customer, MonitoringCartType::Pharmacy);
    }

    /**
     * @return array<string, mixed>
     */
    private function laboratoryItemMetadata(LaboratoryCartItem $item, Cart $cart): array
    {
        $test = $item->laboratoryTest;

        return [
            'operational_item_id' => $item->id,
            'product_id' => $test ? (string) $test->id : (string) $item->laboratory_test_id,
            'product_name' => $test?->name,
            'brand' => $test?->brand?->value,
            'brand_label' => $test?->brand?->label(),
            'quantity' => 1,
            'cart_total' => (float) $cart->total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pharmacyItemMetadata(OnlinePharmacyCartItem $item, Cart $cart): array
    {
        return [
            'operational_item_id' => $item->id,
            'product_id' => (string) $item->vitau_product_id,
            'product_name' => null,
            'quantity' => max(1, (int) $item->quantity),
            'cart_total' => (float) $cart->total,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function mergeCartTotal(array $snapshot, Cart $cart): array
    {
        return array_merge($snapshot, [
            'cart_total' => (float) $cart->total,
        ]);
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
}
