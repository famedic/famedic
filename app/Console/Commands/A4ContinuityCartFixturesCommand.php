<?php

namespace App\Console\Commands;

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
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

    private const CATEGORY = '[UAT] RC2 A4 continuidad laboratorio';

    protected $signature = 'akubica:a4-continuity-cart
                            {--dry-run : Muestra el plan sin escribir}
                            {--apply : Crea el ítem OLAB temporal}
                            {--cleanup : Elimina sólo el ítem OLAB temporal propio}
                            {--namespace= : Namespace dedicado obligatorio}
                            {--confirm= : Confirmación exacta para apply/cleanup}';

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
        if ($apply && $cleanup) {
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
            return $this->emit('ok', ['mode' => 'cleanup', 'deleted' => ['laboratory_cart_items' => 0, 'laboratory_tests' => 0, 'categories' => 0], 'reason' => 'ALREADY_COMPLETED']);
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
            $categoryDeleted = $this->cleanupCategory($ledger);
            if ($ledger !== null) {
                $this->writeLedger($namespace, $ledger + ['status' => 'completed', 'resources_absent' => true, 'category_deleted' => $categoryDeleted > 0]);
            }
            return $this->emit('ok', ['mode' => 'cleanup', 'deleted' => ['laboratory_cart_items' => 0, 'laboratory_tests' => 0, 'categories' => $categoryDeleted], 'reason' => 'RESOURCE_NOT_CREATED']);
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
            $categoryDeleted = $this->cleanupCategory($ledger);
            $this->writeLedger($namespace, $ledger + ['status' => 'completed', 'resources_absent' => true, 'category_deleted' => $categoryDeleted > 0]);

            return $this->emit('ok', ['mode' => 'cleanup', 'deleted' => ['laboratory_cart_items' => $itemDeleted, 'laboratory_tests' => $testDeleted, 'categories' => $categoryDeleted], 'reason' => 'PARTIAL_RESOURCE_RECOVERED']);
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
            $categoryDeleted = $this->cleanupCategory($ledger);
            $categoryReason = $categoryDeleted > 0 ? 'WINDOW_CREATED_NO_REFERENCES' : 'SHARED_REFERENCE_PRESERVED_OR_ABSENT';
        }
        if ($ledger !== null) {
            $this->writeLedger($namespace, $ledger + [
                'status' => 'completed',
                'resources_absent' => ! LaboratoryCartItem::query()->withTrashed()->whereKey($existingItem->id)->exists()
                    && ! LaboratoryTest::query()->withTrashed()->whereKey($existingTest->id)->exists(),
                'category_deleted' => $categoryDeleted > 0,
                'category_reason' => $categoryReason,
            ]);
        }

        return $this->emit('ok', ['mode' => 'cleanup', 'deleted' => ['laboratory_cart_items' => $itemDeleted, 'laboratory_tests' => $testDeleted, 'categories' => $categoryDeleted], 'category_reason' => $categoryReason, 'resource_id' => $existingItem->id]);
    }

    private function cleanupCategory(?array $ledger): int
    {
        if ($ledger === null || ($ledger['category_created'] ?? false) !== true) {
            return 0;
        }
        $categoryId = (int) ($ledger['category_id'] ?? 0);
        $category = LaboratoryTestCategory::query()->withTrashed()->find($categoryId);
        if ($category === null || LaboratoryTest::query()->withTrashed()->where('laboratory_test_category_id', $categoryId)->exists()) {
            return 0;
        }

        return LaboratoryTestCategory::query()->withTrashed()->whereKey($categoryId)->forceDelete();
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
