<?php

// RC2 Batch 2 · A2 (contract rev7 2b6672e6, L06 / C1 / D-2): Leo laboratory cart bound to line laboratory and brand.
// cart:laboratory:read / cart:laboratory:write (exact literals), binding brand grant, ownership, medical/laboratory
// separation, server-side prices, idempotency, and the Leo laboratory checkout preparation without payment.
// LOCAL ONLY (MySQL harness). This is not STAGING_PASS and certifies nothing.
use App\Models\ApiConsumerDelegation;
use App\Models\LaboratoryCartItem;
use App\Models\MedicalServiceCart;
use App\Models\MedicalServiceCartItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Api\V1\Contract\Rev7Schema;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture;

const A2_READ = 'cart:laboratory:read';
const A2_WRITE = 'cart:laboratory:write';
const A2_LAB_CAPS = ['memberships:read', 'cart:read', 'checkout:prepare', A2_READ, A2_WRITE];

beforeEach(function () {
    SyntheticConsumerRegistryFixture::reset();
    config(['api_v1.idempotency.enabled' => true]);
});

function a2Headers(array $issued, bool $idempotent = false, ?string $key = null): array
{
    return ['Authorization' => 'Bearer '.$issued['token']->plainTextToken, 'X-Synthetic-Machine-Credential' => $issued['machine']->plainTextToken]
        + ($idempotent ? ['Idempotency-Key' => $key ?? (string) Str::uuid()] : []);
}

function a2Leo(array $caps = A2_LAB_CAPS, array $brands = ['olab'], ?User $user = null): array
{
    return SyntheticConsumerRegistryFixture::issue($user ?? User::factory()->withRegularCustomer()->create(), 'leo', $caps, $brands);
}

function a2Schema(array $json, string $component): void
{
    expect(Rev7Schema::errors($json, $component))->toBe([]);
}

function a2LabItem(User $user, \App\Models\LaboratoryTest $test): LaboratoryCartItem
{
    return LaboratoryCartItem::query()->create(['customer_id' => $user->customer->id, 'laboratory_test_id' => $test->id]);
}

function a2Membership(): array
{
    return ['service_line' => 'medical_services', 'item_type' => 'membership', 'membership_plan_code' => 'basic_annual', 'quantity' => 1];
}

function a2Forbidden($response): void
{
    $response->assertStatus(403)->assertJsonPath('success', false)->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
}

// ── Positive flows (rev7 schemas) ────────────────────────────────────

test('A2 leo adds, reads, totals and removes its own laboratory items with the rev7 shapes and server prices', function () {
    $leo = a2Leo();
    $test = createOlabTest(['requires_appointment' => false, 'famedic_price_cents' => 13700, 'public_price_cents' => 22000]);

    $added = $this->postJson('/api/v1/cart/items', ['laboratory_test_id' => $test->id, 'brand' => 'olab'], a2Headers($leo, true))
        ->assertStatus(201)->json();
    a2Schema($added, 'LaboratoryCartItemAddedResponse');
    expect($added['data']['item']['price_cents'])->toBe(13700)
        ->and($added['data']['cart']['subtotal_cents'])->toBe(22000)
        ->and($added['data']['cart']['discount_cents'])->toBe(8300)
        ->and($added['data']['cart']['total_cents'])->toBe(13700);
    $itemId = $added['data']['item']['id'];

    $cart = $this->getJson('/api/v1/cart?service_line=laboratory&brand=olab', a2Headers($leo))->assertOk()->json();
    a2Schema($cart, 'LaboratoryCartResponse');
    expect($cart['data']['brand'])->toBe('olab')->and($cart['data']['items'])->toHaveCount(1);

    $totals = $this->getJson('/api/v1/cart/totals?service_line=laboratory&brand=olab', a2Headers($leo))->assertOk()->json();
    a2Schema($totals, 'LaboratoryCartTotalsResponse');
    expect($totals['data']['items_count'])->toBe(1)->and($totals['data']['total_cents'])->toBe(13700);

    $removed = $this->deleteJson("/api/v1/cart/items/{$itemId}?service_line=laboratory&brand=olab", [], a2Headers($leo, true))->assertOk()->json();
    a2Schema($removed, 'LaboratoryCartItemRemovedResponse');
    expect($removed['data']['removed_item_id'])->toBe($itemId)
        ->and($removed['data']['cart']['items'])->toBe([])
        ->and(LaboratoryCartItem::query()->whereKey($itemId)->exists())->toBeFalse();
});

