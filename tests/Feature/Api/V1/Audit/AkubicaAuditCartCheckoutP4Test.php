<?php

use App\Enums\LaboratoryBrand;
use App\Models\Api\V1\ApiV1AuditEvent;
use App\Models\Contact;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryTest;
use App\Models\User;
use App\Services\Api\V1\Audit\AuditActorResolver;
use App\Services\Api\V1\Audit\AuditEventDefinitions;
use App\Services\Api\V1\Audit\AuditEventWriter;
use App\Services\Api\V1\Audit\AuditMetadataNormalizer;
use App\Services\Api\V1\Audit\AuditOutcome;
use App\Services\Api\V1\Audit\CartCheckoutAuditRecorder;
use App\Support\Api\V1\AkubicaCorrelationId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config()->set('api_v1.audit.enabled', false);
    config()->set('api_v1.idempotency.enabled', false);
});

afterEach(function () {
    config()->set('api_v1.audit.enabled', false);
    config()->set('api_v1.idempotency.enabled', false);
});

function enableCartCheckoutAudit(): void
{
    config()->set('api_v1.audit.enabled', true);
    app()->forgetInstance(AuditMetadataNormalizer::class);
    app()->forgetInstance(AuditEventWriter::class);
    app()->forgetInstance(CartCheckoutAuditRecorder::class);
    app()->forgetInstance(AuditActorResolver::class);
}

/**
 * @return array{0: User, 1: string, 2: int}
 */
function cartAuditCustomerToken(array $userAttrs = []): array
{
    $user = User::factory()->withRegularCustomer()->create(array_merge([
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
        'email' => 'cart.audit@ejemplo.com',
    ], $userAttrs));

    $newToken = $user->createToken('akubica-test');

    return [$user, $newToken->plainTextToken, (int) $newToken->accessToken->id];
}

function cartAuditAssertNoSecrets(?ApiV1AuditEvent $event = null, array $extraForbidden = []): void
{
    $rows = $event !== null
        ? [DB::table('api_v1_audit_events')->where('id', $event->id)->first()]
        : DB::table('api_v1_audit_events')->get()->all();

    foreach ($rows as $row) {
        $blob = json_encode($row);
        expect($blob)->not->toContain('Bearer')
            ->and($blob)->not->toContain('+5255')
            ->and($blob)->not->toContain('@ejemplo.com')
            ->and($blob)->not->toContain('Idempotency-Key')
            ->and($blob)->not->toContain('idem-key-')
            ->and($blob)->not->toContain('PROMO10')
            ->and($blob)->not->toContain('SALDO-')
            ->and($blob)->not->toContain('payment_url')
            ->and($blob)->not->toContain('payment_token')
            ->and($blob)->not->toContain('cvv')
            ->and($blob)->not->toContain('api_v1.payment.')
            ->and($blob)->not->toContain('api_v1.orders.created');

        foreach ($extraForbidden as $needle) {
            expect($blob)->not->toContain($needle);
        }
    }
}

function cartAuditLatest(string $eventName): ?ApiV1AuditEvent
{
    return ApiV1AuditEvent::query()
        ->where('event_name', $eventName)
        ->orderByDesc('id')
        ->first();
}

// ── Flag OFF / alcance ───────────────────────────────────────────────────

test('flag OFF cart and checkout mutations work without audit inserts', function () {
    [$user, $token] = cartAuditCustomerToken();
    $test = createOlabTest();

    $this->postJson('/api/v1/cart/items', [
        'brand' => 'olab',
        'laboratory_test_id' => $test->id,
    ], authHeaders($token))->assertCreated();

    $item = LaboratoryCartItem::query()->where('customer_id', $user->customer->id)->first();
    expect($item)->not->toBeNull();

    $this->deleteJson("/api/v1/cart/items/{$item->id}", [], authHeaders($token))->assertOk();
    $this->deleteJson('/api/v1/cart?brand=olab', [], authHeaders($token))->assertOk();

    expect(ApiV1AuditEvent::query()->count())->toBe(0);
});

