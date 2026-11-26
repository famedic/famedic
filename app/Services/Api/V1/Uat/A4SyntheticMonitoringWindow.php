<?php

namespace App\Services\Api\V1\Uat;

use App\Console\Commands\A4ContinuityCartFixturesCommand;
use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\LaboratoryCartItem;
use Illuminate\Support\Facades\Storage;

/**
 * Synthetic A4-window only. Production laboratory sync/issue behaviour is unchanged
 * when the customer is not membership_none of the dedicated A4 namespace fixture.
 *
 * Authoritative ledger lives in akubica_uat_fixture_manifests.metadata
 * (same MySQL transaction as cart_item creation). The JSON file is a replica
 * written after commit. A file write inside DB::transaction is not transactional
 * with MySQL, so the file is never the source of truth.
 */
final class A4SyntheticMonitoringWindow
{
    public const LEDGER_METADATA_KEY = 'a4_continuity_cart';

    public const UNACCREDITED_CONTENT = 'A4_SYNTHETIC_SYNC_UNACCREDITED_CONTENT';

    public const CONTEXT_MISMATCH = 'A4_SYNTHETIC_SYNC_CONTEXT_MISMATCH';

    public const TRACE_MISSING = 'A4_MONITORING_TRACE_MISSING';

    public const PERSISTENCE_FAILED = 'A4_FIXTURE_LEDGER_PERSISTENCE_FAILED';

    public const REPLICA_PENDING = 'A4_FIXTURE_LEDGER_REPLICA_PENDING';

    private static ?array $capturedLedger = null;

    private static bool $failNextDatabasePersist = false;

    private static bool $failNextReplica = false;

    private static mixed $replicaDisk = null;

    private static ?\Closure $beforeLockedSync = null;

    public function __construct(
        private readonly AkubicaUatFixtureManifest $manifest,
        private readonly Customer $customer,
    ) {
    }

    public static function clearTestHooks(): void
    {
        self::$capturedLedger = null;
        self::$failNextDatabasePersist = false;
        self::$failNextReplica = false;
        self::$replicaDisk = null;
        self::$beforeLockedSync = null;
    }

    public static function resetCapture(): void
    {
        self::$capturedLedger = null;
    }

    public static function failNextDatabasePersist(): void
    {
        self::$failNextDatabasePersist = true;
    }

    public static function failNextReplica(): void
    {
        self::$failNextReplica = true;
    }

    public static function useReplicaDisk(object $disk): void
    {
        self::$replicaDisk = $disk;
    }

    /**
     * Test-only hook: runs immediately before the locked transaction, to simulate
     * a content change after an early inspection and before modification.
     */
    public static function injectBeforeLockedSync(\Closure $callback): void
    {
        self::$beforeLockedSync = $callback;
    }

    public function runInjectedContentChange(): void
    {
        $callback = self::$beforeLockedSync;
        self::$beforeLockedSync = null;
        if ($callback !== null) {
            $callback();
        }
    }

    public static function capturedLedger(): ?array
    {
        return self::$capturedLedger;
    }

    public static function forCustomer(Customer $customer): ?self
    {
        if (! app()->environment((array) config('akubica_uat.allowed_environments', ['testing', 'staging']))) {
            return null;
        }

        $namespace = AkubicaUatFixtureContract::DEDICATED_NAMESPACE;
        $manifest = AkubicaUatFixtureManifest::query()
            ->where('namespace', $namespace)
            ->where('status', AkubicaUatFixtureContract::STATUS_ACTIVE)
            ->first();
        if ($manifest === null) {
            return null;
        }

        $membershipNone = (int) data_get($manifest->metadata, 'ids.customers.membership_none', 0);
        if ($membershipNone < 1 || $membershipNone !== (int) $customer->id) {
            return null;
        }

        return new self($manifest, $customer);
    }

    /**
     * Lock and validate the exact cart that is about to be synchronised, then
     * return. Caller must delete immediately in the same transaction.
     */
    public function assertExactCartSafeToSync(Cart $cart): void
    {
        $locked = Cart::query()->whereKey($cart->id)->lockForUpdate()->first();
        if ($locked === null) {
            throw new \DomainException(self::CONTEXT_MISMATCH);
        }
        if ((int) $locked->user_id !== (int) $this->customer->user_id
            || $locked->type !== MonitoringCartType::Lab
            || $locked->status !== MonitoringCartStatus::Active) {
            throw new \DomainException(self::CONTEXT_MISMATCH);
        }

        $items = CartItem::query()->where('cart_id', $locked->id)->lockForUpdate()->orderBy('id')->get();
        if ($items->isEmpty()) {
            return;
        }

        $ledger = self::readAuthoritativeLedger(AkubicaUatFixtureContract::DEDICATED_NAMESPACE);
        foreach ($items as $item) {
            if (! self::itemMatchesLedger($item, $ledger, (int) $this->customer->id)) {
                throw new \DomainException(self::UNACCREDITED_CONTENT);
            }
        }
    }