test('A2 leo laboratory checkout prepare has the rev7 Leo shape: minimized, no payment and no continuity (A4 pending)', function () {
    // Even with the medical continuity flags on, laboratory continuity is NOT simulated: it depends on A4.
    config(['akubica_connectors.continuity.enabled' => true, 'akubica_connectors.continuity.certified' => true]);
    $leo = a2Leo();
    a2LabItem($leo['user'], createOlabTest(['requires_appointment' => true]));

    $json = $this->getJson('/api/v1/checkout/prepare?service_line=laboratory&brand=olab', a2Headers($leo))->assertOk()->json();

    a2Schema($json, 'LeoLaboratoryCheckoutPrepareResponse');
    expect($json['data']['audience'])->toBe('leo')
        ->and($json['data']['service_line'])->toBe('laboratory')
        ->and($json['data']['assistant_can_pay'])->toBeFalse()
        ->and($json['data']['can_continue_on_platform'])->toBeTrue()
        ->and($json['data']['can_continue_to_payment_platform'])->toBeFalse()
        ->and($json['data']['requirements']['requires_appointment'])->toBeTrue()
        ->and($json['data']['warnings'])->toBeArray();
    foreach (['continuity_url', 'continuity_expires_at', 'contacts', 'addresses', 'payment_methods', 'coupon'] as $absent) {
        expect(array_key_exists($absent, $json['data']))->toBeFalse();
    }
});

test('A2 leo laboratory checkout prepare requires checkout:prepare and cart:laboratory:read', function () {
    a2Forbidden($this->getJson('/api/v1/checkout/prepare?service_line=laboratory&brand=olab', a2Headers(a2Leo(['cart:read', A2_READ]))));
    a2Forbidden($this->getJson('/api/v1/checkout/prepare?service_line=laboratory&brand=olab', a2Headers(a2Leo(['cart:read', 'checkout:prepare', A2_WRITE]))));
});

// ── Authentication, capability, delegation, brand grant ──────────────

test('A2 without authentication every laboratory operation is 401', function () {
    $this->getJson('/api/v1/cart?service_line=laboratory&brand=olab')->assertStatus(401);
    $this->getJson('/api/v1/cart/totals?service_line=laboratory&brand=olab')->assertStatus(401);
    $this->postJson('/api/v1/cart/items', ['laboratory_test_id' => createOlabTest()->id, 'brand' => 'olab'])->assertStatus(401);
    $this->deleteJson('/api/v1/cart/items/1?service_line=laboratory&brand=olab')->assertStatus(401);
    expect(LaboratoryCartItem::query()->count())->toBe(0);
});

test('A2 without the laboratory capability leo is refused before any effect', function () {
    $leo = a2Leo(['memberships:read', 'cart:read', 'checkout:prepare', 'cart:membership:write'], []);
    $item = a2LabItem($leo['user'], createOlabTest());

    a2Forbidden($this->getJson('/api/v1/cart?service_line=laboratory&brand=olab', a2Headers($leo)));
    a2Forbidden($this->getJson('/api/v1/cart/totals?service_line=laboratory&brand=olab', a2Headers($leo)));
    a2Forbidden($this->postJson('/api/v1/cart/items', ['laboratory_test_id' => createOlabTest()->id, 'brand' => 'olab'], a2Headers($leo, true)));
    a2Forbidden($this->deleteJson("/api/v1/cart/items/{$item->id}?service_line=laboratory&brand=olab", [], a2Headers($leo, true)));
    expect(LaboratoryCartItem::query()->pluck('id')->all())->toBe([$item->id]);
});

