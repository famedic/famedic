<?php

// RC2 R01-R05: authorization per route for leo vs native, ownership and results/invoices protection. LOCAL, MySQL 8.
// Batch 2 · A1 (contract rev7 2b6672e6): D5 is superseded by rev7 L07/L09/L12/L13b/L18. The fourth column is now the
// status leo gets WITH the literal grant: 200 (own data), 404 (unknown order 999999, ownership soft deny) or 403.
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture;

const RC2_R_ROUTES_V2 = [
    // [method, uri, literal capability, leo status with the literal grant (rev7)]
    // Batch 2 · A3: rev7 enables Leo on the step-up, secure-link and invoice-request operations
    // (x-consumer-allowlist ["leo","native"], x-leo-implementation-status BATCH_2; rows L10 results, L14 invoices,
    // L13 invoice request/status). They now pass consumer authorization and answer with a rev7-listed status
    // for this empty-body call on unknown order 999999 with the A3 flags off: 503 FEATURE_DISABLED (step-up request),
    // 422 VALIDATION_ERROR (empty verify/secure-link/store body) or 404 ORDER_NOT_FOUND (status). Never 403.
    ['GET', '/api/v1/orders', 'api:operation:api:v1:orders:index', 200],
    ['GET', '/api/v1/orders/results', 'api:operation:api:v1:orders:results:index', 200],
    ['GET', '/api/v1/orders/invoices', 'api:operation:api:v1:orders:invoices:index', 200],
    ['GET', '/api/v1/orders/999999/products', 'api:operation:api:v1:orders:products', 404],
    ['GET', '/api/v1/orders/999999/status', 'api:operation:api:v1:orders:status', 404],
    ['PUT', '/api/v1/orders/999999/cancel', 'api:operation:api:v1:orders:cancel', 403],
    ['GET', '/api/v1/orders/999999/results', 'api:operation:api:v1:orders:results', 404],
    ['GET', '/api/v1/orders/999999/results/download', 'api:operation:api:v1:orders:results:download', 403],
    ['POST', '/api/v1/orders/999999/results/step-up/request', 'api:operation:api:v1:orders:results:step-up:request', 503],
    ['POST', '/api/v1/orders/999999/results/step-up/verify', 'api:operation:api:v1:orders:results:step-up:verify', 422],
    ['POST', '/api/v1/orders/999999/results/secure-link', 'api:operation:api:v1:orders:results:secure-link', 422],
    ['GET', '/api/v1/orders/999999/invoices', 'api:operation:api:v1:orders:invoices', 404],
    ['GET', '/api/v1/orders/999999/invoices/1/download', 'api:operation:api:v1:orders:invoices:download', 403],
    ['POST', '/api/v1/orders/999999/invoices/1/step-up/request', 'api:operation:api:v1:orders:invoices:step-up:request', 503],
    ['POST', '/api/v1/orders/999999/invoices/1/step-up/verify', 'api:operation:api:v1:orders:invoices:step-up:verify', 422],
    ['POST', '/api/v1/orders/999999/invoices/1/secure-link', 'api:operation:api:v1:orders:invoices:secure-link', 422],
    ['GET', '/api/v1/orders/999999/invoice-request/status', 'api:operation:api:v1:orders:invoice-request:status', 404],
    ['POST', '/api/v1/orders/999999/invoice-request', 'api:operation:api:v1:orders:invoice-request:store', 422],
    ['GET', '/api/v1/user/family', 'api:operation:api:v1:user:family', 200],
    ['GET', '/api/v1/user/tax-profiles', 'api:operation:api:v1:user:tax-profiles', 200],
    ['GET', '/api/v1/laboratory-appointments/requirements?brand=olab', 'api:operation:api:v1:laboratory-appointments:requirements', 403],
    ['GET', '/api/v1/laboratory-appointments', 'api:operation:api:v1:laboratory-appointments:index', 403],
    ['POST', '/api/v1/laboratory-appointments', 'api:operation:api:v1:laboratory-appointments:store', 403],
    ['DELETE', '/api/v1/laboratory-appointments/999999', 'api:operation:api:v1:laboratory-appointments:destroy', 403],
];

function rc2rHeaders(array $issued): array
{
    return [
        'Authorization' => 'Bearer '.$issued['token']->plainTextToken,
        'X-Synthetic-Machine-Credential' => $issued['machine']->plainTextToken,
        'Idempotency-Key' => (string) Str::uuid(),
    ];
}

function rc2rPurchase(Customer $customer): LaboratoryPurchase
{
    return LaboratoryPurchase::query()->create([
        'customer_id' => $customer->id,
        'brand' => LaboratoryBrand::OLAB,
        'gda_order_id' => 'GDA-RC2R-'.Str::random(8),
        'gda_consecutivo' => random_int(100000, 999999),
        'name' => 'Sintetico', 'paternal_lastname' => 'Rc2', 'maternal_lastname' => 'Qa',
        'phone' => '5500000000', 'phone_country' => 'MX', 'birth_date' => '1990-01-01', 'gender' => Gender::MALE,
        'street' => 'Calle', 'number' => '1', 'neighborhood' => 'Centro', 'state' => 'CDMX', 'city' => 'CDMX', 'zipcode' => '01000',
        'total_cents' => 35000,
    ]);
}