test('block 4 does not audit GET cart totals coupon prepare or payment-link', function () {
    enableCartCheckoutAudit();
    [$user, $token] = cartAuditCustomerToken();
    addOlabCartItemReadyForPaymentLink($user);
    [$contact, $address] = setupAkubicaCheckoutDraft($user);

    $this->getJson('/api/v1/cart?brand=olab', authHeaders($token))->assertOk();
    $this->getJson('/api/v1/cart/totals?brand=olab', authHeaders($token))->assertOk();
    $this->getJson('/api/v1/cart/coupon?brand=olab', authHeaders($token))->assertOk();
    $this->getJson('/api/v1/checkout/prepare?brand=olab', authHeaders($token))->assertOk();

    $this->postJson('/api/v1/checkout/payment-link', [
        'brand' => 'olab',
    ], authHeaders($token))->assertOk();

    expect(ApiV1AuditEvent::query()->count())->toBe(0)
        ->and($contact->id)->toBeInt()
        ->and($address->id)->toBeInt();
});

test('block 4 does not emit payment or orders.created events', function () {
    enableCartCheckoutAudit();
    [$user, $token] = cartAuditCustomerToken();
    $test = createOlabTest();

    $this->postJson('/api/v1/cart/items', [
        'brand' => 'olab',
        'laboratory_test_id' => $test->id,
    ], authHeaders($token))->assertCreated();

    $names = ApiV1AuditEvent::query()->pluck('event_name')->all();
    foreach ($names as $name) {
        expect($name)->not->toStartWith('api_v1.payment.')
            ->and($name)->not->toBe('api_v1.orders.created')
            ->and($name)->not->toBe('api_v1.checkout.completed');
    }
});

// ── Carrito: add / remove / clear ────────────────────────────────────────

test('POST cart item success audits item_added with actor resource and metadata', function () {
    enableCartCheckoutAudit();
    [$user, $token, $tokenId] = cartAuditCustomerToken();
    $test = createOlabTest();
    $corr = 'corr-cart-item-added-01';

    $response = $this->postJson('/api/v1/cart/items', [
        'brand' => 'olab',
        'laboratory_test_id' => $test->id,
    ], array_merge(authHeaders($token), [
        AkubicaCorrelationId::HEADER => $corr,
    ]))->assertCreated();

    $itemId = (int) $response->json('data.item.id');
    $event = cartAuditLatest(AuditEventDefinitions::EVENT_CART_ITEM_ADDED);

    expect($event)->not->toBeNull()
        ->and($event->outcome)->toBe(AuditOutcome::SUCCEEDED)
        ->and($event->http_status)->toBe(201)
        ->and($event->error_code)->toBeNull()
        ->and($event->retryable)->toBeFalse()
        ->and($event->correlation_id)->toBe($corr)
        ->and($event->actor_type)->toBe('customer')
        ->and($event->actor_key)->toBe('customer:'.$user->customer->id)
        ->and($event->customer_id)->toBe($user->customer->id)
        ->and($event->user_id)->toBe($user->id)
        ->and($event->personal_access_token_id)->toBe($tokenId)
        ->and($event->resource_type)->toBe(CartCheckoutAuditRecorder::RESOURCE_LABORATORY_CART_ITEM)
        ->and($event->resource_key)->toBe((string) $itemId)
        ->and($event->metadata['laboratory_brand'])->toBe('olab')
        ->and($event->metadata['cart_item_row_id'])->toBe($itemId)
        ->and($event->metadata['laboratory_test_row_id'])->toBe($test->id)
        ->and($event->metadata['item_count'])->toBe(1)
        ->and($event->metadata['quantity'])->toBe(1)
        ->and(ApiV1AuditEvent::query()->count())->toBe(1);

    cartAuditAssertNoSecrets($event);
});

test('POST cart item LAB_TEST_NOT_FOUND audits rejected without foreign test id', function () {
    enableCartCheckoutAudit();
    [$user, $token] = cartAuditCustomerToken();

    $this->postJson('/api/v1/cart/items', [
        'brand' => 'olab',
        'laboratory_test_id' => 999999001,
    ], authHeaders($token))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'LAB_TEST_NOT_FOUND');

    $event = cartAuditLatest(AuditEventDefinitions::EVENT_CART_ITEM_ADDED);

    expect($event)->not->toBeNull()
        ->and($event->outcome)->toBe(AuditOutcome::REJECTED)
        ->and($event->http_status)->toBe(404)
        ->and($event->error_code)->toBe('LAB_TEST_NOT_FOUND')
        ->and($event->retryable)->toBeFalse()
        ->and($event->resource_key)->toBeNull()
        ->and($event->metadata['laboratory_brand'] ?? null)->toBe('olab')
        ->and($event->metadata)->not->toHaveKey('laboratory_test_row_id')
        ->and($event->metadata)->not->toHaveKey('cart_item_row_id');
});