test('A2 cart:laboratory:read never authorizes writes', function () {
    $leo = a2Leo(['cart:read', A2_READ]);
    $item = a2LabItem($leo['user'], createOlabTest());

    $this->getJson('/api/v1/cart?service_line=laboratory&brand=olab', a2Headers($leo))->assertOk();
    a2Forbidden($this->postJson('/api/v1/cart/items', ['laboratory_test_id' => createOlabTest()->id, 'brand' => 'olab'], a2Headers($leo, true)));
    a2Forbidden($this->deleteJson("/api/v1/cart/items/{$item->id}?service_line=laboratory&brand=olab", [], a2Headers($leo, true)));
    expect(LaboratoryCartItem::query()->pluck('id')->all())->toBe([$item->id]);
});

test('A2 the delegation must grant the laboratory capability and be active', function () {
    $leo = a2Leo();
    $test = createOlabTest();
    ApiConsumerDelegation::query()->whereKey($leo['delegation']->id)->update(['capabilities' => json_encode(['cart:read', A2_READ])]);
    a2Forbidden($this->postJson('/api/v1/cart/items', ['laboratory_test_id' => $test->id, 'brand' => 'olab'], a2Headers($leo, true)));

    ApiConsumerDelegation::query()->whereKey($leo['delegation']->id)->update(['capabilities' => json_encode(A2_LAB_CAPS), 'enabled' => false]);
    a2Forbidden($this->getJson('/api/v1/cart?service_line=laboratory&brand=olab', a2Headers($leo)));
    expect(LaboratoryCartItem::query()->count())->toBe(0);
});

test('A2 B21x the binding does not grant the laboratory capability for another brand (403 without effect)', function () {
    $leo = a2Leo(A2_LAB_CAPS, ['olab']);
    $swiss = createOlabTest(['brand' => \App\Enums\LaboratoryBrand::SWISSLAB]);
    $swissItem = a2LabItem($leo['user'], $swiss);

    a2Forbidden($this->getJson('/api/v1/cart?service_line=laboratory&brand=swisslab', a2Headers($leo)));
    a2Forbidden($this->getJson('/api/v1/cart/totals?service_line=laboratory&brand=swisslab', a2Headers($leo)));
    a2Forbidden($this->getJson('/api/v1/checkout/prepare?service_line=laboratory&brand=swisslab', a2Headers($leo)));
    a2Forbidden($this->postJson('/api/v1/cart/items', ['laboratory_test_id' => createOlabTest(['brand' => \App\Enums\LaboratoryBrand::SWISSLAB])->id, 'brand' => 'swisslab'], a2Headers($leo, true)));
    a2Forbidden($this->deleteJson("/api/v1/cart/items/{$swissItem->id}?service_line=laboratory&brand=swisslab", [], a2Headers($leo, true)));
    expect(LaboratoryCartItem::query()->pluck('id')->all())->toBe([$swissItem->id]);
});

test('A2 a binding with the capabilities but without any brand grant is closed', function () {
    $leo = a2Leo(A2_LAB_CAPS, []);
    a2Forbidden($this->getJson('/api/v1/cart?service_line=laboratory&brand=olab', a2Headers($leo)));
    a2Forbidden($this->postJson('/api/v1/cart/items', ['laboratory_test_id' => createOlabTest()->id, 'brand' => 'olab'], a2Headers($leo, true)));
    expect(LaboratoryCartItem::query()->count())->toBe(0);
});

