<?php

namespace App\Console\Commands;

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Enums\MonitoringCartType;
use App\Models\AkubicaCheckoutLink;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Services\Api\V1\Continuity\LaboratoryCheckoutContinuity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A4-only, namespace-scoped laboratory cart preparation for membership_none.
 * It creates no access, link, payment or external job and never widens Leo.
 * Inventory registration is a separate explicit step using the inventory
 * helper, so cleanup can prove ownership before deleting the dynamic ID.
 */
final class A4ContinuityCartFixturesCommand extends Command
{
    public const CONFIRM = 'akubica-a4-continuity-cart';

    public const PURPOSE = 'cart-item-created-by-A4-continuity';

    public const SOURCE_CASE = 'A4';

    public const TEST_PREFIX = 'RC2-A4-CONTINUITY-';

    public const RETIRE_MONITORING_CONFIRM = 'akubica-a4-retire-monitoring-residue';

    private const CATEGORY = '[UAT] RC2 A4 continuidad laboratorio';

    protected $signature = 'akubica:a4-continuity-cart
                            {--dry-run : Muestra el plan sin escribir}
                            {--apply : Crea el ítem OLAB temporal}
                            {--cleanup : Elimina sólo el ítem OLAB temporal propio}
                            {--retire-monitoring-residue : Retira un residuo de monitoreo acreditado, con IDs exactos}
                            {--cart-id= : ID exacto del carrito de monitoreo}
                            {--cart-item-id= : ID exacto del ítem de monitoreo}
                            {--execute : Ejecuta el retiro del residuo de monitoreo (si se omite, sólo dry-run)}
                            {--namespace= : Namespace dedicado obligatorio}
                            {--confirm= : Confirmación exacta para apply/cleanup/execute}';

    protected $description = 'Prepara y limpia el ítem OLAB temporal de A4 para membership_none.';

    public function handle(): int
    {
        if (! app()->environment((array) config('akubica_uat.allowed_environments', ['testing', 'staging']))) {
            return $this->emit('error', ['error_code' => 'A4_FIXTURE_ENVIRONMENT_NOT_ALLOWED'], self::FAILURE);
        }

        $namespace = (string) $this->option('namespace');
        if ($namespace !== AkubicaUatFixtureContract::DEDICATED_NAMESPACE) {
            return $this->emit('error', ['error_code' => 'A4_FIXTURE_NAMESPACE_NOT_ALLOWED'], self::FAILURE);
        }

        $apply = (bool) $this->option('apply');
        $cleanup = (bool) $this->option('cleanup');
        $retire = (bool) $this->option('retire-monitoring-residue');
        if ((int) $apply + (int) $cleanup + (int) $retire > 1) {
            return $this->emit('error', ['error_code' => 'A4_FIXTURE_INVALID_OPTION_COMBINATION'], self::FAILURE);
        }
        if (($apply || $cleanup) && (string) $this->option('confirm') !== self::CONFIRM) {
            return $this->emit('error', ['error_code' => 'A4_FIXTURE_CONFIRMATION_MISMATCH'], self::FAILURE);
        }

        $manifest = AkubicaUatFixtureManifest::query()
            ->where('namespace', $namespace)
            ->where('status', AkubicaUatFixtureContract::STATUS_ACTIVE)
            ->first();
        if ($manifest === null) {
            return $this->emit('error', ['error_code' => 'A4_FIXTURE_NAMESPACE_NOT_ACTIVE'], self::FAILURE);
        }

        $customerId = (int) data_get($manifest->metadata, 'ids.customers.membership_none', 0);
        if ($customerId < 1) {
            return $this->emit('error', ['error_code' => 'A4_FIXTURE_MEMBERSHIP_NONE_NOT_IN_MANIFEST'], self::FAILURE);
        }

        if ($retire) {
            return $this->retireMonitoringResidue($namespace, $customerId);
        }

        $existingTest = LaboratoryTest::query()->withTrashed()->where('gda_id', $this->testGdaId($namespace))->first();
        $existingItem = $existingTest === null
            ? null
            : LaboratoryCartItem::query()->withTrashed()->where('laboratory_test_id', $existingTest->id)->first();
        $plan = [
            'namespace' => $namespace,
            'purpose' => self::PURPOSE,
            'owner' => 'customer:membership_none',
            'customer_id' => $customerId,
            'resource_type' => 'laboratory_cart_item',
            'service_line' => 'laboratory',
            'brand' => 'olab',
            'test_gda_id' => $this->testGdaId($namespace),
            'existing_test_id' => $existingTest?->id,
            'existing_item_id' => $existingItem?->id,
        ];

        if (! $apply && ! $cleanup) {
            return $this->emit('ok', ['mode' => 'dry-run', 'plan' => $plan]);
        }

        $lock = Cache::lock('akubica-a4-continuity-cart:'.$namespace, 30);
        if (! $lock->get()) {
            return $this->emit('error', ['error_code' => 'A4_FIXTURE_LOCK_UNAVAILABLE'], self::FAILURE);
        }

        try {
            return LaboratoryTest::withoutSyncingToSearch(fn () => DB::transaction(fn () => $apply
                ? $this->apply($namespace, $customerId, $existingTest, $existingItem)
                : $this->cleanup($namespace, $customerId, $existingTest, $existingItem)));
        } catch (\DomainException $exception) {
            return $this->emit('error', ['error_code' => $exception->getMessage()], self::FAILURE);
        } finally {
            $lock->release();
        }
    }

