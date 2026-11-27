<?php

namespace App\Console\Commands;

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\FamilyAccount;
use App\Models\Invoice;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryTest;
use App\Support\Api\V1\LaboratoryInvoiceSupport;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Namespace-scoped, non-purchasing order fixtures.
 *
 * - profile r01 (default, unchanged): own/foreign orders for R01/R01b.
 * - profile int005 (INT-005): own order with result PDF + invoice of the current month, own invoiceable order
 *   (no invoice, no request), own order in the name of the synthetic family member (M08b) and a foreign order with
 *   result + invoice for ownership negatives. Reuses the synthetic documents already stored by the dedicated
 *   manifest (no storage writes), requires the synthetic family from akubica:uat-fixtures-r04r05 --family-only,
 *   runs without model events (no observers / external calls) and never creates payments or transactions.
 */
class MaterializeAkubicaUatOrdersCommand extends Command
{
    protected $signature = 'akubica:uat-fixtures-orders
                            {--dry-run : Plan without writes (default)}
                            {--apply : Create or reconcile own/foreign orders}
                            {--reset : Remove only this namespace marker}
                            {--confirm= : Exact namespace confirmation}
                            {--namespace=akubica-uat-batch2-a4-v1 : Dedicated namespace}
                            {--profile=r01 : r01 (R01/R01b) or int005 (INT-005: family order, result+invoice of the month, invoiceable, foreign)}';

    protected $description = 'Prepare isolated synthetic order fixtures (R01/R01b or INT-005) in the dedicated namespace.';

    public const INT005_PROFILE = 'int005';

    /** @var array<int, string> */
    public const INT005_DOCUMENTS = [
        'results/result-ready.pdf',
        'invoices/invoice-ready.pdf',
        'invoices/invoice-ready.xml',
        'results/foreign-order.pdf',
        'invoices/foreign-order.pdf',
        'invoices/foreign-order.xml',
    ];

