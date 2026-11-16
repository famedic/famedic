<?php

// RC2 B19/B20/B21 (D3): medical/laboratory cart isolation with valid products and readback of both carts.
// Profile per annex: "Bearer + máquina" (registered consumer). Leo keeps its narrow medical scope (no widening).
use App\Models\LaboratoryCartItem;
use App\Models\MedicalServiceCart;
use App\Models\MedicalServiceCartItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture;

function b19Headers(array $issued, bool $idempotent = true): array
{
    $headers = [
        'Authorization' => 'Bearer '.$issued['token']->plainTextToken,
        'X-Synthetic-Machine-Credential' => $issued['machine']->plainTextToken,
    ];

    return $idempotent ? $headers + ['Idempotency-Key' => (string) Str::uuid()] : $headers;
}

function b19Membership(): array
{
    return ['service_line' => 'medical_services', 'item_type' => 'membership', 'membership_plan_code' => 'basic_annual', 'quantity' => 1];
}

/** @return array{0: array, 1: array} medical and laboratory readback */
function b19Readback($test, array $issued): array
{
    $medical = $test->getJson('/api/v1/cart?service_line=medical_services', b19Headers($issued, false))->assertOk()->json('data');
    $laboratory = $test->getJson('/api/v1/cart?brand=olab', b19Headers($issued, false))->assertOk()->json('data');

    return [$medical, $laboratory];
}

beforeEach(fn () => SyntheticConsumerRegistryFixture::reset());

test('B19 adding a valid membership changes only medical_services (laboratory readback unchanged)', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $native = SyntheticConsumerRegistryFixture::issue($user, 'native');
    addOlabCartItem($user);
    [, $labBefore] = b19Readback($this, $native);

    $this->postJson('/api/v1/cart/items', b19Membership(), b19Headers($native))->assertStatus(201)
        ->assertJsonPath('data.items.0.item_type', 'membership');

    [$medical, $labAfter] = b19Readback($this, $native);
    expect($medical['totals']['item_count'])->toBe(1)
        ->and($labAfter)->toEqual($labBefore);
});

test('B20 adding a valid laboratory study changes only that brand cart (medical readback unchanged)', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $native = SyntheticConsumerRegistryFixture::issue($user, 'native');
    $this->postJson('/api/v1/cart/items', b19Membership(), b19Headers($native))->assertStatus(201);
    [$medicalBefore, $labBefore] = b19Readback($this, $native);
    $test = createOlabTest(['requires_appointment' => false]);

    $this->postJson('/api/v1/cart/items', ['brand' => 'olab', 'laboratory_test_id' => $test->id, 'quantity' => 1], b19Headers($native))
        ->assertStatus(201)
        ->assertJsonPath('data.item.laboratory_test_id', $test->id);

    [$medicalAfter, $labAfter] = b19Readback($this, $native);
    expect($medicalAfter)->toEqual($medicalBefore)
        ->and(count($labAfter['items']))->toBe(count($labBefore['items']) + 1);
});

test('B21 removing an item of one line keeps the other line items and totals', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $native = SyntheticConsumerRegistryFixture::issue($user, 'native');
    $medicalItemId = $this->postJson('/api/v1/cart/items', b19Membership(), b19Headers($native))->assertStatus(201)->json('data.items.0.id');
    $test = createOlabTest(['requires_appointment' => false]);
    $labItemId = $this->postJson('/api/v1/cart/items', ['brand' => 'olab', 'laboratory_test_id' => $test->id, 'quantity' => 1], b19Headers($native))
        ->assertStatus(201)->json('data.item.id');

    [$medicalBefore, $labBefore] = b19Readback($this, $native);
    $this->deleteJson("/api/v1/cart/items/{$labItemId}?brand=olab", [], b19Headers($native))->assertOk();
    [$medicalMid, $labMid] = b19Readback($this, $native);
    expect($medicalMid)->toEqual($medicalBefore)->and($labMid['items'])->toHaveCount(count($labBefore['items']) - 1);

    $this->deleteJson("/api/v1/cart/items/{$medicalItemId}?service_line=medical_services", [], b19Headers($native))->assertOk();
    [$medicalAfter, $labAfter] = b19Readback($this, $native);
    expect($medicalAfter['totals']['item_count'])->toBe(0)->and($labAfter)->toEqual($labMid);
});