    /**
     * Empty laboratory-item branch: lock every Active Lab cart that would be
     * cleared, validate each (including carts that currently have no items),
     * then delete immediately in the same transaction.
     */
    public function assertAndClearLockedActiveCarts(int $userId): void
    {
        $carts = Cart::query()
            ->where('user_id', $userId)
            ->where('type', MonitoringCartType::Lab)
            ->where('status', MonitoringCartStatus::Active)
            ->lockForUpdate()
            ->get();

        foreach ($carts as $cart) {
            $this->assertExactCartSafeToSync($cart);
        }
        foreach ($carts as $cart) {
            CartItem::query()->where('cart_id', $cart->id)->delete();
            $cart->delete();
        }
    }

    /**
     * Record IDs actually returned by create(), for window lab items only.
     * Does not query cart_items by product_id and does not adopt vanished replacements.
     *
     * @param  list<array{0: CartItem, 1: LaboratoryCartItem}>  $created
     */
    public function recordCreatedItems(Customer $customer, Cart $cart, array $created): void
    {
        if ((int) $customer->id !== (int) $this->customer->id
            || (int) $cart->user_id !== (int) $customer->user_id
            || $cart->type !== MonitoringCartType::Lab
            || $cart->status !== MonitoringCartStatus::Active) {
            throw new \DomainException(self::CONTEXT_MISMATCH);
        }

        $expectedGda = A4ContinuityCartFixturesCommand::TEST_PREFIX.AkubicaUatFixtureContract::DEDICATED_NAMESPACE.'-OLAB';
        $ids = [];
        $testId = null;
        $windowLabSeen = false;
        foreach ($created as $pair) {
            /** @var CartItem $item */
            $item = $pair[0];
            /** @var LaboratoryCartItem $labItem */
            $labItem = $pair[1];
            if ((int) $item->cart_id !== (int) $cart->id) {
                throw new \DomainException(self::CONTEXT_MISMATCH);
            }
            if ((int) $labItem->customer_id !== (int) $customer->id) {
                continue;
            }
            $test = $labItem->laboratoryTest;
            if ($test === null || (string) $test->gda_id !== $expectedGda) {
                continue;
            }
            $windowLabSeen = true;
            if ((string) $item->product_id !== (string) $test->id) {
                continue;
            }
            $ids[] = (int) $item->id;
            $testId = (int) $test->id;
        }

        if ($windowLabSeen && ($ids === [] || $testId === null)) {
            throw new \DomainException(self::TRACE_MISSING);
        }
        if ($ids === []) {
            return;
        }

        $existing = self::readAuthoritativeLedger(AkubicaUatFixtureContract::DEDICATED_NAMESPACE) ?? [];
        $status = ($existing['status'] ?? 'active') === 'completed' ? 'active' : ($existing['status'] ?? 'active');
        $ledger = array_merge($existing, [
            'namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
            'status' => $status,
            'customer_id' => (int) $customer->id,
            'test_id' => $testId,
            'monitoring_cart_id' => (int) $cart->id,
            'monitoring_cart_item_ids' => $ids,
        ]);
        self::persistLedger(AkubicaUatFixtureContract::DEDICATED_NAMESPACE, $ledger);
        self::$capturedLedger = $ledger;
    }