    public function handle(): int
    {
        if (! app()->environment((array) config('akubica_uat.allowed_environments', ['testing', 'staging']))
            || app()->environment('production')) {
            return $this->failCommand('UAT_ENVIRONMENT_NOT_ALLOWED');
        }

        $namespace = (string) $this->option('namespace');
        $apply = (bool) $this->option('apply');
        $reset = (bool) $this->option('reset');
        $profile = (string) ($this->option('profile') ?: 'r01');
        if (! in_array($profile, ['r01', self::INT005_PROFILE], true)) {
            return $this->failCommand('UAT_ORDER_PROFILE_NOT_ALLOWED');
        }
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
        if ($profile === self::INT005_PROFILE) {
            return $this->handleInt005($namespace, $ids, $customers, $apply, $reset);
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

    /**
     * @param  array<string, mixed>  $ids
     * @param  array{own: int, foreign: int}  $customers
     */
    private function handleInt005(string $namespace, array $ids, array $customers, bool $apply, bool $reset): int
    {
        $marker = $namespace.':rc2-int005';
        $keys = $this->int005Keys($namespace);
        $family = $this->int005Family($namespace, $customers['own']);
        $test = $this->int005Test($ids);
        $documents = $this->int005Documents($namespace);
        $pre = $this->int005Snapshot($keys, $customers);
        $collisions = collect($pre)->filter(fn ($s) => $s !== null && $s['customer_role'] === 'other')->keys()->values()->all();

        if (! $apply && ! $reset) {
            return $this->ok([
                'action' => 'dry-run', 'profile' => self::INT005_PROFILE, 'namespace' => $namespace, 'marker' => $marker,
                'customers' => $customers,
                'preconditions' => [
                    'family_present' => $family !== null, 'family_id' => $family?->id,
                    'test_present' => $test !== null, 'test_id' => $test?->id,
                    'documents_present' => ! in_array(false, $documents, true), 'documents' => $documents,
                    'collisions' => $collisions,
                ],
                'would_create' => collect($pre)->filter(fn ($s) => $s === null)->keys()->values()->all(),
                'existing' => $pre,
                'effects' => ['orders' => 4, 'purchase_items' => 4, 'invoices' => 2, 'storage_writes' => 0,
                    'external_calls' => 0, 'payments' => 0, 'transactions' => 0, 'sms' => 0],
            ]);
        }

        if ($collisions !== []) {
            return $this->failCommand('UAT_INT005_NATURAL_KEY_COLLISION');
        }
        if ($apply && $family === null) {
            return $this->failCommand('UAT_INT005_FAMILY_MISSING');
        }
        if ($apply && $test === null) {
            return $this->failCommand('UAT_INT005_TEST_MISSING');
        }
        if ($apply && in_array(false, $documents, true)) {
            return $this->failCommand('UAT_INT005_DOCUMENTS_MISSING');
        }

        $changes = [];
        try {
            Model::withoutEvents(function () use ($apply, $namespace, $keys, $customers, $family, $test, $marker, &$changes): void {
                DB::transaction(function () use ($apply, $namespace, $keys, $customers, $family, $test, $marker, &$changes): void {
                    $apply
                        ? $this->int005Apply($namespace, $keys, $customers, $family, $test, $marker, $changes)
                        : $this->int005Reset($keys, $customers, $changes);
                });
            });
        } catch (\RuntimeException $exception) {
            if (str_starts_with($exception->getMessage(), 'UAT_')) {
                return $this->failCommand($exception->getMessage());
            }
            throw $exception;
        }

        return $this->ok([
            'action' => $apply ? 'apply' : 'reset', 'profile' => self::INT005_PROFILE, 'namespace' => $namespace,
            'marker' => $marker, 'changes' => $changes, 'pre' => $pre, 'post' => $this->int005Snapshot($keys, $customers),
        ]);
    }

    /** @return array<string, string> */
    private function int005Keys(string $namespace): array
    {
        $prefix = strtoupper($namespace).'-INT005-';

        return [
            'own_result_invoice' => $prefix.'OWN-RESULT-INVOICE',
            'own_invoiceable' => $prefix.'OWN-INVOICEABLE',
            'own_family' => $prefix.'OWN-FAMILY',
            'foreign_result_invoice' => $prefix.'FOREIGN-RESULT-INVOICE',
        ];
    }

    private function int005Family(string $namespace, int $ownCustomerId): ?FamilyAccount
    {
        $name = $namespace === AkubicaUatFixtureContract::NAMESPACE
            ? MaterializeAkubicaUatR04R05FixturesCommand::FAMILY_NAME
            : MaterializeAkubicaUatR04R05FixturesCommand::FAMILY_NAME.' '.$namespace;

        return FamilyAccount::query()
            ->where('customer_id', $ownCustomerId)
            ->where('name', $name)
            ->where('paternal_lastname', MaterializeAkubicaUatR04R05FixturesCommand::FAMILY_LASTNAME)
            ->where('maternal_lastname', MaterializeAkubicaUatR04R05FixturesCommand::FAMILY_LASTNAME_2)
            ->orderBy('id')
            ->first();
    }

    /** @param  array<string, mixed>  $ids */
    private function int005Test(array $ids): ?LaboratoryTest
    {
        $testId = (int) Arr::get($ids, 'tests.olab', 0);
        if ($testId <= 0) {
            return null;
        }
        $test = LaboratoryTest::query()->find($testId);
        $brand = $test?->brand instanceof LaboratoryBrand ? $test->brand->value : $test?->brand;

        return $test !== null && $brand === LaboratoryBrand::OLAB->value ? $test : null;
    }

    /** @return array<string, bool> */
    private function int005Documents(string $namespace): array
    {
        $disk = Storage::disk(AkubicaUatFixtureContract::STORAGE_DISK);

        return collect(self::INT005_DOCUMENTS)
            ->mapWithKeys(fn (string $document): array => [$document => $disk->exists(AkubicaUatFixtureContract::storagePath($document, $namespace))])
            ->all();
    }

    /**
     * @param  array<string, string>  $keys
     * @param  array{own: int, foreign: int}  $customers
     * @return array<string, array<string, mixed>|null>
     */
    private function int005Snapshot(array $keys, array $customers): array
    {
        $support = app(LaboratoryInvoiceSupport::class);
        $out = [];
        foreach ($keys as $slot => $key) {
            $order = LaboratoryPurchase::query()->withTrashed()->where('gda_order_id', $key)->first();
            if ($order === null) {
                $out[$slot] = null;

                continue;
            }
            $role = array_search((int) $order->customer_id, $customers, true);
            $out[$slot] = [
                'id' => $order->id,
                'customer_role' => $role === false ? 'other' : $role,
                'trashed' => $order->trashed(),
                'has_results' => filled($order->results),
                'items' => LaboratoryPurchaseItem::query()->withTrashed()->where('laboratory_purchase_id', $order->id)->count(),
                'invoice_id' => Invoice::query()->withTrashed()->where('invoiceable_type', LaboratoryPurchase::class)->where('invoiceable_id', $order->id)->value('id'),
                'invoice_requests' => InvoiceRequest::query()->withTrashed()->where('invoice_requestable_type', LaboratoryPurchase::class)->where('invoice_requestable_id', $order->id)->count(),
                'created_month' => localizedDate($order->created_at)->format('Y-m'),
                'current_month' => localizedDate(now())->format('Y-m'),
                'can_request_invoice' => $support->canRequestInvoice($order),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $keys
     * @param  array{own: int, foreign: int}  $customers
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function int005Apply(string $namespace, array $keys, array $customers, FamilyAccount $family, LaboratoryTest $test, string $marker, array &$changes): void
    {
        $path = fn (string $document): string => AkubicaUatFixtureContract::storagePath($document, $namespace);
        $titular = ['name' => '[UAT INT-005]', 'paternal_lastname' => 'FAMEDIC', 'maternal_lastname' => 'TITULAR', 'birth_date' => '1990-01-01', 'gender' => Gender::MALE->value];
        $definitions = [
            'own_result_invoice' => ['role' => 'own', 'patient' => $titular, 'results' => $path('results/result-ready.pdf'),
                'invoice' => [$path('invoices/invoice-ready.pdf'), $path('invoices/invoice-ready.xml')]],
            'own_invoiceable' => ['role' => 'own', 'patient' => $titular, 'results' => null, 'invoice' => null],
            'own_family' => ['role' => 'own', 'patient' => [
                'name' => $family->name, 'paternal_lastname' => $family->paternal_lastname, 'maternal_lastname' => $family->maternal_lastname,
                'birth_date' => $family->birth_date?->format('Y-m-d') ?? '2015-01-01', 'gender' => $family->gender?->value ?? Gender::MALE->value,
            ], 'results' => null, 'invoice' => null],
            'foreign_result_invoice' => ['role' => 'foreign', 'patient' => array_merge($titular, ['maternal_lastname' => 'AJENO']),
                'results' => $path('results/foreign-order.pdf'), 'invoice' => [$path('invoices/foreign-order.pdf'), $path('invoices/foreign-order.xml')]],
        ];

        foreach ($definitions as $slot => $definition) {
            $customerId = $customers[$definition['role']];
            $order = LaboratoryPurchase::query()->withTrashed()->where('gda_order_id', $keys[$slot])->first();
            if ($order !== null && (int) $order->customer_id !== $customerId) {
                throw new \RuntimeException('UAT_INT005_NATURAL_KEY_COLLISION');
            }
            $created = $order === null;
            if ($created) {
                $user = \App\Models\Customer::query()->find($customerId)?->user;
                $order = LaboratoryPurchase::query()->create(array_merge($definition['patient'], [
                    'customer_id' => $customerId, 'brand' => LaboratoryBrand::OLAB->value, 'gda_order_id' => $keys[$slot],
                    'phone' => $user?->phone ?? '5500000000', 'phone_country' => $user?->phone_country ?? 'MX',
                    'street' => $marker, 'number' => '1', 'neighborhood' => 'UAT', 'state' => 'Nuevo Leon', 'city' => 'Monterrey', 'zipcode' => '64000',
                    'total_cents' => (int) $test->famedic_price_cents, 'status' => 'completed',
                    'results' => $definition['results'], 'ready_at' => $definition['results'] ? now() : null, 'completed_at' => now(),
                ]));
            } elseif ($order->trashed()) {
                $order->restore();
            }

            $item = LaboratoryPurchaseItem::query()->withTrashed()->where('laboratory_purchase_id', $order->id)->first();
            if ($item === null) {
                $item = LaboratoryPurchaseItem::query()->create([
                    'laboratory_purchase_id' => $order->id, 'gda_id' => $test->gda_id, 'laboratory_test_id' => $test->id,
                    'name' => $test->name, 'indications' => $test->indications ?: '[UAT] indicaciones sinteticas',
                    'price_cents' => (int) $test->famedic_price_cents, 'feature_list' => $test->feature_list,
                ]);
            } elseif ($item->trashed()) {
                $item->restore();
            }

            $invoiceId = null;
            if ($definition['invoice'] !== null) {
                $invoice = Invoice::query()->withTrashed()->where('invoiceable_type', LaboratoryPurchase::class)->where('invoiceable_id', $order->id)->first();
                if ($invoice === null) {
                    $invoice = Invoice::query()->create([
                        'invoiceable_type' => LaboratoryPurchase::class, 'invoiceable_id' => $order->id,
                        'invoice' => $definition['invoice'][0], 'invoice_xml' => $definition['invoice'][1], 'completed_at' => now(),
                    ]);
                } elseif ($invoice->trashed()) {
                    $invoice->restore();
                }
                $invoiceId = $invoice->id;
            }

            $changes[] = ['slot' => $slot, 'table' => 'laboratory_purchases', 'id' => $order->id, 'customer_role' => $definition['role'],
                'item_id' => $item->id, 'invoice_id' => $invoiceId, 'op' => $created ? 'created' : 'kept'];
        }
    }

    /**
     * @param  array<string, string>  $keys
     * @param  array{own: int, foreign: int}  $customers
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function int005Reset(array $keys, array $customers, array &$changes): void
    {
        $orders = LaboratoryPurchase::query()->withTrashed()->whereIn('gda_order_id', array_values($keys))->get();
        foreach ($orders as $order) {
            if (! in_array((int) $order->customer_id, $customers, true)) {
                throw new \RuntimeException('UAT_INT005_OWNERSHIP_MISMATCH');
            }
        }
        foreach ($orders as $order) {
            $invoices = Invoice::query()->withTrashed()->where('invoiceable_type', LaboratoryPurchase::class)->where('invoiceable_id', $order->id)->pluck('id')->all();
            $requests = InvoiceRequest::query()->withTrashed()->where('invoice_requestable_type', LaboratoryPurchase::class)->where('invoice_requestable_id', $order->id)->pluck('id')->all();
            $items = LaboratoryPurchaseItem::query()->withTrashed()->where('laboratory_purchase_id', $order->id)->pluck('id')->all();
            Invoice::query()->withTrashed()->whereIn('id', $invoices)->forceDelete();
            InvoiceRequest::query()->withTrashed()->whereIn('id', $requests)->forceDelete();
            LaboratoryPurchaseItem::query()->withTrashed()->whereIn('id', $items)->forceDelete();
            $order->forceDelete();
            $changes[] = ['table' => 'laboratory_purchases', 'id' => $order->id, 'op' => 'deleted',
                'invoices' => $invoices, 'invoice_requests' => $requests, 'purchase_items' => $items];
        }
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