test('POST cart item wrong brand audits LAB_TEST_NOT_FOUND without leaking test id', function () {
    enableCartCheckoutAudit();
    [$user, $token] = cartAuditCustomerToken();
    $swiss = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::SWISSLAB]);

    $this->postJson('/api/v1/cart/items', [
        'brand' => 'olab',
        'laboratory_test_id' => $swiss->id,
    ], authHeaders($token))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'LAB_TEST_NOT_FOUND');

    $event = cartAuditLatest(AuditEventDefinitions::EVENT_CART_ITEM_ADDED);

    expect($event->outcome)->toBe(AuditOutcome::REJECTED)
        ->and($event->error_code)->toBe('LAB_TEST_NOT_FOUND')
        ->and($event->resource_key)->toBeNull()
        ->and($event->metadata)->not->toHaveKey('laboratory_test_row_id')
        ->and($event->metadata)->not->toHaveKey('cart_item_row_id');
});

test('POST cart item already in cart audits ITEM_ALREADY_IN_CART', function () {
    enableCartCheckoutAudit();
    [$user, $token] = cartAuditCustomerToken();
    $test = addOlabCartItem($user);

    $this->postJson('/api/v1/cart/items', [
        'brand' => 'olab',
        'laboratory_test_id' => $test->id,
    ], authHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ITEM_ALREADY_IN_CART');

    $event = cartAuditLatest(AuditEventDefinitions::EVENT_CART_ITEM_ADDED);

    expect($event->outcome)->toBe(AuditOutcome::REJECTED)
        ->and($event->error_code)->toBe('ITEM_ALREADY_IN_CART')
        ->and($event->http_status)->toBe(409)
        ->and($event->metadata['laboratory_test_row_id'])->toBe($test->id);
});

test('DELETE cart item success audits item_removed', function () {
    enableCartCheckoutAudit();
    [$user, $token] = cartAuditCustomerToken();
    $test = addOlabCartItem($user);
    $item = LaboratoryCartItem::query()
        ->where('customer_id', $user->customer->id)
        ->where('laboratory_test_id', $test->id)
        ->firstOrFail();

    $this->deleteJson("/api/v1/cart/items/{$item->id}", [], authHeaders($token))->assertOk();

    $event = cartAuditLatest(AuditEventDefinitions::EVENT_CART_ITEM_REMOVED);

    expect($event)->not->toBeNull()
        ->and($event->outcome)->toBe(AuditOutcome::SUCCEEDED)
        ->and($event->http_status)->toBe(200)
        ->and($event->resource_type)->toBe(CartCheckoutAuditRecorder::RESOURCE_LABORATORY_CART_ITEM)
        ->and($event->resource_key)->toBe((string) $item->id)
        ->and($event->metadata['cart_item_row_id'])->toBe($item->id)
        ->and($event->metadata['item_count'])->toBe(0)
        ->and(LaboratoryCartItem::query()->find($item->id))->toBeNull();
});

test('DELETE foreign cart item audits FORBIDDEN without foreign ids', function () {
    enableCartCheckoutAudit();
    [$owner] = cartAuditCustomerToken(['email' => 'owner.cart@ejemplo.com', 'phone' => '5511111111']);
    [$stranger, $strangerToken] = cartAuditCustomerToken(['email' => 'stranger.cart@ejemplo.com', 'phone' => '5522222222']);
    $test = addOlabCartItem($owner);
    $item = LaboratoryCartItem::query()
        ->where('customer_id', $owner->customer->id)
        ->where('laboratory_test_id', $test->id)
        ->firstOrFail();

    $this->deleteJson("/api/v1/cart/items/{$item->id}", [], authHeaders($strangerToken))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');

    $event = cartAuditLatest(AuditEventDefinitions::EVENT_CART_ITEM_REMOVED);

    expect($event->outcome)->toBe(AuditOutcome::REJECTED)
        ->and($event->error_code)->toBe('FORBIDDEN')
        ->and($event->resource_key)->toBeNull()
        ->and($event->metadata ?? [])->not->toHaveKey('cart_item_row_id')
        ->and($event->metadata ?? [])->not->toHaveKey('laboratory_test_row_id')
        ->and($event->metadata ?? [])->not->toHaveKey('laboratory_brand')
        ->and($event->customer_id)->toBe($stranger->customer->id)
        ->and($event->customer_id)->not->toBe($owner->customer->id)
        ->and(LaboratoryCartItem::query()->find($item->id))->not->toBeNull()
        ->and($stranger->customer->id)->not->toBe($owner->customer->id);
});

test('DELETE missing cart item audits CART_ITEM_NOT_FOUND', function () {
    enableCartCheckoutAudit();
    [, $token] = cartAuditCustomerToken();

    $this->deleteJson('/api/v1/cart/items/999999002', [], authHeaders($token))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'CART_ITEM_NOT_FOUND');

    $event = cartAuditLatest(AuditEventDefinitions::EVENT_CART_ITEM_REMOVED);

    expect($event->outcome)->toBe(AuditOutcome::REJECTED)
        ->and($event->error_code)->toBe('CART_ITEM_NOT_FOUND')
        ->and($event->resource_key)->toBeNull();
});