    /**
     * @param  array<string, mixed>|null  $ledger
     */
    public static function itemMatchesLedger(CartItem $item, ?array $ledger, int $customerId): bool
    {
        if ($ledger === null) {
            return false;
        }
        if ((int) ($ledger['customer_id'] ?? 0) !== $customerId) {
            return false;
        }
        $ids = $ledger['monitoring_cart_item_ids'] ?? [];
        if (! is_array($ids) || ! in_array((int) $item->id, array_map('intval', $ids), true)) {
            return false;
        }
        $cartId = (int) ($ledger['monitoring_cart_id'] ?? 0);
        if ($cartId < 1 || (int) $item->cart_id !== $cartId) {
            return false;
        }
        $testId = (int) ($ledger['test_id'] ?? 0);
        if ($testId < 1 || (string) $item->product_id !== (string) $testId) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function readAuthoritativeLedger(string $namespace): ?array
    {
        if ($namespace !== AkubicaUatFixtureContract::DEDICATED_NAMESPACE) {
            return null;
        }

        $manifest = AkubicaUatFixtureManifest::query()->where('namespace', $namespace)->first();
        $fromDb = is_array($manifest?->metadata[self::LEDGER_METADATA_KEY] ?? null)
            ? $manifest->metadata[self::LEDGER_METADATA_KEY]
            : null;
        if (is_array($fromDb) && ($fromDb['namespace'] ?? null) === $namespace) {
            return $fromDb;
        }

        return self::readReplica($namespace);
    }

    /**
     * @param  array<string, mixed>  $ledger
     */
    public static function persistLedger(string $namespace, array $ledger): void
    {
        if ($namespace !== AkubicaUatFixtureContract::DEDICATED_NAMESPACE || ($ledger['namespace'] ?? null) !== $namespace) {
            throw new \DomainException('A4_FIXTURE_LEDGER_INVALID');
        }
        if (self::$failNextDatabasePersist) {
            self::$failNextDatabasePersist = false;
            throw new \DomainException(self::PERSISTENCE_FAILED);
        }

        $manifest = AkubicaUatFixtureManifest::query()
            ->where('namespace', $namespace)
            ->lockForUpdate()
            ->first();
        if ($manifest === null) {
            throw new \DomainException('A4_FIXTURE_NAMESPACE_NOT_ACTIVE');
        }

        $metadata = $manifest->metadata ?? [];
        $metadata[self::LEDGER_METADATA_KEY] = $ledger;
        $manifest->forceFill(['metadata' => $metadata])->save();
    }

    public static function writeReplica(string $namespace): void
    {
        if (self::$failNextReplica) {
            self::$failNextReplica = false;
            throw new \DomainException(self::REPLICA_PENDING);
        }

        $ledger = self::readAuthoritativeLedger($namespace);
        if ($ledger === null) {
            return;
        }

        $disk = self::$replicaDisk ?? Storage::disk(AkubicaUatFixtureContract::STORAGE_DISK);
        $path = AkubicaUatFixtureContract::storagePath('a4-continuity-cart.json', $namespace);
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(6));
        $payload = json_encode($ledger, JSON_THROW_ON_ERROR);

        try {
            $put = $disk->put($temporary, $payload);
            if ($put !== true) {
                throw new \DomainException(self::REPLICA_PENDING);
            }
            $moved = $disk->move($temporary, $path);
            if ($moved !== true) {
                throw new \DomainException(self::REPLICA_PENDING);
            }
        } catch (\DomainException $exception) {
            self::deleteOwnTemporary($disk, $temporary);
            if ($exception->getMessage() === self::REPLICA_PENDING) {
                throw $exception;
            }
            throw new \DomainException(self::REPLICA_PENDING, previous: $exception);
        } catch (\Throwable $exception) {
            self::deleteOwnTemporary($disk, $temporary);
            throw new \DomainException(self::REPLICA_PENDING, previous: $exception);
        }
    }

    private static function deleteOwnTemporary(object $disk, string $temporary): void
    {
        $base = AkubicaUatFixtureContract::storagePath('a4-continuity-cart.json', AkubicaUatFixtureContract::DEDICATED_NAMESPACE);
        if ($temporary === '' || ! str_starts_with($temporary, $base.'.tmp.')) {
            return;
        }
        try {
            if ($disk->exists($temporary)) {
                $disk->delete($temporary);
            }
        } catch (\Throwable) {
            // Best-effort cleanup of our own temp object only.
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function readReplica(string $namespace): ?array
    {
        $disk = Storage::disk(AkubicaUatFixtureContract::STORAGE_DISK);
        $path = AkubicaUatFixtureContract::storagePath('a4-continuity-cart.json', $namespace);
        if (! $disk->exists($path)) {
            return null;
        }
        $ledger = json_decode((string) $disk->get($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($ledger) || ($ledger['namespace'] ?? null) !== $namespace) {
            throw new \DomainException('A4_FIXTURE_LEDGER_INVALID');
        }

        return $ledger;
    }
}