    private function apply(string $namespace, int $customerId, ?LaboratoryTest $existingTest, ?LaboratoryCartItem $existingItem): int
    {
        if ($existingTest !== null && $existingTest->trashed()) {
            $existingTest->restore();
        }
        if ($existingItem !== null && (int) $existingItem->customer_id !== $customerId) {
            throw new \DomainException('A4_FIXTURE_RESOURCE_COLLISION');
        }
        if ($existingItem !== null && $existingItem->trashed()) {
            $existingItem->restore();
        }

        $categoryName = self::CATEGORY.' · '.$namespace;
        $category = LaboratoryTestCategory::query()->withTrashed()->where('name', $categoryName)->first();
        $categoryCreated = false;
        if ($category === null) {
            $category = LaboratoryTestCategory::query()->create(['name' => $categoryName]);
            $categoryCreated = true;
        }
        if ($category->trashed()) {
            $category->restore();
        }
        $test = $existingTest ?? LaboratoryTest::query()->create([
            'brand' => 'olab',
            'gda_id' => $this->testGdaId($namespace),
            'name' => '[UAT] RC2 A4 continuidad OLAB',
            'indications' => '[UAT] estudio sintético RC2; no clínico',
            'requires_appointment' => false,
            'public_price_cents' => 40000,
            'famedic_price_cents' => 30000,
            'laboratory_test_category_id' => $category->id,
        ]);
        if ($test->laboratory_test_category_id !== $category->id) {
            throw new \DomainException('A4_FIXTURE_RESOURCE_COLLISION');
        }

        $item = $existingItem ?? LaboratoryCartItem::query()->create([
            'customer_id' => $customerId,
            'laboratory_test_id' => $test->id,
        ]);
        if ((int) $item->customer_id !== $customerId || (int) $item->laboratory_test_id !== (int) $test->id) {
            throw new \DomainException('A4_FIXTURE_RESOURCE_COLLISION');
        }
        $existingLedger = $this->readLedger($namespace);
        if (($existingLedger['status'] ?? null) === 'completed') {
            $existingLedger = null;
        }
        if ($existingLedger !== null && ($existingLedger['category_id'] ?? null) !== (int) $category->id) {
            throw new \DomainException('A4_FIXTURE_LEDGER_COLLISION');
        }
        $this->writeLedger($namespace, [
            'namespace' => $namespace,
            'status' => 'active',
            'category_id' => (int) $category->id,
            'category_created' => $existingLedger['category_created'] ?? $categoryCreated,
            'test_id' => (int) $test->id,
            'item_id' => (int) $item->id,
            'monitoring_cart_id' => $this->activeLaboratoryMonitoringCartId($customerId),
        ]);

        return $this->emit('ok', [
            'mode' => 'apply',
            'resource_id' => $item->id,
            'test_id' => $test->id,
            'customer_id' => $customerId,
            'purpose' => self::PURPOSE,
            'owner' => 'customer:membership_none',
            'brand' => 'olab',
            'service_line' => 'laboratory',
            'idempotent' => $existingItem !== null,
        ]);
    }