beforeEach(fn () => SyntheticConsumerRegistryFixture::reset());

test('R-A leo holding every literal grant reaches exactly the rev7 operations; downloads, appointments and native-only routes 403', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $leo = SyntheticConsumerRegistryFixture::issue($user, 'leo', array_column(RC2_R_ROUTES_V2, 2));

    foreach (RC2_R_ROUTES_V2 as [$method, $uri, , $expected]) {
        $response = $this->json($method, $uri, [], rc2rHeaders($leo));
        expect($response->status())->toBe($expected, "$method $uri");
        if ($expected === 403) {
            expect($response->json('error.code'))->toBe('CONSUMER_NOT_AUTHORIZED', "$method $uri");
        }
        if ($expected === 404) {
            expect($response->json('error.code'))->toBe('ORDER_NOT_FOUND', "$method $uri");
        }
        if ($expected === 503) {
            expect($response->json('error.code'))->toBe('FEATURE_DISABLED', "$method $uri");
        }
        if ($expected === 422) {
            expect($response->json('error.code'))->toBe('VALIDATION_ERROR', "$method $uri");
        }
    }
});

test('R01 leo lists only its own orders; another customer order never appears', function () {
    $owner = User::factory()->withRegularCustomer()->create();
    $foreign = rc2rPurchase($owner->customer);
    $user = User::factory()->withRegularCustomer()->create();
    $own = rc2rPurchase($user->customer);
    $leo = SyntheticConsumerRegistryFixture::issue($user, 'leo', ['api:operation:api:v1:orders:index']);

    $ids = collect($this->getJson('/api/v1/orders', rc2rHeaders($leo))->assertOk()->json('data.orders'))->pluck('id')->all();

    expect($ids)->toContain($own->id)->not->toContain($foreign->id);
});

test('R01 native (Bearer + machine) reads own order status; foreign order is soft-denied', function () {
    $owner = User::factory()->withRegularCustomer()->create();
    $foreign = rc2rPurchase($owner->customer);
    $user = User::factory()->withRegularCustomer()->create();
    $own = rc2rPurchase($user->customer);
    $native = SyntheticConsumerRegistryFixture::issue($user, 'native');

    $this->getJson("/api/v1/orders/{$own->id}/status", rc2rHeaders($native))->assertOk();
    expect($this->getJson("/api/v1/orders/{$foreign->id}/status", rc2rHeaders($native))->status())->toBeIn([403, 404]);
});

test('R02/R03 native keeps results/invoices metadata reads; leo with the same literal grants gets the minimal rev7 metadata (L09/L12)', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $native = SyntheticConsumerRegistryFixture::issue($user, 'native');
    $this->getJson('/api/v1/orders/results', rc2rHeaders($native))->assertOk();
    $this->getJson('/api/v1/orders/invoices', rc2rHeaders($native))->assertOk();

    $other = User::factory()->withRegularCustomer()->create();
    $leo = SyntheticConsumerRegistryFixture::issue($other, 'leo', [
        'api:operation:api:v1:orders:results:index', 'api:operation:api:v1:orders:results',
        'api:operation:api:v1:orders:invoices:index', 'api:operation:api:v1:orders:invoices',
    ]);
    foreach (['/api/v1/orders/results' => 'results', '/api/v1/orders/invoices' => 'invoices'] as $uri => $key) {
        $this->getJson($uri, rc2rHeaders($leo))->assertOk()->assertJsonPath("data.{$key}", []);
    }
});

test('R04 leo reads only its own family data', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $leo = SyntheticConsumerRegistryFixture::issue($user, 'leo', ['api:operation:api:v1:user:family']);

    $this->getJson('/api/v1/user/family', rc2rHeaders($leo))->assertOk()->assertJsonPath('success', true);
});

test('R-E PUT /orders/{id}/cancel: native without literal 403; native with literal 503 FEATURE_DISABLED; leo 403', function () {
    $make = function (string $kind, array $caps) {
        return SyntheticConsumerRegistryFixture::issue(User::factory()->withRegularCustomer()->create(), $kind, $caps);
    };
    $this->putJson('/api/v1/orders/1/cancel', [], rc2rHeaders($make('native', ['api:operation:api:v1:orders:index'])))->assertStatus(403)->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
    $this->putJson('/api/v1/orders/1/cancel', [], rc2rHeaders($make('native', ['api:operation:api:v1:orders:cancel'])))->assertStatus(503)->assertJsonPath('error.code', 'FEATURE_DISABLED');
    $this->putJson('/api/v1/orders/1/cancel', [], rc2rHeaders($make('leo', ['api:operation:api:v1:orders:cancel'])))->assertStatus(403)->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
});