test('A2 the binding writer rejects a brand grant without a laboratory capability or with an unknown brand', function () {
    expect(fn () => a2Leo(['cart:read', 'cart:membership:write'], ['olab']))->toThrow(\DomainException::class);
    expect(fn () => a2Leo(A2_LAB_CAPS, ['farmacia']))->toThrow(\UnexpectedValueException::class);
});

test('A2 an invalid or missing brand is a validation error only after authorization', function () {
    $leo = a2Leo();
    $this->getJson('/api/v1/cart?service_line=laboratory&brand=nope', a2Headers($leo))->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    $this->getJson('/api/v1/cart?service_line=laboratory', a2Headers($leo))->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    a2Forbidden($this->getJson('/api/v1/cart?service_line=laboratory&brand=nope', a2Headers(a2Leo(['cart:read'], []))));
});

// ── Ownership, account isolation, line/brand crossing ────────────────

test('A2 leo cannot read or remove a foreign account laboratory item', function () {
    $leo = a2Leo();
    $other = User::factory()->withRegularCustomer()->create();
    $foreign = a2LabItem($other, createOlabTest());

    $cart = $this->getJson('/api/v1/cart?service_line=laboratory&brand=olab', a2Headers($leo))->assertOk()->json('data');
    expect($cart['items'])->toBe([]);
    a2Forbidden($this->deleteJson("/api/v1/cart/items/{$foreign->id}?service_line=laboratory&brand=olab", [], a2Headers($leo, true)));
    a2Forbidden($this->deleteJson('/api/v1/cart/items/999999?service_line=laboratory&brand=olab', [], a2Headers($leo, true)));
    expect(LaboratoryCartItem::query()->whereKey($foreign->id)->exists())->toBeTrue();
});

test('A2 brand crossing: an own item of another brand or a test of another brand has no effect', function () {
    $leo = a2Leo(A2_LAB_CAPS, ['olab', 'swisslab']);
    $swissItem = a2LabItem($leo['user'], createOlabTest(['brand' => \App\Enums\LaboratoryBrand::SWISSLAB]));
    $swissTest = createOlabTest(['brand' => \App\Enums\LaboratoryBrand::SWISSLAB]);

    a2Forbidden($this->deleteJson("/api/v1/cart/items/{$swissItem->id}?service_line=laboratory&brand=olab", [], a2Headers($leo, true)));
    $this->postJson('/api/v1/cart/items', ['laboratory_test_id' => $swissTest->id, 'brand' => 'olab'], a2Headers($leo, true))
        ->assertStatus(404)->assertJsonPath('error.code', 'LAB_TEST_NOT_FOUND');
    $olab = $this->getJson('/api/v1/cart?service_line=laboratory&brand=olab', a2Headers($leo))->assertOk()->json('data');
    expect($olab['items'])->toBe([])
        ->and(LaboratoryCartItem::query()->pluck('id')->all())->toBe([$swissItem->id]);
});

test('A2 B19-B21 the laboratory grant never touches the medical cart and the membership grant never touches laboratory', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $leo = a2Leo([...A2_LAB_CAPS, 'cart:membership:write'], ['olab'], $user);
    $medicalItemId = $this->postJson('/api/v1/cart/items', a2Membership(), a2Headers($leo, true))->assertStatus(201)->json('data.items.0.id');
    $medicalBefore = $this->getJson('/api/v1/cart?service_line=medical_services', a2Headers($leo))->assertOk()->json('data');

    // B20: adding a laboratory study changes only that brand cart.
    $labId = $this->postJson('/api/v1/cart/items', ['laboratory_test_id' => createOlabTest()->id, 'brand' => 'olab'], a2Headers($leo, true))
        ->assertStatus(201)->json('data.item.id');
    expect($this->getJson('/api/v1/cart?service_line=medical_services', a2Headers($leo))->json('data'))->toEqual($medicalBefore);

    // B21: a laboratory removal addressed to a medical id (no laboratory item with that id) has no effect on medical.
    $collision = $medicalItemId === $labId ? $medicalItemId + 5000 : $medicalItemId;
    a2Forbidden($this->deleteJson("/api/v1/cart/items/{$collision}?service_line=laboratory&brand=olab", [], a2Headers($leo, true)));
    expect(MedicalServiceCartItem::query()->whereKey($medicalItemId)->exists())->toBeTrue();

    // B21: removing the laboratory item keeps the medical line.
    $this->deleteJson("/api/v1/cart/items/{$labId}?service_line=laboratory&brand=olab", [], a2Headers($leo, true))->assertOk();
    expect($this->getJson('/api/v1/cart?service_line=medical_services', a2Headers($leo))->json('data'))->toEqual($medicalBefore);
});