test('DELETE cart clear audits cart.cleared', function () {
    enableCartCheckoutAudit();
    [$user, $token] = cartAuditCustomerToken();
    addOlabCartItem($user);
    addOlabCartItem($user, createOlabTest(['name' => 'Segundo estudio']));

    $this->deleteJson('/api/v1/cart?brand=olab', [], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.deleted_count', 2);

    $event = cartAuditLatest(AuditEventDefinitions::EVENT_CART_CLEARED);

    expect($event)->not->toBeNull()
        ->and($event->outcome)->toBe(AuditOutcome::SUCCEEDED)
        ->and($event->resource_type)->toBe(CartCheckoutAuditRecorder::RESOURCE_LABORATORY_CART)
        ->and($event->resource_key)->toBe($user->customer->id.':olab')
        ->and($event->metadata['item_count'])->toBe(2)
        ->and($event->metadata['laboratory_brand'])->toBe('olab')
        ->and($user->customer->laboratoryCartItems()->ofBrand(LaboratoryBrand::OLAB)->count())->toBe(0);
});

test('one cart mutation request produces one terminal audit event', function () {
    enableCartCheckoutAudit();
    [$user, $token] = cartAuditCustomerToken();
    $test = createOlabTest();

    $this->postJson('/api/v1/cart/items', [
        'brand' => 'olab',
        'laboratory_test_id' => $test->id,
    ], authHeaders($token))->assertCreated();

    expect(ApiV1AuditEvent::query()->count())->toBe(1);
});

// ── Beneficios (cupón / saldo) ───────────────────────────────────────────

test('POST cart coupon success audits benefit_applied without coupon code', function () {
    enableCartCheckoutAudit();
    [$user, $token] = cartAuditCustomerToken();
    addOlabCartItem($user);
    $coupon = createBalanceCouponForUser($user, 'PROMO10', 7000);

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'olab',
        'code' => 'PROMO10',
    ], authHeaders($token))->assertOk();

    $event = cartAuditLatest(AuditEventDefinitions::EVENT_CART_BENEFIT_APPLIED);

    expect($event)->not->toBeNull()
        ->and($event->outcome)->toBe(AuditOutcome::SUCCEEDED)
        ->and($event->resource_type)->toBe(CartCheckoutAuditRecorder::RESOURCE_LABORATORY_CART)
        ->and($event->metadata['benefit_type'])->toBe('balance')
        ->and($event->metadata['coupon_row_id'])->toBe($coupon->id)
        ->and($event->metadata['applied_amount_minor'])->toBe(7000)
        ->and($event->metadata['credit_minor'])->toBe(7000)
        ->and($event->metadata['currency'])->toBe('MXN')
        ->and($event->metadata)->not->toHaveKey('coupon_code')
        ->and($event->metadata)->not->toHaveKey('code');

    cartAuditAssertNoSecrets($event, ['PROMO10']);
});