    private function cleanup(string $namespace, int $customerId, ?LaboratoryTest $existingTest, ?LaboratoryCartItem $existingItem): int
    {
        $ledger = $this->readLedger($namespace);
        if (($ledger['status'] ?? null) === 'completed') {
            $monitoring = $this->reconcileWindowMonitoring($namespace, $customerId, $ledger);

            return $this->emit('ok', [
                'mode' => 'cleanup',
                'deleted' => [
                    'laboratory_cart_items' => 0,
                    'laboratory_tests' => 0,
                    'categories' => 0,
                    'monitoring_carts' => $monitoring['monitoring_carts'],
                    'monitoring_cart_items' => $monitoring['monitoring_cart_items'],
                ],
                'reason' => 'ALREADY_COMPLETED',
                'monitoring_reason' => $monitoring['reason'],
            ]);
        }
        if ($ledger !== null) {
            $ledgerTest = LaboratoryTest::query()->withTrashed()->find((int) ($ledger['test_id'] ?? 0));
            $ledgerItem = LaboratoryCartItem::query()->withTrashed()->find((int) ($ledger['item_id'] ?? 0));
            if ($existingTest !== null && $ledgerTest !== null && (int) $existingTest->id !== (int) $ledgerTest->id) {
                return $this->emit('error', ['error_code' => 'A4_FIXTURE_LEDGER_RESOURCE_MISMATCH'], self::FAILURE);
            }
            if ($existingItem !== null && $ledgerItem !== null && (int) $existingItem->id !== (int) $ledgerItem->id) {
                return $this->emit('error', ['error_code' => 'A4_FIXTURE_LEDGER_RESOURCE_MISMATCH'], self::FAILURE);
            }
            $existingTest = $ledgerTest;
            $existingItem = $ledgerItem;
        }
        if ($existingTest === null && $existingItem === null) {
            [$categoryDeleted, $categoryReason] = $this->cleanupCategory($ledger);
            $monitoring = $this->reconcileWindowMonitoring($namespace, $customerId, $ledger);
            if ($ledger !== null) {
                $this->writeLedger($namespace, array_merge($ledger, [
                    'status' => 'completed',
                    'resources_absent' => true,
                    'category_deleted' => $categoryDeleted > 0,
                    'category_reason' => $categoryReason,
                    'monitoring_reason' => $monitoring['reason'],
                ]));
            }

            return $this->emit('ok', [
                'mode' => 'cleanup',
                'deleted' => [
                    'laboratory_cart_items' => 0,
                    'laboratory_tests' => 0,
                    'categories' => $categoryDeleted,
                    'monitoring_carts' => $monitoring['monitoring_carts'],
                    'monitoring_cart_items' => $monitoring['monitoring_cart_items'],
                ],
                'reason' => 'RESOURCE_NOT_CREATED',
                'category_reason' => $categoryReason,
                'monitoring_reason' => $monitoring['reason'],
            ]);
        }
        if (($existingTest === null || $existingItem === null) && $ledger !== null) {
            if ($existingItem !== null && ((int) $existingItem->customer_id !== $customerId || (int) $existingItem->laboratory_test_id !== (int) ($ledger['test_id'] ?? 0))) {
                return $this->emit('error', ['error_code' => 'A4_FIXTURE_RESOURCE_NOT_OWNED'], self::FAILURE);
            }
            if ($existingTest !== null && ! str_starts_with((string) $existingTest->gda_id, self::TEST_PREFIX.$namespace.'-')) {
                return $this->emit('error', ['error_code' => 'A4_FIXTURE_RESOURCE_NOT_OWNED'], self::FAILURE);
            }
            $itemDeleted = $existingItem === null ? 0 : LaboratoryCartItem::query()->withTrashed()->whereKey($existingItem->id)->forceDelete();
            $testDeleted = 0;
            if ($existingTest !== null && ! LaboratoryCartItem::query()->withTrashed()->where('laboratory_test_id', $existingTest->id)->exists()) {
                $testDeleted = LaboratoryTest::query()->withTrashed()->whereKey($existingTest->id)->forceDelete();
            }
            [$categoryDeleted, $categoryReason] = $this->cleanupCategory($ledger);
            $monitoring = $this->reconcileWindowMonitoring($namespace, $customerId, $ledger);
            $this->writeLedger($namespace, array_merge($ledger, [
                'status' => 'completed',
                'resources_absent' => true,
                'category_deleted' => $categoryDeleted > 0,
                'category_reason' => $categoryReason,
                'monitoring_reason' => $monitoring['reason'],
            ]));

            return $this->emit('ok', [
                'mode' => 'cleanup',
                'deleted' => [
                    'laboratory_cart_items' => $itemDeleted,
                    'laboratory_tests' => $testDeleted,
                    'categories' => $categoryDeleted,
                    'monitoring_carts' => $monitoring['monitoring_carts'],
                    'monitoring_cart_items' => $monitoring['monitoring_cart_items'],
                ],
                'reason' => 'PARTIAL_RESOURCE_RECOVERED',
                'category_reason' => $categoryReason,
                'monitoring_reason' => $monitoring['reason'],
            ]);
        }
        if ($existingTest === null || $existingItem === null || (int) $existingItem->customer_id !== $customerId || ! str_starts_with((string) $existingTest->gda_id, self::TEST_PREFIX.$namespace.'-')) {
            return $this->emit('error', ['error_code' => 'A4_FIXTURE_RESOURCE_NOT_OWNED'], self::FAILURE);
        }
        $itemDeleted = LaboratoryCartItem::query()->withTrashed()->whereKey($existingItem->id)->forceDelete();
        $testDeleted = 0;
        if (! LaboratoryCartItem::query()->withTrashed()->where('laboratory_test_id', $existingTest->id)->exists()) {
            $testDeleted = LaboratoryTest::query()->withTrashed()->whereKey($existingTest->id)->forceDelete();
        }
        $categoryDeleted = 0;
        $categoryReason = 'CATEGORY_PROVENANCE_UNCONFIRMED';
        if ($ledger !== null && ($ledger['category_created'] ?? false) === true) {
            [$categoryDeleted, $categoryReason] = $this->cleanupCategory($ledger);
        }
        $monitoring = $this->reconcileWindowMonitoring($namespace, $customerId, $ledger);
        if ($ledger !== null) {
            $this->writeLedger($namespace, array_merge($ledger, [
                'status' => 'completed',
                'resources_absent' => ! LaboratoryCartItem::query()->withTrashed()->whereKey($existingItem->id)->exists()
                    && ! LaboratoryTest::query()->withTrashed()->whereKey($existingTest->id)->exists(),
                'category_deleted' => $categoryDeleted > 0,
                'category_reason' => $categoryReason,
                'monitoring_reason' => $monitoring['reason'],
            ]));
        }

        return $this->emit('ok', [
            'mode' => 'cleanup',
            'deleted' => [
                'laboratory_cart_items' => $itemDeleted,
                'laboratory_tests' => $testDeleted,
                'categories' => $categoryDeleted,
                'monitoring_carts' => $monitoring['monitoring_carts'],
                'monitoring_cart_items' => $monitoring['monitoring_cart_items'],
            ],
            'category_reason' => $categoryReason,
            'monitoring_reason' => $monitoring['reason'],
            'resource_id' => $existingItem->id,
        ]);
    }