test('A2 B19 a laboratory-only grant cannot add a membership and a membership-only grant cannot write laboratory', function () {
    $labOnly = a2Leo();
    a2Forbidden($this->postJson('/api/v1/cart/items', a2Membership(), a2Headers($labOnly, true)));
    expect(MedicalServiceCart::query()->where('customer_id', $labOnly['user']->customer->id)->first()?->items()->count() ?? 0)->toBe(0);

    $membershipOnly = a2Leo(['memberships:read', 'cart:read', 'checkout:prepare', 'cart:membership:write'], []);
    $item = a2LabItem($membershipOnly['user'], createOlabTest());
    a2Forbidden($this->deleteJson("/api/v1/cart/items/{$item->id}?service_line=laboratory&brand=olab", [], a2Headers($membershipOnly, true)));
    a2Forbidden($this->deleteJson("/api/v1/cart/items/{$item->id}?brand=olab", [], a2Headers($membershipOnly, true)));
    expect(LaboratoryCartItem::query()->whereKey($item->id)->exists())->toBeTrue();
});

// ── Forbidden generic operations and client-manipulated prices ───────

test('A2 generic cart:write and DELETE /cart stay denied to leo even with laboratory grants', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $leo = a2Leo([...A2_LAB_CAPS, 'cart:write'], ['olab'], $user);
    $item = a2LabItem($user, createOlabTest());

    a2Forbidden($this->deleteJson('/api/v1/cart?brand=olab', [], a2Headers($leo)));
    a2Forbidden($this->deleteJson('/api/v1/cart?service_line=laboratory&brand=olab', [], a2Headers($leo)));
    expect(LaboratoryCartItem::query()->whereKey($item->id)->exists())->toBeTrue();

    $writeOnlyGeneric = a2Leo(['cart:read', A2_READ, 'cart:write'], ['olab']);
    a2Forbidden($this->postJson('/api/v1/cart/items', ['laboratory_test_id' => createOlabTest()->id, 'brand' => 'olab'], a2Headers($writeOnlyGeneric, true)));
    expect(LaboratoryCartItem::query()->count())->toBe(1);
});

test('A2 client-supplied amounts or extra fields are rejected and never persisted', function () {
    $leo = a2Leo();
    $test = createOlabTest(['famedic_price_cents' => 35000]);
    foreach ([['price_cents' => 1], ['total_cents' => 1], ['unit_price_cents' => 1], ['subtotal_cents' => 1], ['discount_cents' => 35000], ['quantity' => 2], ['service_line' => 'laboratory']] as $extra) {
        $this->postJson('/api/v1/cart/items', ['laboratory_test_id' => $test->id, 'brand' => 'olab'] + $extra, a2Headers($leo, true))
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }
    expect(LaboratoryCartItem::query()->count())->toBe(0);

    $price = $this->postJson('/api/v1/cart/items', ['laboratory_test_id' => $test->id, 'brand' => 'olab'], a2Headers($leo, true))
        ->assertStatus(201)->json('data.item.price_cents');
    expect($price)->toBe(35000);
});

// ── Leo restrictions and native regression ───────────────────────────

