<?php

use App\Console\Commands\MaterializeAkubicaUatOrdersCommand as OrdersCmd;
use App\Console\Commands\MaterializeAkubicaUatR04R05FixturesCommand as FamilyCmd;
use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Enums\LaboratoryBrand;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\Customer;
use App\Models\FamilyAccount;
use App\Models\Invoice;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryTest;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Api\V1\LaboratoryInvoiceSupport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

const INT005_NS = 'akubica-uat-batch2-a4-v1';

function int005Manifest(bool $withDocuments = true): array
{
    Storage::fake(AkubicaUatFixtureContract::STORAGE_DISK);
    $primary = Customer::factory()->withRegularAccount()->create(['user_id' => User::factory()->create()->id]);
    $foreign = Customer::factory()->withRegularAccount()->create(['user_id' => User::factory()->create()->id]);
    // LaboratoryTest is Scout-searchable: never reach Algolia from tests (same guard as the base materializer).
    $test = LaboratoryTest::withoutSyncingToSearch(fn () => LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB->value, 'name' => '[UAT] estudio olab']));
    AkubicaUatFixtureManifest::query()->create([
        'namespace' => INT005_NS, 'fixture_version' => 1, 'status' => 'active', 'expires_at' => now()->addDays(7),
        'metadata' => ['ids' => ['customers' => ['primary' => $primary->id, 'foreign' => $foreign->id], 'tests' => ['olab' => $test->id], 'store' => []]],
    ]);
    if ($withDocuments) {
        foreach (OrdersCmd::INT005_DOCUMENTS as $document) {
            Storage::disk(AkubicaUatFixtureContract::STORAGE_DISK)->put(AkubicaUatFixtureContract::storagePath($document, INT005_NS), '[UAT] synthetic');
        }
    }

    return [$primary, $foreign, $test];
}

function int005Run(string $action, array $extra = []): array
{
    $args = array_merge(['--namespace' => INT005_NS, '--profile' => 'int005'], $extra);
    if ($action !== 'dry-run') {
        $args['--'.$action] = true;
        $args['--confirm'] ??= INT005_NS;
    }
    $code = Artisan::call('akubica:uat-fixtures-orders', $args);
    $lines = array_values(array_filter(explode("\n", trim(Artisan::output()))));

    return [$code, json_decode((string) end($lines), true)];
}

function int005Family(): void
{
    Artisan::call('akubica:uat-fixtures-r04r05', ['--apply' => true, '--family-only' => true, '--namespace' => INT005_NS, '--confirm' => INT005_NS]);
}

function int005SyntheticOrder(int $customerId, string $gdaOrderId): LaboratoryPurchase
{
    return LaboratoryPurchase::query()->create([
        'customer_id' => $customerId, 'brand' => LaboratoryBrand::OLAB->value, 'gda_order_id' => $gdaOrderId,
        'name' => '[UAT]', 'paternal_lastname' => 'FAMEDIC', 'maternal_lastname' => 'OTRO', 'phone' => '5500000000', 'phone_country' => 'MX',
        'birth_date' => '1990-01-01', 'gender' => 1, 'street' => 'unrelated', 'number' => '1', 'neighborhood' => 'UAT',
        'state' => 'Nuevo Leon', 'city' => 'Monterrey', 'zipcode' => '64000', 'total_cents' => 0,
    ]);
}

function int005Orders()
{
    return LaboratoryPurchase::query()->withTrashed()->where('gda_order_id', 'like', strtoupper(INT005_NS).'-INT005-%');
}

test('INT-005 dry-run is the default, writes nothing and reports the plan and preconditions', function () {
    int005Manifest();
    [$code, $out] = int005Run('dry-run');

    expect($code)->toBe(0)
        ->and($out['action'])->toBe('dry-run')
        ->and($out['profile'])->toBe('int005')
        ->and($out['would_create'])->toBe(['own_result_invoice', 'own_invoiceable', 'own_family', 'foreign_result_invoice'])
        ->and($out['preconditions']['family_present'])->toBeFalse()
        ->and($out['preconditions']['test_present'])->toBeTrue()
        ->and($out['preconditions']['documents_present'])->toBeTrue()
        ->and($out['effects'])->toMatchArray(['orders' => 4, 'invoices' => 2, 'storage_writes' => 0, 'external_calls' => 0, 'payments' => 0, 'sms' => 0])
        ->and(int005Orders()->count())->toBe(0)
        ->and(Invoice::query()->count())->toBe(0);
});

test('INT-005 apply fails closed without the synthetic family, the olab test or the stored documents', function () {
    int005Manifest(withDocuments: false);
    [$code, $out] = int005Run('apply');
    expect($code)->toBe(1)->and($out['error_code'])->toBe('UAT_INT005_FAMILY_MISSING');

    int005Family();
    [$code, $out] = int005Run('apply');
    expect($code)->toBe(1)->and($out['error_code'])->toBe('UAT_INT005_DOCUMENTS_MISSING')
        ->and(int005Orders()->count())->toBe(0);
});