    /**
     * Namespace-scoped reconciliation of monitoring carts/items after laboratory cleanup.
     * Query-builder deletes are used on purpose so Eloquent observers are not relied upon
     * and no ActiveCampaign/mail/provider side effects are dispatched.
     *
     * @param  array<string, mixed>|null  $ledger
     * @return array{monitoring_carts: int, monitoring_cart_items: int, links_expired: int, reason: string}
     */
    private function reconcileWindowMonitoring(string $namespace, int $customerId, ?array $ledger): array
    {
        $customer = Customer::query()->find($customerId);
        if ($customer === null || ! $customer->user_id) {
            return ['monitoring_carts' => 0, 'monitoring_cart_items' => 0, 'links_expired' => 0, 'reason' => 'CUSTOMER_ABSENT'];
        }

        $carts = Cart::query()
            ->where('user_id', $customer->user_id)
            ->where('type', MonitoringCartType::Lab)
            ->get();

        $deletedCarts = 0;
        $deletedItems = 0;
        $windowCartIds = [];

        foreach ($carts as $cart) {
            if (! $this->monitoringCartBelongsToWindow($cart, $customerId, $ledger)) {
                continue;
            }
            $windowCartIds[] = (int) $cart->id;

            if (LaboratoryCartItem::query()->where('customer_id', $customerId)->exists()) {
                $testId = (int) ($ledger['test_id'] ?? 0);
                $itemQuery = CartItem::query()->where('cart_id', $cart->id);
                if ($testId > 0) {
                    $itemQuery->where('product_id', (string) $testId);
                }
                $deletedItems += $itemQuery->delete();

                continue;
            }

            $deletedItems += CartItem::query()->where('cart_id', $cart->id)->delete();
            $deletedCarts += (int) Cart::query()->whereKey($cart->id)->delete();
        }

        $linksExpired = $this->expireUnusedWindowLinks($customerId, $windowCartIds);

        $reason = $deletedCarts + $deletedItems + $linksExpired > 0
            ? 'WINDOW_MONITORING_RECONCILED'
            : ($windowCartIds === [] ? 'NO_WINDOW_MONITORING_CART' : 'WINDOW_MONITORING_ALREADY_ABSENT');

        return [
            'monitoring_carts' => $deletedCarts,
            'monitoring_cart_items' => $deletedItems,
            'links_expired' => $linksExpired,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $ledger
     */
    private function monitoringCartBelongsToWindow(Cart $cart, int $customerId, ?array $ledger): bool
    {
        if ($ledger !== null && (int) ($ledger['monitoring_cart_id'] ?? 0) === (int) $cart->id && (int) $cart->id > 0) {
            return true;
        }

        $testId = (int) ($ledger['test_id'] ?? 0);
        if ($testId > 0 && CartItem::query()->where('cart_id', $cart->id)->where('product_id', (string) $testId)->exists()) {
            return true;
        }

        return $this->windowContinuityLinksQuery($customerId, [(int) $cart->id])->exists();
    }

    /**
     * @param  list<int>  $cartIds
     */
    private function windowContinuityLinksQuery(int $customerId, array $cartIds)
    {
        $query = AkubicaCheckoutLink::query()
            ->where('customer_id', $customerId)
            ->where('metadata->purpose', LaboratoryCheckoutContinuity::PURPOSE);

        return $query->where(function ($q) use ($cartIds) {
            foreach ($cartIds as $cartId) {
                if ($cartId < 1) {
                    continue;
                }
                $q->orWhere('metadata->laboratory_cart_id', $cartId)
                    ->orWhere('metadata->laboratory_cart_id', (string) $cartId);
            }
        });
    }

    /**
     * @param  list<int>  $cartIds
     */
    private function expireUnusedWindowLinks(int $customerId, array $cartIds): int
    {
        $cartIds = array_values(array_filter($cartIds, fn (int $id): bool => $id > 0));
        if ($cartIds === []) {
            return 0;
        }

        return $this->windowContinuityLinksQuery($customerId, $cartIds)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now(), 'updated_at' => now()]);
    }