test('A2 leo laboratory grants never open payment, payment link, coupons, pharmacy or other lines', function () {
    $leo = a2Leo([...A2_LAB_CAPS, 'payments:draft', 'legacy-benefits:write'], ['olab']);
    a2LabItem($leo['user'], createOlabTest());

    a2Forbidden($this->postJson('/api/v1/checkout/draft', ['brand' => 'olab'], a2Headers($leo)));
    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], a2Headers($leo, true))->assertStatus(403);
    expect(DB::table('akubica_checkout_links')->count())->toBe(0);
    a2Forbidden($this->postJson('/api/v1/cart/coupon', ['brand' => 'olab', 'code' => 'X'], a2Headers($leo)));
    $this->postJson('/api/v1/cart/items', ['service_line' => 'pharmacy', 'item_type' => 'medication'], a2Headers($leo, true))->assertStatus(503);
    a2Forbidden($this->getJson('/api/v1/cart?service_line=pharmacy', a2Headers($leo)));
    // The legacy brand-only form is not the Leo laboratory surface.
    a2Forbidden($this->getJson('/api/v1/cart?brand=olab', a2Headers($leo)));
    expect(LaboratoryCartItem::query()->count())->toBe(1);
});

test('A2 native keeps its behaviour: brand-only laboratory cart works and service_line=laboratory stays 409', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $native = SyntheticConsumerRegistryFixture::issue($user, 'native');
    $test = createOlabTest(['requires_appointment' => false]);

    $this->postJson('/api/v1/cart/items', ['brand' => 'olab', 'laboratory_test_id' => $test->id, 'quantity' => 1], a2Headers($native, true))->assertStatus(201);
    $this->getJson('/api/v1/cart?brand=olab', a2Headers($native))->assertOk()->assertJsonPath('data.brand', 'olab');
    $this->getJson('/api/v1/cart?service_line=laboratory&brand=olab', a2Headers($native))->assertStatus(409)->assertJsonPath('error.code', 'CART_SERVICE_LINE_MISMATCH');
    $prepare = $this->getJson('/api/v1/checkout/prepare?brand=olab', a2Headers($native))->assertOk()->json('data');
    expect($prepare)->toHaveKeys(['contacts', 'addresses', 'payment_methods'])->not->toHaveKey('audience');
});

// ── Idempotency ──────────────────────────────────────────────────────

test('A2 idempotent add and remove: a retried key replays without a second effect', function () {
    $leo = a2Leo();
    $test = createOlabTest();
    $key = (string) Str::uuid();

    $first = $this->postJson('/api/v1/cart/items', ['laboratory_test_id' => $test->id, 'brand' => 'olab'], a2Headers($leo, true, $key))->assertStatus(201);
    $second = $this->postJson('/api/v1/cart/items', ['laboratory_test_id' => $test->id, 'brand' => 'olab'], a2Headers($leo, true, $key))->assertStatus(201);
    expect($second->json())->toEqual($first->json())
        ->and(LaboratoryCartItem::query()->count())->toBe(1);

    $itemId = $first->json('data.item.id');
    $removeKey = (string) Str::uuid();
    $this->deleteJson("/api/v1/cart/items/{$itemId}?service_line=laboratory&brand=olab", [], a2Headers($leo, true, $removeKey))->assertOk();
    $this->deleteJson("/api/v1/cart/items/{$itemId}?service_line=laboratory&brand=olab", [], a2Headers($leo, true, $removeKey))->assertOk()
        ->assertJsonPath('data.removed_item_id', $itemId);
    expect(LaboratoryCartItem::query()->count())->toBe(0);
    // Without a key, a retry of an already removed own item is a plain 404 (no second effect).
    $this->deleteJson("/api/v1/cart/items/{$itemId}?service_line=laboratory&brand=olab", [], a2Headers($leo))
        ->assertStatus(404)->assertJsonPath('error.code', 'CART_ITEM_NOT_FOUND');
});