test('INT-005 guards: namespace, confirmation, profile, option combination and environment', function () {
    int005Manifest();
    int005Family();

    expect(int005Run('apply', ['--namespace' => 'akubica-uat-v1', '--confirm' => 'akubica-uat-v1'])[0])->toBe(1)
        ->and(int005Run('apply', ['--confirm' => 'otro'])[0])->toBe(1)
        ->and(int005Run('apply', ['--profile' => 'base'])[1]['error_code'])->toBe('UAT_ORDER_PROFILE_NOT_ALLOWED')
        ->and(int005Run('apply', ['--reset' => true])[0])->toBe(1);

    $this->app->detectEnvironment(fn () => 'production');
    expect(int005Run('apply')[1]['error_code'])->toBe('UAT_ENVIRONMENT_NOT_ALLOWED');
    $this->app->detectEnvironment(fn () => 'qa');
    expect(int005Run('apply')[1]['error_code'])->toBe('UAT_ENVIRONMENT_NOT_ALLOWED')
        ->and(int005Orders()->count())->toBe(0);
});

test('INT-005 apply creates the four synthetic orders, is idempotent and never creates payments', function () {
    [$primary, $foreign, $test] = int005Manifest();
    int005Family();
    $family = FamilyAccount::query()->where('customer_id', $primary->id)->where('name', FamilyCmd::FAMILY_NAME.' '.INT005_NS)->firstOrFail();
    $transactions = Transaction::query()->count();

    [$code, $out] = int005Run('apply');
    expect($code)->toBe(0)->and(collect($out['changes'])->pluck('op')->unique()->all())->toBe(['created']);

    [$code, $again] = int005Run('apply');
    expect($code)->toBe(0)->and(collect($again['changes'])->pluck('op')->unique()->all())->toBe(['kept'])
        ->and(collect($again['changes'])->pluck('id')->all())->toBe(collect($out['changes'])->pluck('id')->all());

    $key = fn (string $suffix) => LaboratoryPurchase::query()->where('gda_order_id', strtoupper(INT005_NS).'-INT005-'.$suffix)->firstOrFail();
    $own = $key('OWN-RESULT-INVOICE');
    $invoiceable = $key('OWN-INVOICEABLE');
    $familyOrder = $key('OWN-FAMILY');
    $foreignOrder = $key('FOREIGN-RESULT-INVOICE');
    $support = app(LaboratoryInvoiceSupport::class);

    expect(int005Orders()->count())->toBe(4)
        ->and(LaboratoryPurchaseItem::query()->whereIn('laboratory_purchase_id', int005Orders()->pluck('id'))->count())->toBe(4)
        ->and(LaboratoryPurchaseItem::query()->whereIn('laboratory_purchase_id', int005Orders()->pluck('id'))->pluck('laboratory_test_id')->unique()->all())->toBe([$test->id])
        ->and($own->customer_id)->toBe($primary->id)
        ->and($own->results)->toBe(AkubicaUatFixtureContract::storagePath('results/result-ready.pdf', INT005_NS))
        ->and($own->invoice)->not->toBeNull()
        ->and(localizedDate($own->created_at)->format('Y-m'))->toBe(localizedDate(now())->format('Y-m'))
        ->and(localizedDate($own->invoice->completed_at)->format('Y-m'))->toBe(localizedDate(now())->format('Y-m'))
        ->and($invoiceable->invoice)->toBeNull()
        ->and($invoiceable->invoiceRequest)->toBeNull()
        ->and($familyOrder->name)->toBe($family->name)
        ->and($familyOrder->paternal_lastname)->toBe($family->paternal_lastname)
        ->and($familyOrder->maternal_lastname)->toBe($family->maternal_lastname)
        ->and($familyOrder->customer_id)->toBe($primary->id)
        ->and($foreignOrder->customer_id)->toBe($foreign->id)
        ->and($foreignOrder->invoice)->not->toBeNull()
        ->and(Invoice::query()->count())->toBe(2)
        ->and(InvoiceRequest::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe($transactions);

    if (now()->copy()->setTimezone(config('app.timezone'))->isSameDay(localizedDate(now())->endOfMonth())) {
        expect($support->canRequestInvoice($invoiceable))->toBeIn([true, false]);
    } else {
        expect($support->canRequestInvoice($invoiceable))->toBeTrue()
            ->and($out['post']['own_invoiceable']['can_request_invoice'])->toBeTrue();
    }
    expect($out['post']['own_result_invoice']['can_request_invoice'])->toBeFalse()
        ->and($out['post']['foreign_result_invoice']['customer_role'])->toBe('foreign');
});

test('INT-005 orders are scoped to their owner customer (ownership negative for the foreign order)', function () {
    // OrderController and OrderDocumentDownloadController resolve every order through $customer->laboratoryPurchases().
    // The HTTP variant (as in the R01 test of this file's sibling) currently returns 403 in this local harness at 9af5dfc6
    // for both R01 and INT-005 (OBS-PEST-ARNES / INT-031, PR #97); the ownership scope itself is asserted here.
    [$primary, $foreign] = int005Manifest();
    int005Family();
    int005Run('apply');
    $key = fn (string $suffix) => LaboratoryPurchase::query()->where('gda_order_id', strtoupper(INT005_NS).'-INT005-'.$suffix)->value('id');

    $ownIds = $primary->fresh()->laboratoryPurchases()->pluck('id')->all();
    $foreignIds = $foreign->fresh()->laboratoryPurchases()->pluck('id')->all();

    expect($ownIds)->toContain($key('OWN-RESULT-INVOICE'), $key('OWN-INVOICEABLE'), $key('OWN-FAMILY'))
        ->and($ownIds)->not->toContain($key('FOREIGN-RESULT-INVOICE'))
        ->and($foreignIds)->toBe([$key('FOREIGN-RESULT-INVOICE')])
        ->and($primary->fresh()->laboratoryPurchases()->whereKey($key('FOREIGN-RESULT-INVOICE'))->exists())->toBeFalse();
});

test('INT-005 reset removes only INT-005 rows (including invoice requests made in the window) and keeps R01 and unrelated data', function () {
    [$primary] = int005Manifest();
    int005Family();
    Artisan::call('akubica:uat-fixtures-orders', ['--apply' => true, '--namespace' => INT005_NS, '--confirm' => INT005_NS]);
    $unrelated = int005SyntheticOrder($primary->id, 'UNRELATED-ORDER-1');
    int005Run('apply');
    $invoiceable = LaboratoryPurchase::query()->where('gda_order_id', strtoupper(INT005_NS).'-INT005-OWN-INVOICEABLE')->firstOrFail();
    InvoiceRequest::query()->create([
        'invoice_requestable_type' => LaboratoryPurchase::class, 'invoice_requestable_id' => $invoiceable->id,
        'name' => '[UAT] Razon Fiscal', 'rfc' => 'XAXX010101000', 'zipcode' => '64000', 'tax_regime' => '601', 'cfdi_use' => 'G03',
        'fiscal_certificate' => AkubicaUatFixtureContract::storagePath('tax/fiscal-certificate.pdf', INT005_NS),
    ]);

    [$code, $out] = int005Run('reset');

    expect($code)->toBe(0)
        ->and(collect($out['changes'])->pluck('op')->unique()->all())->toBe(['deleted'])
        ->and(collect($out['changes'])->pluck('invoice_requests')->flatten()->count())->toBe(1)
        ->and(collect($out['post'])->filter()->all())->toBe([])
        ->and(int005Orders()->count())->toBe(0)
        ->and(Invoice::query()->withTrashed()->count())->toBe(0)
        ->and(InvoiceRequest::query()->withTrashed()->count())->toBe(0)
        ->and(LaboratoryPurchase::query()->where('street', INT005_NS.':rc2-r01-orders')->count())->toBe(2)
        ->and(LaboratoryPurchase::query()->whereKey($unrelated->id)->exists())->toBeTrue()
        ->and(FamilyAccount::query()->where('customer_id', $primary->id)->count())->toBe(1);
    foreach (OrdersCmd::INT005_DOCUMENTS as $document) {
        expect(Storage::disk(AkubicaUatFixtureContract::STORAGE_DISK)->exists(AkubicaUatFixtureContract::storagePath($document, INT005_NS)))->toBeTrue();
    }

    [$code, $again] = int005Run('reset');
    expect($code)->toBe(0)->and($again['changes'])->toBe([]);
});

test('INT-005 refuses a natural key owned by another customer (no writes, no deletes)', function () {
    int005Manifest();
    int005Family();
    $intruder = Customer::factory()->withRegularAccount()->create(['user_id' => User::factory()->create()->id]);
    int005SyntheticOrder($intruder->id, strtoupper(INT005_NS).'-INT005-OWN-FAMILY');

    expect(int005Run('dry-run')[1]['preconditions']['collisions'])->toBe(['own_family'])
        ->and(int005Run('apply')[1]['error_code'])->toBe('UAT_INT005_NATURAL_KEY_COLLISION')
        ->and(int005Run('reset')[1]['error_code'])->toBe('UAT_INT005_NATURAL_KEY_COLLISION')
        ->and(int005Orders()->count())->toBe(1);
});