test('B21 id collision: a laboratory item whose id equals a foreign medical item id is removed (not blocked, not cross-deleted)', function () {
    $victim = User::factory()->withRegularCustomer()->create();
    $owner = User::factory()->withRegularCustomer()->create();
    $native = SyntheticConsumerRegistryFixture::issue($owner, 'native');

    app(\App\Services\Api\V1\MedicalServiceCartService::class)->addBasicPlan($victim->customer);
    $foreignMedical = MedicalServiceCartItem::query()->sole();
    $test = createOlabTest(['requires_appointment' => false]);
    DB::table('laboratory_cart_items')->insert([
        'id' => $foreignMedical->id, 'customer_id' => $owner->customer->id, 'laboratory_test_id' => $test->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->deleteJson("/api/v1/cart/items/{$foreignMedical->id}", [], b19Headers($native))->assertOk()
        ->assertJsonPath('data.removed_item_id', $foreignMedical->id);

    expect(LaboratoryCartItem::query()->whereKey($foreignMedical->id)->exists())->toBeFalse()
        ->and(MedicalServiceCartItem::query()->whereKey($foreignMedical->id)->exists())->toBeTrue();
});

test('B21 id collision inside the same customer requires a discriminator (typed 409, zero effects)', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $native = SyntheticConsumerRegistryFixture::issue($user, 'native');
    $medicalItemId = $this->postJson('/api/v1/cart/items', b19Membership(), b19Headers($native))->assertStatus(201)->json('data.items.0.id');
    $test = createOlabTest(['requires_appointment' => false]);
    DB::table('laboratory_cart_items')->insert([
        'id' => $medicalItemId, 'customer_id' => $user->customer->id, 'laboratory_test_id' => $test->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->deleteJson("/api/v1/cart/items/{$medicalItemId}", [], b19Headers($native))
        ->assertStatus(409)->assertJsonPath('error.code', 'CART_SERVICE_LINE_MISMATCH');
    expect(LaboratoryCartItem::query()->whereKey($medicalItemId)->exists())->toBeTrue()
        ->and(MedicalServiceCartItem::query()->whereKey($medicalItemId)->exists())->toBeTrue();

    $this->deleteJson("/api/v1/cart/items/{$medicalItemId}?brand=olab", [], b19Headers($native))->assertOk();
    expect(LaboratoryCartItem::query()->whereKey($medicalItemId)->exists())->toBeFalse()
        ->and(MedicalServiceCartItem::query()->whereKey($medicalItemId)->exists())->toBeTrue();
});

test('B21 discriminator mismatch is typed and has zero effects', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $native = SyntheticConsumerRegistryFixture::issue($user, 'native');
    $medicalItemId = $this->postJson('/api/v1/cart/items', b19Membership(), b19Headers($native))->assertStatus(201)->json('data.items.0.id');

    $this->deleteJson("/api/v1/cart/items/{$medicalItemId}?brand=olab", [], b19Headers($native))->assertStatus(404)->assertJsonPath('error.code', 'CART_ITEM_NOT_FOUND');
    $this->deleteJson("/api/v1/cart/items/{$medicalItemId}?service_line=laboratory", [], b19Headers($native))->assertStatus(409)->assertJsonPath('error.code', 'CART_SERVICE_LINE_MISMATCH');
    $this->deleteJson("/api/v1/cart/items/999999?service_line=medical_services", [], b19Headers($native))->assertStatus(404);
    expect(MedicalServiceCartItem::query()->whereKey($medicalItemId)->exists())->toBeTrue();
});

test('B19-B21 leo profile is not widened: no laboratory cart read or write, medical scope only', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $leo = SyntheticConsumerRegistryFixture::issue($user, 'leo', ['memberships:read', 'cart:read', 'checkout:prepare', 'cart:membership:write']);
    $test = createOlabTest(['requires_appointment' => false]);
    addOlabCartItem($user, $test);
    $labItemId = LaboratoryCartItem::query()->sole()->id;

    $this->postJson('/api/v1/cart/items', b19Membership(), b19Headers($leo))->assertStatus(201);
    $this->getJson('/api/v1/cart?brand=olab', b19Headers($leo, false))->assertStatus(403)->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
    $this->postJson('/api/v1/cart/items', ['brand' => 'olab', 'laboratory_test_id' => createOlabTest()->id, 'quantity' => 1], b19Headers($leo))
        ->assertStatus(403)->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
    $this->deleteJson("/api/v1/cart/items/{$labItemId}", [], b19Headers($leo))->assertStatus(403);

    expect(LaboratoryCartItem::query()->count())->toBe(1)
        ->and(MedicalServiceCart::query()->where('customer_id', $user->customer->id)->first()->items()->count())->toBe(1);
});