test('POST cart coupon EMPTY_CART audits rejected', function () {
    enableCartCheckoutAudit();
    [$user, $token] = cartAuditCustomerToken();
    createBalanceCouponForUser($user, 'PROMO10', 7000);

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'olab',
        'code' => 'PROMO10',
    ], authHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'EMPTY_CART');

    $event = cartAuditLatest(AuditEventDefinitions::EVENT_CART_BENEFIT_APPLIED);

    expect($event->outcome)->toBe(AuditOutcome::REJECTED)
        ->and($event->error_code)->toBe('EMPTY_CART')
        ->and($event->metadata)->not->toHaveKey('coupon_row_id');
});

test('POST cart coupon foreign code audits COUPON_NOT_FOUND without leaking ids', function () {
    enableCartCheckoutAudit();
    [$owner] = cartAuditCustomerToken(['email' => 'coupon.owner@ejemplo.com', 'phone' => '5533333333']);
    [$stranger, $strangerToken] = cartAuditCustomerToken(['email' => 'coupon.stranger@ejemplo.com', 'phone' => '5544444444']);
    addOlabCartItem($stranger);
    $coupon = createBalanceCouponForUser($owner, 'SALDO-AJENO', 5000);

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'olab',
        'code' => 'SALDO-AJENO',
    ], authHeaders($strangerToken))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'COUPON_NOT_FOUND');

    $event = cartAuditLatest(AuditEventDefinitions::EVENT_CART_BENEFIT_APPLIED);
    $blob = json_encode(DB::table('api_v1_audit_events')->where('id', $event->id)->first());

    expect($event->error_code)->toBe('COUPON_NOT_FOUND')
        ->and($event->metadata ?? [])->not->toHaveKey('coupon_row_id')
        ->and($event->metadata ?? [])->not->toHaveKey('applied_amount_minor')
        ->and($blob)->not->toContain('SALDO-AJENO')
        ->and(($event->metadata['coupon_row_id'] ?? null))->not->toBe($coupon->id);
});

test('POST cart coupon expired audits COUPON_EXPIRED', function () {
    enableCartCheckoutAudit();
    [$user, $token] = cartAuditCustomerToken();
    addOlabCartItem($user);
    $coupon = createBalanceCouponForUser($user, 'PROMO10', 7000);
    \App\Models\CouponUser::query()
        ->where('user_id', $user->id)
        ->where('coupon_id', $coupon->id)
        ->update(['used_at' => now()]);

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'olab',
        'code' => 'PROMO10',
    ], authHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COUPON_EXPIRED');

    $event = cartAuditLatest(AuditEventDefinitions::EVENT_CART_BENEFIT_APPLIED);

    expect($event->outcome)->toBe(AuditOutcome::REJECTED)
        ->and($event->error_code)->toBe('COUPON_EXPIRED');
});

test('POST cart coupon not applicable audits COUPON_NOT_APPLICABLE', function () {
    enableCartCheckoutAudit();
    [$user, $token] = cartAuditCustomerToken();
    addOlabCartItem($user, createOlabTest([
        'famedic_price_cents' => 5000,
        'public_price_cents' => 6000,
    ]));
    // remaining greater than cart total → not applicable in domain rules
    createBalanceCouponForUser($user, 'PROMO10', 9000);

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'olab',
        'code' => 'PROMO10',
    ], authHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COUPON_NOT_APPLICABLE');

    $event = cartAuditLatest(AuditEventDefinitions::EVENT_CART_BENEFIT_APPLIED);

    expect($event->error_code)->toBe('COUPON_NOT_APPLICABLE');
});

test('DELETE cart coupon success audits benefit_removed; no-op produces zero events', function () {
    enableCartCheckoutAudit();
    [$user, $token] = cartAuditCustomerToken();
    addOlabCartItem($user);
    $coupon = createBalanceCouponForUser($user, 'PROMO10', 7000);

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'olab',
        'code' => 'PROMO10',
    ], authHeaders($token))->assertOk();

    ApiV1AuditEvent::query()->delete();

    $this->deleteJson('/api/v1/cart/coupon?brand=olab', [], authHeaders($token))->assertOk();

    $event = cartAuditLatest(AuditEventDefinitions::EVENT_CART_BENEFIT_REMOVED);

    expect($event)->not->toBeNull()
        ->and($event->outcome)->toBe(AuditOutcome::SUCCEEDED)
        ->and($event->metadata['coupon_row_id'])->toBe($coupon->id)
        ->and($event->metadata['removed_amount_minor'])->toBe(7000)
        ->and($event->metadata['credit_minor'])->toBe(0);

    cartAuditAssertNoSecrets($event, ['PROMO10']);

    ApiV1AuditEvent::query()->delete();

    $this->deleteJson('/api/v1/cart/coupon?brand=olab', [], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.removed', false);

    expect(ApiV1AuditEvent::query()->count())->toBe(0);
});

