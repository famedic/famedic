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

        $category = LaboratoryTestCategory::query()->withTrashed()->firstOrCreate(['name' => self::CATEGORY.' · '.$namespace]);
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
        if ($existingTest === null || $existingItem === null) {
            return $this->emit('ok', ['mode' => 'cleanup', 'deleted' => ['laboratory_cart_items' => 0, 'laboratory_tests' => 0], 'reason' => 'RESOURCE_NOT_CREATED']);
        }
        if ((int) $existingItem->customer_id !== $customerId || ! str_starts_with((string) $existingTest->gda_id, self::TEST_PREFIX.$namespace.'-')) {
            return $this->emit('error', ['error_code' => 'A4_FIXTURE_RESOURCE_NOT_OWNED'], self::FAILURE);
        }
        $itemDeleted = LaboratoryCartItem::query()->withTrashed()->whereKey($existingItem->id)->forceDelete();
        $testDeleted = 0;
        if (! LaboratoryCartItem::query()->withTrashed()->where('laboratory_test_id', $existingTest->id)->exists()) {
            $testDeleted = LaboratoryTest::query()->withTrashed()->whereKey($existingTest->id)->forceDelete();
        }

        return $this->emit('ok', ['mode' => 'cleanup', 'deleted' => ['laboratory_cart_items' => $itemDeleted, 'laboratory_tests' => $testDeleted], 'resource_id' => $existingItem->id]);
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
