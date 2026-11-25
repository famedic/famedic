<?php

namespace App\Console\Commands;

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\LaboratoryPurchase;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Namespace-scoped, non-purchasing order fixtures for R01/R01b. */
class MaterializeAkubicaUatOrdersCommand extends Command
{
    protected $signature = 'akubica:uat-fixtures-orders
                            {--dry-run : Plan without writes (default)}
                            {--apply : Create or reconcile own/foreign orders}
                            {--reset : Remove only this namespace marker}
                            {--confirm= : Exact namespace confirmation}
                            {--namespace=akubica-uat-batch2-a4-v1 : Dedicated namespace}';

    protected $description = 'Prepare isolated synthetic own/foreign order fixtures for R01/R01b.';

    public function handle(): int
    {
        if (! app()->environment((array) config('akubica_uat.allowed_environments', ['testing', 'staging']))
            || app()->environment('production')) {
            return $this->failCommand('UAT_ENVIRONMENT_NOT_ALLOWED');
        }

        $namespace = (string) $this->option('namespace');
        $apply = (bool) $this->option('apply');
        $reset = (bool) $this->option('reset');
        if ($namespace !== AkubicaUatFixtureContract::DEDICATED_NAMESPACE || ($apply && $reset)
            || (($apply || $reset) && (string) $this->option('confirm') !== $namespace)) {
            return $this->failCommand('UAT_ORDER_FIXTURE_GUARD');
        }
        $manifest = AkubicaUatFixtureManifest::query()->where('namespace', $namespace)->latest('id')->first();
        if (! $manifest || $manifest->status !== AkubicaUatFixtureContract::STATUS_ACTIVE) {
            return $this->failCommand('UAT_FIXTURES_NOT_ACTIVE');
        }
        $ids = (array) Arr::get((array) ($manifest->ids ?? $manifest->metadata ?? []), 'ids', []);
        $customers = [
            'own' => (int) Arr::get($ids, 'customers.primary', 0),
            'foreign' => (int) Arr::get($ids, 'customers.foreign', 0),
        ];
        if (in_array(0, $customers, true) || $customers['own'] === $customers['foreign']) {
            return $this->failCommand('UAT_ORDER_CUSTOMERS_INVALID');
        }
        $marker = $namespace.':rc2-r01-orders';
        $keys = $this->keys($namespace);
        $existing = LaboratoryPurchase::query()->withTrashed()->whereIn('gda_order_id', $keys)->get();
        if (! $apply && ! $reset) {
            return $this->ok(['action' => 'dry-run', 'namespace' => $namespace, 'marker' => $marker,
                'customers' => $customers, 'existing' => $existing->map(fn ($o) => ['id' => $o->id, 'customer_id' => $o->customer_id])->values()]);
        }
        $changes = [];
        DB::transaction(function () use ($apply, $reset, $namespace, $customers, &$changes): void {
            if ($reset) {
                $orders = LaboratoryPurchase::query()->withTrashed()->whereIn('gda_order_id', $this->keys($namespace))->get();
                foreach ($orders as $order) {
                    if (! in_array((int) $order->customer_id, $customers, true)) {
                        throw new \RuntimeException('UAT_ORDER_OWNERSHIP_MISMATCH');
                    }
                    $changes[] = ['table' => 'laboratory_purchases', 'id' => $order->id, 'op' => 'deleted'];
                    $order->forceDelete();
                }
                return;
            }
            foreach ($customers as $role => $customerId) {
                $key = strtoupper($namespace).'-R01-'.strtoupper($role);
                $order = LaboratoryPurchase::query()->withTrashed()->firstOrNew(['gda_order_id' => $key]);
                if ($order->exists && (int) $order->customer_id !== $customerId) {
                    throw new \RuntimeException('UAT_ORDER_NATURAL_KEY_COLLISION');
                }
                $user = $order->customer?->user;
                $order->fill([
                    'customer_id' => $customerId, 'brand' => LaboratoryBrand::OLAB->value,
                    'name' => '[UAT R01]', 'paternal_lastname' => 'FAMEDIC', 'maternal_lastname' => $role,
                    'phone' => $user?->phone ?? '5500000000', 'phone_country' => $user?->phone_country ?? 'MX',
                    'birth_date' => '1990-01-01', 'gender' => Gender::MALE->value,
                    'street' => $namespace.':rc2-r01-orders', 'number' => '1', 'neighborhood' => 'UAT',
                    'state' => 'Nuevo Leon', 'city' => 'Monterrey', 'zipcode' => '64000',
                    'total_cents' => 0, 'results' => null, 'completed_at' => null,
                ]);
                if ($order->trashed()) $order->restore();
                $order->save();
                $changes[] = ['table' => 'laboratory_purchases', 'id' => $order->id, 'customer_role' => $role, 'op' => $order->wasRecentlyCreated ? 'created' : 'kept'];
            }
        });
        return $this->ok(['action' => $apply ? 'apply' : 'reset', 'namespace' => $namespace, 'marker' => $namespace.':rc2-r01-orders', 'changes' => $changes]);
    }

    private function keys(string $namespace): array
    {
        return [strtoupper($namespace).'-R01-OWN', strtoupper($namespace).'-R01-FOREIGN'];
    }

    private function ok(array $payload): int
    {
        $this->line(json_encode(['status' => 'ok'] + $payload, JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    private function failCommand(string $code): int
    {
        $this->line(json_encode(['status' => 'error', 'error_code' => $code]));
        return self::FAILURE;
    }
}
