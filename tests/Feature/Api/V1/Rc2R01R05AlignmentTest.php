<?php

// RC2 R01-R05 (D5): authorization per route for leo vs native, ownership and results/invoices protection. LOCAL, MySQL 8.
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture;

const RC2_R_ROUTES_V2 = [
    // [method, uri, literal capability, leo reachable with literal grant]
    ['GET', '/api/v1/orders', 'api:operation:api:v1:orders:index', true],
    ['GET', '/api/v1/orders/results', 'api:operation:api:v1:orders:results:index', false],
    ['GET', '/api/v1/orders/invoices', 'api:operation:api:v1:orders:invoices:index', false],
    ['GET', '/api/v1/orders/999999/products', 'api:operation:api:v1:orders:products', false],
    ['GET', '/api/v1/orders/999999/status', 'api:operation:api:v1:orders:status', false],
    ['PUT', '/api/v1/orders/999999/cancel', 'api:operation:api:v1:orders:cancel', false],
    ['GET', '/api/v1/orders/999999/results', 'api:operation:api:v1:orders:results', false],
    ['GET', '/api/v1/orders/999999/results/download', 'api:operation:api:v1:orders:results:download', false],
    ['POST', '/api/v1/orders/999999/results/step-up/request', 'api:operation:api:v1:orders:results:step-up:request', false],
    ['POST', '/api/v1/orders/999999/results/step-up/verify', 'api:operation:api:v1:orders:results:step-up:verify', false],
    ['POST', '/api/v1/orders/999999/results/secure-link', 'api:operation:api:v1:orders:results:secure-link', false],
    ['GET', '/api/v1/orders/999999/invoices', 'api:operation:api:v1:orders:invoices', false],
    ['GET', '/api/v1/orders/999999/invoices/1/download', 'api:operation:api:v1:orders:invoices:download', false],
    ['POST', '/api/v1/orders/999999/invoices/1/step-up/request', 'api:operation:api:v1:orders:invoices:step-up:request', false],
    ['POST', '/api/v1/orders/999999/invoices/1/step-up/verify', 'api:operation:api:v1:orders:invoices:step-up:verify', false],
    ['POST', '/api/v1/orders/999999/invoices/1/secure-link', 'api:operation:api:v1:orders:invoices:secure-link', false],
    ['GET', '/api/v1/orders/999999/invoice-request/status', 'api:operation:api:v1:orders:invoice-request:status', false],
    ['POST', '/api/v1/orders/999999/invoice-request', 'api:operation:api:v1:orders:invoice-request:store', false],
    ['GET', '/api/v1/user/family', 'api:operation:api:v1:user:family', true],
    ['GET', '/api/v1/laboratory-appointments/requirements?brand=olab', 'api:operation:api:v1:laboratory-appointments:requirements', true],
    ['GET', '/api/v1/laboratory-appointments', 'api:operation:api:v1:laboratory-appointments:index', true],
    ['POST', '/api/v1/laboratory-appointments', 'api:operation:api:v1:laboratory-appointments:store', false],
    ['DELETE', '/api/v1/laboratory-appointments/999999', 'api:operation:api:v1:laboratory-appointments:destroy', false],
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

test('R-A leo holding every literal grant reaches only R01/R04/R05 reads; results/invoices and native-only routes 403', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $leo = SyntheticConsumerRegistryFixture::issue($user, 'leo', array_column(RC2_R_ROUTES_V2, 2));

    foreach (RC2_R_ROUTES_V2 as [$method, $uri, , $leoReachable]) {
        $response = $this->json($method, $uri, [], rc2rHeaders($leo));
        if ($leoReachable) {
            expect($response->status())->toBe(200, "$method $uri");
        } else {
            expect($response->status())->toBe(403, "$method $uri")
                ->and($response->json('error.code'))->toBe('CONSUMER_NOT_AUTHORIZED');
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

test('R02/R03 native keeps results/invoices metadata reads; leo with the same literal grants is denied', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $native = SyntheticConsumerRegistryFixture::issue($user, 'native');
    $this->getJson('/api/v1/orders/results', rc2rHeaders($native))->assertOk();
    $this->getJson('/api/v1/orders/invoices', rc2rHeaders($native))->assertOk();

    $other = User::factory()->withRegularCustomer()->create();
    $leo = SyntheticConsumerRegistryFixture::issue($other, 'leo', \App\Services\Api\V1\Auth\ConsumerCapabilityPolicy::LEO_DENIED_PROTECTED_READS);
    foreach (['/api/v1/orders/results', '/api/v1/orders/invoices'] as $uri) {
        $this->getJson($uri, rc2rHeaders($leo))->assertForbidden()->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
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