// ── Checkout draft ───────────────────────────────────────────────────────

test('POST checkout draft success audits draft_synced', function () {
    enableCartCheckoutAudit();
    [$user, $token] = cartAuditCustomerToken();
    addOlabCartItemReadyForPaymentLink($user);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);

    $this->postJson('/api/v1/checkout/draft', [
        'brand' => 'olab',
        'contact_id' => $contact->id,
    ], authHeaders($token))->assertOk();

    $draft = LaboratoryCheckoutDraft::query()
        ->where('customer_id', $user->customer->id)
        ->where('laboratory_brand', LaboratoryBrand::OLAB)
        ->firstOrFail();

    $event = cartAuditLatest(AuditEventDefinitions::EVENT_CHECKOUT_DRAFT_SYNCED);

    expect($event)->not->toBeNull()
        ->and($event->outcome)->toBe(AuditOutcome::SUCCEEDED)
        ->and($event->resource_type)->toBe(CartCheckoutAuditRecorder::RESOURCE_LABORATORY_CHECKOUT_DRAFT)
        ->and($event->resource_key)->toBe((string) $draft->id)
        ->and($event->metadata['draft_row_id'])->toBe($draft->id)
        ->and($event->metadata['checkout_step'])->toBe('address')
        ->and($event->metadata['item_count'])->toBe(1)
        ->and($event->metadata)->not->toHaveKey('contact_id')
        ->and($event->metadata)->not->toHaveKey('address_id')
        ->and($event->metadata)->not->toHaveKey('notes');

    cartAuditAssertNoSecrets($event);
});

test('POST checkout draft EMPTY_CART audits rejected', function () {
    enableCartCheckoutAudit();
    [, $token] = cartAuditCustomerToken();

    $this->postJson('/api/v1/checkout/draft', [
        'brand' => 'olab',
        'contact_id' => 1,
    ], authHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'EMPTY_CART');

    $event = cartAuditLatest(AuditEventDefinitions::EVENT_CHECKOUT_DRAFT_SYNCED);

    expect($event->outcome)->toBe(AuditOutcome::REJECTED)
        ->and($event->error_code)->toBe('EMPTY_CART')
        ->and($event->resource_key)->toBeNull();
});

test('POST checkout draft foreign contact audits CONTACT_NOT_FOUND without foreign id', function () {
    enableCartCheckoutAudit();
    [$owner] = cartAuditCustomerToken(['email' => 'draft.owner@ejemplo.com', 'phone' => '5555555555']);
    [$stranger, $strangerToken] = cartAuditCustomerToken(['email' => 'draft.stranger@ejemplo.com', 'phone' => '5566666666']);
    addOlabCartItemReadyForPaymentLink($stranger);
    $foreignContact = Contact::factory()->create(['customer_id' => $owner->customer->id]);

    $this->postJson('/api/v1/checkout/draft', [
        'brand' => 'olab',
        'contact_id' => $foreignContact->id,
    ], authHeaders($strangerToken))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'CONTACT_NOT_FOUND');

    $event = cartAuditLatest(AuditEventDefinitions::EVENT_CHECKOUT_DRAFT_SYNCED);

    expect($event->error_code)->toBe('CONTACT_NOT_FOUND')
        ->and($event->resource_key)->toBeNull()
        ->and($event->metadata ?? [])->not->toHaveKey('draft_row_id')
        ->and($event->metadata ?? [])->not->toHaveKey('contact_id')
        ->and(($event->metadata['draft_row_id'] ?? null))->not->toBe($foreignContact->id);
});

// ── Normalizer / fail-soft / pre-middleware ───────────────────────────────