    private function activeLaboratoryMonitoringCartId(int $customerId): ?int
    {
        $userId = Customer::query()->whereKey($customerId)->value('user_id');
        if (! $userId) {
            return null;
        }

        $id = Cart::query()
            ->where('user_id', $userId)
            ->where('type', MonitoringCartType::Lab)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function retireMonitoringResidue(string $namespace, int $customerId): int
    {
        $cartId = (int) $this->option('cart-id');
        $cartItemId = (int) $this->option('cart-item-id');
        $execute = (bool) $this->option('execute');

        if ($cartId < 1 || $cartItemId < 1) {
            return $this->emit('error', ['error_code' => 'A4_MONITORING_RESIDUE_IDS_REQUIRED'], self::FAILURE);
        }
        if ($execute && (string) $this->option('confirm') !== self::RETIRE_MONITORING_CONFIRM) {
            return $this->emit('error', ['error_code' => 'A4_FIXTURE_CONFIRMATION_MISMATCH'], self::FAILURE);
        }

        $customer = Customer::query()->find($customerId);
        if ($customer === null || ! $customer->user_id) {
            return $this->emit('error', ['error_code' => 'A4_FIXTURE_MEMBERSHIP_NONE_NOT_IN_MANIFEST'], self::FAILURE);
        }

        $cart = Cart::query()->find($cartId);
        $item = CartItem::query()->find($cartItemId);
        $ledger = $this->readLedger($namespace);

        $plan = [
            'mode' => $execute ? 'retire-monitoring-residue' : 'retire-monitoring-residue-dry-run',
            'namespace' => $namespace,
            'customer_id' => $customerId,
            'cart_id' => $cartId,
            'cart_item_id' => $cartItemId,
            'cart_present' => $cart !== null,
            'cart_item_present' => $item !== null,
        ];

        if ($cart === null && $item === null) {
            if (! $this->historicalResidueProvenance($cartId, $customerId, $ledger)) {
                return $this->emit('error', ['error_code' => 'A4_MONITORING_RESIDUE_ALREADY_ABSENT_PROVENANCE_UNCONFIRMED', 'plan' => $plan], self::FAILURE);
            }

            return $this->emit('ok', $plan + ['reason' => 'ALREADY_ABSENT', 'deleted' => ['monitoring_carts' => 0, 'monitoring_cart_items' => 0]]);
        }

        if ($cart === null || (int) $cart->user_id !== (int) $customer->user_id || $cart->type !== MonitoringCartType::Lab) {
            return $this->emit('error', ['error_code' => 'A4_MONITORING_RESIDUE_NOT_OWNED', 'plan' => $plan], self::FAILURE);
        }
        if ($item === null || (int) $item->cart_id !== $cartId) {
            return $this->emit('error', ['error_code' => 'A4_MONITORING_RESIDUE_ITEM_MISMATCH', 'plan' => $plan], self::FAILURE);
        }

        $otherItems = CartItem::query()->where('cart_id', $cartId)->whereKeyNot($cartItemId)->count();
        if ($otherItems > 0) {
            return $this->emit('error', ['error_code' => 'A4_MONITORING_RESIDUE_CART_NOT_EXCLUSIVE', 'plan' => $plan + ['other_item_count' => $otherItems]], self::FAILURE);
        }

        if (LaboratoryCartItem::query()->where('customer_id', $customerId)->exists()) {
            return $this->emit('error', ['error_code' => 'A4_MONITORING_RESIDUE_LAB_CART_IN_USE', 'plan' => $plan], self::FAILURE);
        }

        if (! $this->historicalResidueProvenance($cartId, $customerId, $ledger, $cart)) {
            return $this->emit('error', ['error_code' => 'A4_MONITORING_RESIDUE_PROVENANCE_UNCONFIRMED', 'plan' => $plan], self::FAILURE);
        }

        if (! $execute) {
            return $this->emit('ok', $plan + ['reason' => 'DRY_RUN', 'would_delete' => ['monitoring_carts' => 1, 'monitoring_cart_items' => 1]]);
        }

        $lock = Cache::lock('akubica-a4-continuity-cart:'.$namespace, 30);
        if (! $lock->get()) {
            return $this->emit('error', ['error_code' => 'A4_FIXTURE_LOCK_UNAVAILABLE'], self::FAILURE);
        }

        try {
            return DB::transaction(function () use ($cartId, $cartItemId, $plan) {
                $itemsDeleted = CartItem::query()->where('cart_id', $cartId)->whereKey($cartItemId)->delete();
                $cartsDeleted = (int) Cart::query()->whereKey($cartId)->delete();

                return $this->emit('ok', $plan + [
                    'reason' => 'MONITORING_RESIDUE_REMOVED',
                    'deleted' => ['monitoring_carts' => $cartsDeleted, 'monitoring_cart_items' => $itemsDeleted],
                ]);
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>|null  $ledger
     */
    private function historicalResidueProvenance(int $cartId, int $customerId, ?array $ledger, ?Cart $cart = null): bool
    {
        if ($ledger !== null && (int) ($ledger['monitoring_cart_id'] ?? 0) === $cartId) {
            return true;
        }
        if ($cart !== null && $this->monitoringCartBelongsToWindow($cart, $customerId, $ledger)) {
            return true;
        }

        return $this->windowContinuityLinksQuery($customerId, [$cartId])->exists();
    }

    private function cleanupCategory(?array $ledger): array
    {
        if ($ledger === null || ($ledger['category_created'] ?? false) !== true) {
            return [0, 'CATEGORY_PROVENANCE_UNCONFIRMED'];
        }
        $categoryId = (int) ($ledger['category_id'] ?? 0);
        $category = LaboratoryTestCategory::query()->withTrashed()->find($categoryId);
        if ($category === null) {
            return [0, 'CATEGORY_ALREADY_ABSENT'];
        }
        if (LaboratoryTest::query()->withTrashed()->where('laboratory_test_category_id', $categoryId)->exists()) {
            return [0, 'SHARED_REFERENCE_PRESERVED'];
        }

        $deleted = LaboratoryTestCategory::query()->withTrashed()->whereKey($categoryId)->forceDelete();

        return [$deleted, $deleted > 0 ? 'WINDOW_CREATED_NO_REFERENCES' : 'CATEGORY_ALREADY_ABSENT'];
    }

    private function ledgerPath(string $namespace): string
    {
        return AkubicaUatFixtureContract::storagePath('a4-continuity-cart.json', $namespace);
    }

    private function readLedger(string $namespace): ?array
    {
        $disk = Storage::disk(AkubicaUatFixtureContract::STORAGE_DISK);
        $path = $this->ledgerPath($namespace);
        if (! $disk->exists($path)) {
            return null;
        }
        $ledger = json_decode((string) $disk->get($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($ledger) || ($ledger['namespace'] ?? null) !== $namespace) {
            throw new \DomainException('A4_FIXTURE_LEDGER_INVALID');
        }

        return $ledger;
    }

    private function writeLedger(string $namespace, array $ledger): void
    {
        $disk = Storage::disk(AkubicaUatFixtureContract::STORAGE_DISK);
        $path = $this->ledgerPath($namespace);
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(6));
        $disk->put($temporary, json_encode($ledger, JSON_THROW_ON_ERROR));
        $disk->move($temporary, $path);
    }

    private function testGdaId(string $namespace): string
    {
        return self::TEST_PREFIX.$namespace.'-OLAB';
    }

    private function emit(string $status, array $payload, int $code = self::SUCCESS): int
    {
        $this->line(json_encode(['status' => $status] + $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $code;
    }
}