test('normalizer keeps cart allowlisted keys and discards coupon_code and PII', function () {
    $normalizer = AuditMetadataNormalizer::fromConfig();
    $event = AuditEventDefinitions::EVENT_CART_BENEFIT_APPLIED;

    $kept = $normalizer->normalize($event, [
        'laboratory_brand' => 'olab',
        'benefit_type' => 'balance',
        'coupon_row_id' => 42,
        'applied_amount_minor' => 7000,
        'currency' => 'MXN',
        'coupon_code' => 'PROMO10',
        'promo_code' => 'LEAK',
        'email' => 'leak@ejemplo.com',
        'phone' => '+525512345678',
        'card' => '4111111111111111',
        'payment_token' => 'tok_xxx',
        'request_body' => ['code' => 'PROMO10'],
    ]);

    expect($kept)->toMatchArray([
        'laboratory_brand' => 'olab',
        'benefit_type' => 'balance',
        'coupon_row_id' => 42,
        'applied_amount_minor' => 7000,
        'currency' => 'MXN',
    ])
        ->and($kept)->not->toHaveKey('coupon_code')
        ->and($kept)->not->toHaveKey('promo_code')
        ->and($kept)->not->toHaveKey('email')
        ->and($kept)->not->toHaveKey('phone')
        ->and($kept)->not->toHaveKey('card')
        ->and($kept)->not->toHaveKey('payment_token')
        ->and($kept)->not->toHaveKey('request_body');
});

test('broken audit writer does not change cart mutation outcome', function () {
    enableCartCheckoutAudit();
    [$user, $token] = cartAuditCustomerToken();
    $test = createOlabTest();

    Schema::rename('api_v1_audit_events', 'api_v1_audit_events_broken');

    try {
        $this->postJson('/api/v1/cart/items', [
            'brand' => 'olab',
            'laboratory_test_id' => $test->id,
        ], authHeaders($token))
            ->assertCreated()
            ->assertJsonPath('success', true);

        expect(
            LaboratoryCartItem::query()
                ->where('customer_id', $user->customer->id)
                ->where('laboratory_test_id', $test->id)
                ->exists()
        )->toBeTrue();
    } finally {
        if (Schema::hasTable('api_v1_audit_events_broken')) {
            Schema::rename('api_v1_audit_events_broken', 'api_v1_audit_events');
        }
    }
});

test('broken audit writer does not change coupon rejection or draft success', function () {
    enableCartCheckoutAudit();
    [$user, $token] = cartAuditCustomerToken();
    createBalanceCouponForUser($user, 'PROMO10', 7000);

    Schema::rename('api_v1_audit_events', 'api_v1_audit_events_broken');

    try {
        $this->postJson('/api/v1/cart/coupon', [
            'brand' => 'olab',
            'code' => 'PROMO10',
        ], authHeaders($token))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'EMPTY_CART');

        addOlabCartItemReadyForPaymentLink($user);
        $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);

        $this->postJson('/api/v1/checkout/draft', [
            'brand' => 'olab',
            'contact_id' => $contact->id,
        ], authHeaders($token))
            ->assertOk()
            ->assertJsonPath('success', true);

        expect(
            LaboratoryCheckoutDraft::query()
                ->where('customer_id', $user->customer->id)
                ->where('contact_id', $contact->id)
                ->exists()
        )->toBeTrue();
    } finally {
        if (Schema::hasTable('api_v1_audit_events_broken')) {
            Schema::rename('api_v1_audit_events_broken', 'api_v1_audit_events');
        }
    }
});

test('401 and 403 before controller produce zero cart audit events', function () {
    enableCartCheckoutAudit();

    $this->postJson('/api/v1/cart/items', [
        'brand' => 'olab',
        'laboratory_test_id' => 1,
    ])->assertUnauthorized();

    $user = User::factory()->create();
    $token = $user->createToken('akubica-test')->plainTextToken;

    $this->postJson('/api/v1/cart/items', [
        'brand' => 'olab',
        'laboratory_test_id' => 1,
    ], authHeaders($token))->assertForbidden();

    expect(ApiV1AuditEvent::query()->count())->toBe(0);
});

test('payment-link still creates no LaboratoryPurchase under block 4 audit', function () {
    enableCartCheckoutAudit();
    [$user, $token] = cartAuditCustomerToken();
    addOlabCartItemReadyForPaymentLink($user);
    setupAkubicaCheckoutDraft($user);

    $before = \App\Models\LaboratoryPurchase::query()->count();

    $this->postJson('/api/v1/checkout/payment-link', [
        'brand' => 'olab',
    ], authHeaders($token))->assertOk();

    expect(\App\Models\LaboratoryPurchase::query()->count())->toBe($before)
        ->and(ApiV1AuditEvent::query()->count())->toBe(0);
});
