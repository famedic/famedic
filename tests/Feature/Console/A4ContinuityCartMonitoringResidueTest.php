<?php

/**
 * IT-0061 — A4 monitoring-cart residue: hypothesis and supported cleanup.
 *
 * Hypothesis (tested against 5a5ad14e before the fix):
 * A4 cleanup deletes laboratory_cart_items with a query-builder forceDelete(),
 * which does not fire LaboratoryCartItemMonitoringObserver, so the monitoring
 * cart is not resynchronised. monitoring:sync-carts only visits customers that
 * still have laboratory or pharmacy items, so the stale cart remains.
 *
 * The test "A4 cleanup removes the window monitoring cart..." failed on the
 * unfixed base (cart still existed after a successful --cleanup) and is the
 * regression that the command fix must turn green.
 */

use App\Console\Commands\A4ContinuityCartFixturesCommand;
use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\AkubicaCheckoutLink;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryTest;
use App\Models\User;
use App\Services\Api\V1\Continuity\LaboratoryCheckoutContinuity;
use App\Services\Api\V1\Uat\A4SyntheticMonitoringWindow;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

function a4MonitoringResidueCustomer(): User
{
    config()->set('akubica_uat.allowed_environments', ['testing']);
    Storage::disk('local')->delete(AkubicaUatFixtureContract::storagePath(
        'a4-continuity-cart.json',
        AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
    ));

    $user = User::factory()->withRegularCustomer()->create();
    AkubicaUatFixtureManifest::query()->create([
        'namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
        'fixture_version' => AkubicaUatFixtureContract::FIXTURE_VERSION,
        'status' => AkubicaUatFixtureContract::STATUS_ACTIVE,
        'expires_at' => now()->addHour(),
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
        'metadata' => ['ids' => ['customers' => ['membership_none' => $user->customer->id]]],
    ]);

    return $user;
}

function a4MonitoringCleanupArgs(array $extra = []): array
{
    return array_merge([
        '--namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
    ], $extra);
}

beforeEach(function () {
    config()->set('scout.driver', 'null');
    config()->set('scout.queue', false);
    config()->set('akubica_uat.allowed_environments', ['testing']);
    config()->set('akubica_connectors.continuity.laboratory.enabled', true);
    config()->set('akubica_connectors.continuity.laboratory.certified', true);
    config()->set('akubica_connectors.continuity.allowed_host', 'famedic-a4.test');
    URL::forceRootUrl('https://famedic-a4.test');
    URL::forceScheme('https');
    Http::fake();
    Mail::fake();
    Queue::fake();
    Bus::fake();
});

afterEach(function () {
    URL::forceRootUrl(null);
    A4SyntheticMonitoringWindow::clearTestHooks();
});

function a4ReadWindowLedger(): ?array
{
    return A4SyntheticMonitoringWindow::readAuthoritativeLedger(
        AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
    );
}

function a4IssueWindowLink(User $user): array
{
    $token = $user->createToken('a4-cleanup-pat');
    $user->withAccessToken($token->accessToken);
    $request = Request::create('https://famedic-a4.test/api/v1/checkout/prepare', 'GET', [
        'service_line' => 'laboratory',
        'brand' => 'olab',
    ]);
    $request->setUserResolver(fn () => $user);

    return app(LaboratoryCheckoutContinuity::class)->issue($request, $user->customer, LaboratoryBrand::OLAB);
}

test('query-builder forceDelete leaves a monitoring cart because the observer does not run', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $item = LaboratoryCartItem::factory()->create(['customer_id' => $user->customer->id]);

    expect(Cart::query()->where('user_id', $user->id)->where('type', MonitoringCartType::Lab)->count())->toBe(1)
        ->and(CartItem::query()->whereIn('cart_id', Cart::query()->where('user_id', $user->id)->select('id'))->count())->toBeGreaterThan(0);

    LaboratoryCartItem::query()->whereKey($item->id)->forceDelete();

    expect(LaboratoryCartItem::withTrashed()->whereKey($item->id)->exists())->toBeFalse()
        ->and(Cart::query()->where('user_id', $user->id)->where('type', MonitoringCartType::Lab)->count())->toBe(1)
        ->and(CartItem::query()->whereIn('cart_id', Cart::query()->where('user_id', $user->id)->select('id'))->count())->toBeGreaterThan(0);
});

test('eloquent model forceDelete does fire the observer and removes the empty monitoring cart', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $item = LaboratoryCartItem::factory()->create(['customer_id' => $user->customer->id]);
    $cartId = Cart::query()->where('user_id', $user->id)->where('type', MonitoringCartType::Lab)->value('id');
    expect($cartId)->not->toBeNull();

    $item->forceDelete();

    expect(LaboratoryCartItem::withTrashed()->whereKey($item->id)->exists())->toBeFalse()
        ->and(Cart::query()->whereKey($cartId)->exists())->toBeFalse()
        ->and(CartItem::query()->where('cart_id', $cartId)->exists())->toBeFalse();
});

test('A4 cleanup removes the window monitoring cart after deleting the laboratory item', function () {
    $user = a4MonitoringResidueCustomer();
    $args = a4MonitoringCleanupArgs();

    $this->artisan('akubica:a4-continuity-cart', $args + [
        '--apply' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ])->assertSuccessful();

    $item = LaboratoryCartItem::query()->sole();
    $cart = Cart::query()->where('user_id', $user->id)->where('type', MonitoringCartType::Lab)->sole();
    expect(CartItem::query()->where('cart_id', $cart->id)->count())->toBeGreaterThan(0);

    $this->artisan('akubica:a4-continuity-cart', $args + [
        '--cleanup' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ])->assertSuccessful();

    expect(LaboratoryCartItem::withTrashed()->whereKey($item->id)->exists())->toBeFalse()
        ->and(Cart::query()->whereKey($cart->id)->exists())->toBeFalse()
        ->and(CartItem::query()->where('cart_id', $cart->id)->exists())->toBeFalse();
});

test('A4 preparation, link emission, cleanup and independent reconciliation leave no window residue and no external jobs', function () {
    $user = a4MonitoringResidueCustomer();
    $statePath = AkubicaUatFixtureContract::storagePath('access-state.json', AkubicaUatFixtureContract::DEDICATED_NAMESPACE);
    Storage::disk('local')->put($statePath, json_encode([
        'namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
        'scenario' => 'functional-window-2-continuity',
        'status' => 'active',
        'token_count' => 1,
    ], JSON_THROW_ON_ERROR));
    $token = $user->createToken('a4-cleanup-pat', ['memberships:read', 'cart:read', 'checkout:prepare', 'cart:laboratory:read']);
    $tokenId = $token->accessToken->getKey();
    $user->withAccessToken($token->accessToken);

    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--apply' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ]))->assertSuccessful();

    $request = Request::create('https://famedic-a4.test/api/v1/checkout/prepare', 'GET', [
        'service_line' => 'laboratory',
        'brand' => 'olab',
    ]);
    $request->setUserResolver(fn () => $user);
    $issued = app(LaboratoryCheckoutContinuity::class)->issue($request, $user->customer, LaboratoryBrand::OLAB);
    expect($issued['continuity_url'])->toStartWith('https://famedic-a4.test/');

    $link = AkubicaCheckoutLink::query()->sole();
    $cartId = (int) $link->metadata['laboratory_cart_id'];
    expect($cartId)->toBeGreaterThan(0)
        ->and(Cart::query()->whereKey($cartId)->exists())->toBeTrue();

    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--cleanup' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ]))->assertSuccessful();

    $link->refresh();
    expect(LaboratoryCartItem::query()->where('customer_id', $user->customer->id)->count())->toBe(0)
        ->and(LaboratoryTest::query()->where('gda_id', 'like', A4ContinuityCartFixturesCommand::TEST_PREFIX.'%')->count())->toBe(0)
        ->and(Cart::query()->whereKey($cartId)->exists())->toBeFalse()
        ->and(CartItem::query()->where('cart_id', $cartId)->exists())->toBeFalse()
        ->and(AkubicaCheckoutLink::query()->whereKey($link->id)->exists())->toBeTrue()
        ->and($link->used_at)->toBeNull()
        ->and($link->expires_at->lte(now()))->toBeTrue()
        ->and(PersonalAccessToken::query()->whereKey($tokenId)->exists())->toBeTrue()
        ->and(json_decode((string) Storage::disk('local')->get($statePath), true)['status'] ?? null)->toBe('active');

    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
    Mail::assertNothingSent();
    expect(Http::recorded())->toHaveCount(0);

    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--cleanup' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ]))->assertSuccessful();
    expect(Cart::query()->where('user_id', $user->id)->where('type', MonitoringCartType::Lab)->count())->toBe(0);
});

test('A4 cleanup preserves foreign, shared and non-window monitoring carts', function () {
    $user = a4MonitoringResidueCustomer();
    $foreign = User::factory()->withRegularCustomer()->create();
    $foreignItem = LaboratoryCartItem::factory()->create(['customer_id' => $foreign->customer->id]);
    $foreignCartId = Cart::query()->where('user_id', $foreign->id)->where('type', MonitoringCartType::Lab)->value('id');

    $pharmacyCart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Pharmacy,
        'status' => MonitoringCartStatus::Active,
        'total' => 10,
    ]);
    $pharmacyItem = CartItem::query()->create([
        'cart_id' => $pharmacyCart->id,
        'product_id' => 'foreign-product',
        'name' => 'shared pharmacy item',
        'price' => 10,
        'quantity' => 1,
    ]);

    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--apply' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ]))->assertSuccessful();

    // After apply: an unproven same-user lab cart must not be rebuilt away by
    // the laboratory-item observer, and cleanup must leave it in place.
    $unrelatedLabCart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab,
        'status' => MonitoringCartStatus::Active,
        'total' => 5,
    ]);
    CartItem::query()->create([
        'cart_id' => $unrelatedLabCart->id,
        'product_id' => '999999',
        'name' => 'preexisting base item',
        'price' => 5,
        'quantity' => 1,
    ]);

    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--cleanup' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ]))->assertSuccessful();

    expect(LaboratoryCartItem::query()->whereKey($foreignItem->id)->exists())->toBeTrue()
        ->and(Cart::query()->whereKey($foreignCartId)->exists())->toBeTrue()
        ->and(Cart::query()->whereKey($pharmacyCart->id)->exists())->toBeTrue()
        ->and(CartItem::query()->whereKey($pharmacyItem->id)->exists())->toBeTrue()
        ->and(Cart::query()->whereKey($unrelatedLabCart->id)->exists())->toBeTrue();
});

test('A4 cleanup recovers a monitoring residue left after a partial query-builder delete', function () {
    $user = a4MonitoringResidueCustomer();
    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--apply' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ]))->assertSuccessful();

    $item = LaboratoryCartItem::query()->sole();
    $cart = Cart::query()->where('user_id', $user->id)->where('type', MonitoringCartType::Lab)->sole();
    LaboratoryCartItem::query()->whereKey($item->id)->forceDelete();
    expect(Cart::query()->whereKey($cart->id)->exists())->toBeTrue();

    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--cleanup' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ]))->assertSuccessful();

    expect(Cart::query()->whereKey($cart->id)->exists())->toBeFalse()
        ->and(CartItem::query()->where('cart_id', $cart->id)->exists())->toBeFalse();

    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--cleanup' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ]))->assertSuccessful();
    expect(Cart::query()->where('user_id', $user->id)->where('type', MonitoringCartType::Lab)->count())->toBe(0);
});

test('historical monitoring residue retirement requires exact IDs, provenance and is dry-run by default', function () {
    $user = a4MonitoringResidueCustomer();
    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--apply' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ]))->assertSuccessful();

    $token = $user->createToken('a4-cleanup-pat');
    $user->withAccessToken($token->accessToken);
    $request = Request::create('https://famedic-a4.test/api/v1/checkout/prepare', 'GET', [
        'service_line' => 'laboratory',
        'brand' => 'olab',
    ]);
    $request->setUserResolver(fn () => $user);
    app(LaboratoryCheckoutContinuity::class)->issue($request, $user->customer, LaboratoryBrand::OLAB);

    $cart = Cart::query()->where('user_id', $user->id)->where('type', MonitoringCartType::Lab)->sole();
    $item = CartItem::query()->where('cart_id', $cart->id)->sole();

    LaboratoryCartItem::query()->where('customer_id', $user->customer->id)->forceDelete();
    expect(Cart::query()->whereKey($cart->id)->exists())->toBeTrue();

    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--retire-monitoring-residue' => true,
        '--cart-id' => $cart->id,
        '--cart-item-id' => $item->id,
    ]))->assertSuccessful();
    expect(Cart::query()->whereKey($cart->id)->exists())->toBeTrue();

    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--retire-monitoring-residue' => true,
        '--execute' => true,
        '--cart-id' => $cart->id,
        '--cart-item-id' => $item->id,
        '--confirm' => 'wrong-confirm',
    ]))->assertFailed();
    expect(Cart::query()->whereKey($cart->id)->exists())->toBeTrue();

    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--retire-monitoring-residue' => true,
        '--execute' => true,
        '--cart-id' => $cart->id + 99,
        '--cart-item-id' => $item->id,
        '--confirm' => A4ContinuityCartFixturesCommand::RETIRE_MONITORING_CONFIRM,
    ]))->assertFailed();
    expect(Cart::query()->whereKey($cart->id)->exists())->toBeTrue();

    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--retire-monitoring-residue' => true,
        '--execute' => true,
        '--cart-id' => $cart->id,
        '--cart-item-id' => $item->id,
        '--confirm' => A4ContinuityCartFixturesCommand::RETIRE_MONITORING_CONFIRM,
    ]))->assertSuccessful();

    expect(Cart::query()->whereKey($cart->id)->exists())->toBeFalse()
        ->and(CartItem::query()->whereKey($item->id)->exists())->toBeFalse()
        ->and(AkubicaCheckoutLink::query()->count())->toBe(1);

    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--retire-monitoring-residue' => true,
        '--execute' => true,
        '--cart-id' => $cart->id,
        '--cart-item-id' => $item->id,
        '--confirm' => A4ContinuityCartFixturesCommand::RETIRE_MONITORING_CONFIRM,
    ]))->assertSuccessful();
});

test('A4 cleanup keeps a foreign monitoring item inside a recognized window cart and does not delete the cart', function () {
    $user = a4MonitoringResidueCustomer();
    $args = a4MonitoringCleanupArgs();

    $this->artisan('akubica:a4-continuity-cart', $args + [
        '--apply' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ])->assertSuccessful();

    $cart = Cart::query()->where('user_id', $user->id)->where('type', MonitoringCartType::Lab)->sole();
    $windowItem = CartItem::query()->where('cart_id', $cart->id)->sole();
    $foreignItem = CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_id' => 'foreign-monitoring-item',
        'name' => 'unaccredited monitoring item',
        'price' => 5,
        'quantity' => 1,
    ]);

    $this->artisan('akubica:a4-continuity-cart', $args + [
        '--cleanup' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ])->assertSuccessful();

    expect(Cart::query()->whereKey($cart->id)->exists())->toBeTrue()
        ->and(CartItem::query()->whereKey($foreignItem->id)->exists())->toBeTrue()
        ->and(CartItem::query()->whereKey($windowItem->id)->exists())->toBeFalse()
        ->and(CartItem::query()->where('cart_id', $cart->id)->count())->toBe(1);
});

test('A4 cleanup preserves a completed historical monitoring cart even when a continuity link points to it', function () {
    $user = a4MonitoringResidueCustomer();
    $args = a4MonitoringCleanupArgs();

    $this->artisan('akubica:a4-continuity-cart', $args + [
        '--apply' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ])->assertSuccessful();

    $historicalCart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab,
        'status' => MonitoringCartStatus::Completed,
        'total' => 15,
    ]);
    $historicalItem = CartItem::query()->create([
        'cart_id' => $historicalCart->id,
        'product_id' => 'historical-completed',
        'name' => 'historical completed item',
        'price' => 15,
        'quantity' => 1,
    ]);
    $historicalLink = AkubicaCheckoutLink::query()->create([
        'customer_id' => $user->customer->id,
        'token_hash' => hash('sha256', 'historical-completed-link'),
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'expires_at' => now()->subDay(),
        'used_at' => null,
        'metadata' => [
            'purpose' => LaboratoryCheckoutContinuity::PURPOSE,
            'service_line' => 'laboratory',
            'laboratory_cart_id' => (int) $historicalCart->id,
        ],
    ]);

    $this->artisan('akubica:a4-continuity-cart', $args + [
        '--cleanup' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ])->assertSuccessful();

    $historicalLink->refresh();
    expect(Cart::query()->whereKey($historicalCart->id)->exists())->toBeTrue()
        ->and(CartItem::query()->whereKey($historicalItem->id)->exists())->toBeTrue()
        ->and(AkubicaCheckoutLink::query()->whereKey($historicalLink->id)->exists())->toBeTrue()
        ->and($historicalLink->used_at)->toBeNull()
        ->and($historicalCart->fresh()->status)->toBe(MonitoringCartStatus::Completed);
});

test('historical monitoring residue execute rejects a content change after precheck and does not delete foreign items', function () {
    $user = a4MonitoringResidueCustomer();
    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--apply' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ]))->assertSuccessful();

    $token = $user->createToken('a4-cleanup-pat');
    $user->withAccessToken($token->accessToken);
    $request = Request::create('https://famedic-a4.test/api/v1/checkout/prepare', 'GET', [
        'service_line' => 'laboratory',
        'brand' => 'olab',
    ]);
    $request->setUserResolver(fn () => $user);
    app(LaboratoryCheckoutContinuity::class)->issue($request, $user->customer, LaboratoryBrand::OLAB);

    $cart = Cart::query()->where('user_id', $user->id)->where('type', MonitoringCartType::Lab)->sole();
    $item = CartItem::query()->where('cart_id', $cart->id)->sole();

    LaboratoryCartItem::query()->where('customer_id', $user->customer->id)->forceDelete();
    expect(Cart::query()->whereKey($cart->id)->exists())->toBeTrue();

    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--retire-monitoring-residue' => true,
        '--cart-id' => $cart->id,
        '--cart-item-id' => $item->id,
    ]))->assertSuccessful();
    expect(Cart::query()->whereKey($cart->id)->exists())->toBeTrue()
        ->and(CartItem::query()->whereKey($item->id)->exists())->toBeTrue();

    $foreignItem = CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_id' => 'injected-after-precheck',
        'name' => 'foreign item added after dry-run',
        'price' => 1,
        'quantity' => 1,
    ]);

    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--retire-monitoring-residue' => true,
        '--execute' => true,
        '--cart-id' => $cart->id,
        '--cart-item-id' => $item->id,
        '--confirm' => A4ContinuityCartFixturesCommand::RETIRE_MONITORING_CONFIRM,
    ]))->assertFailed();

    expect(Cart::query()->whereKey($cart->id)->exists())->toBeTrue()
        ->and(CartItem::query()->whereKey($item->id)->exists())->toBeTrue()
        ->and(CartItem::query()->whereKey($foreignItem->id)->exists())->toBeTrue();
});


test('A4 cleanup keeps another cart of the same owner that contains the same study', function () {
    $user = a4MonitoringResidueCustomer();
    $args = a4MonitoringCleanupArgs();

    $this->artisan('akubica:a4-continuity-cart', $args + [
        '--apply' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ])->assertSuccessful();

    $windowCart = Cart::query()->where('user_id', $user->id)->where('type', MonitoringCartType::Lab)->where('status', MonitoringCartStatus::Active)->sole();
    $windowItem = CartItem::query()->where('cart_id', $windowCart->id)->sole();

    $ledgerPath = AkubicaUatFixtureContract::storagePath(
        'a4-continuity-cart.json',
        AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
    );
    $ledger = json_decode((string) Storage::disk('local')->get($ledgerPath), true, 512, JSON_THROW_ON_ERROR);
    expect($ledger['customer_id'])->toBe($user->customer->id)
        ->and($ledger['test_id'])->toBe((int) $windowItem->product_id)
        ->and($ledger['monitoring_cart_id'])->toBe((int) $windowCart->id)
        ->and($ledger['monitoring_cart_item_ids'])->toBe([(int) $windowItem->id]);

    $otherCart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab,
        'status' => MonitoringCartStatus::Active,
        'total' => $windowItem->price,
    ]);
    $otherItem = CartItem::query()->create([
        'cart_id' => $otherCart->id,
        'product_id' => $windowItem->product_id,
        'name' => 'same study in another cart of the owner',
        'price' => $windowItem->price,
        'quantity' => 1,
    ]);

    $this->artisan('akubica:a4-continuity-cart', $args + [
        '--cleanup' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ])->expectsOutputToContain('ITEM_NOT_IN_LEDGER_PRESERVED')->assertSuccessful();

    expect(Cart::query()->whereKey($otherCart->id)->exists())->toBeTrue()
        ->and(CartItem::query()->whereKey($otherItem->id)->exists())->toBeTrue()
        ->and(CartItem::query()->where('cart_id', $otherCart->id)->count())->toBe(1)
        ->and(Cart::query()->whereKey($windowCart->id)->exists())->toBeFalse()
        ->and(CartItem::query()->whereKey($windowItem->id)->exists())->toBeFalse();
});

test('A4 cleanup preserves a completed cart that contains the same study recorded in the ledger', function () {
    $user = a4MonitoringResidueCustomer();
    $args = a4MonitoringCleanupArgs();

    $this->artisan('akubica:a4-continuity-cart', $args + [
        '--apply' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ])->assertSuccessful();

    $windowCart = Cart::query()->where('user_id', $user->id)->where('type', MonitoringCartType::Lab)->where('status', MonitoringCartStatus::Active)->sole();
    $windowItem = CartItem::query()->where('cart_id', $windowCart->id)->sole();

    $completedCart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab,
        'status' => MonitoringCartStatus::Completed,
        'total' => $windowItem->price,
    ]);
    $completedItem = CartItem::query()->create([
        'cart_id' => $completedCart->id,
        'product_id' => $windowItem->product_id,
        'name' => 'same study in a completed cart',
        'price' => $windowItem->price,
        'quantity' => 1,
    ]);

    $this->artisan('akubica:a4-continuity-cart', $args + [
        '--cleanup' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ])->expectsOutputToContain('CART_COMPLETED_PRESERVED')->assertSuccessful();

    expect(Cart::query()->whereKey($completedCart->id)->exists())->toBeTrue()
        ->and(CartItem::query()->whereKey($completedItem->id)->exists())->toBeTrue()
        ->and($completedCart->fresh()->status)->toBe(MonitoringCartStatus::Completed)
        ->and(Cart::query()->whereKey($windowCart->id)->exists())->toBeFalse()
        ->and(CartItem::query()->whereKey($windowItem->id)->exists())->toBeFalse();
});

test('A4 cleanup keeps an unregistered item inside the window cart even when its product_id matches the ledger study', function () {
    $user = a4MonitoringResidueCustomer();
    $args = a4MonitoringCleanupArgs();

    $this->artisan('akubica:a4-continuity-cart', $args + [
        '--apply' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ])->assertSuccessful();

    $windowCart = Cart::query()->where('user_id', $user->id)->where('type', MonitoringCartType::Lab)->where('status', MonitoringCartStatus::Active)->sole();
    $windowItem = CartItem::query()->where('cart_id', $windowCart->id)->sole();
    $unregistered = CartItem::query()->create([
        'cart_id' => $windowCart->id,
        'product_id' => $windowItem->product_id,
        'name' => 'unregistered same study inside the window cart',
        'price' => $windowItem->price,
        'quantity' => 1,
    ]);

    $this->artisan('akubica:a4-continuity-cart', $args + [
        '--cleanup' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ])->expectsOutputToContain('ITEM_NOT_IN_LEDGER_PRESERVED')->assertSuccessful();

    expect(Cart::query()->whereKey($windowCart->id)->exists())->toBeTrue()
        ->and(CartItem::query()->whereKey($unregistered->id)->exists())->toBeTrue()
        ->and(CartItem::query()->whereKey($windowItem->id)->exists())->toBeFalse()
        ->and(CartItem::query()->where('cart_id', $windowCart->id)->count())->toBe(1);
});

test('historical monitoring residue retirement rejects a completed cart and does not delete it', function () {
    $user = a4MonitoringResidueCustomer();
    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--apply' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ]))->assertSuccessful();

    $windowItem = CartItem::query()
        ->whereIn('cart_id', Cart::query()->where('user_id', $user->id)->where('type', MonitoringCartType::Lab)->pluck('id'))
        ->sole();

    $completedCart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab,
        'status' => MonitoringCartStatus::Completed,
        'total' => $windowItem->price,
    ]);
    $completedItem = CartItem::query()->create([
        'cart_id' => $completedCart->id,
        'product_id' => $windowItem->product_id,
        'name' => 'completed cart same study',
        'price' => $windowItem->price,
        'quantity' => 1,
    ]);

    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--retire-monitoring-residue' => true,
        '--execute' => true,
        '--cart-id' => $completedCart->id,
        '--cart-item-id' => $completedItem->id,
        '--confirm' => A4ContinuityCartFixturesCommand::RETIRE_MONITORING_CONFIRM,
    ]))->expectsOutputToContain('A4_MONITORING_RESIDUE_CART_COMPLETED')->assertFailed();

    expect(Cart::query()->whereKey($completedCart->id)->exists())->toBeTrue()
        ->and(CartItem::query()->whereKey($completedItem->id)->exists())->toBeTrue()
        ->and($completedCart->fresh()->status)->toBe(MonitoringCartStatus::Completed);
});

test('A4 apply then issue renews monitoring item IDs from sync and cleanup removes only the registered replacements', function () {
    $user = a4MonitoringResidueCustomer();
    $args = a4MonitoringCleanupArgs();

    $this->artisan('akubica:a4-continuity-cart', $args + [
        '--apply' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ])->assertSuccessful();

    $ledgerBefore = a4ReadWindowLedger();
    $idsBefore = $ledgerBefore['monitoring_cart_item_ids'];
    $cartId = (int) $ledgerBefore['monitoring_cart_id'];
    expect($idsBefore)->not->toBeEmpty();

    a4IssueWindowLink($user);

    $ledgerAfter = a4ReadWindowLedger();
    expect($ledgerAfter['monitoring_cart_id'])->toBe($cartId)
        ->and($ledgerAfter['customer_id'])->toBe($user->customer->id)
        ->and($ledgerAfter['test_id'])->toBe($ledgerBefore['test_id'])
        ->and($ledgerAfter['monitoring_cart_item_ids'])->not->toBe($idsBefore)
        ->and($ledgerAfter['monitoring_cart_item_ids'])->not->toBeEmpty();

    foreach ($idsBefore as $id) {
        expect(CartItem::query()->whereKey($id)->exists())->toBeFalse();
    }
    foreach ($ledgerAfter['monitoring_cart_item_ids'] as $id) {
        expect(CartItem::query()->whereKey($id)->exists())->toBeTrue()
            ->and((int) CartItem::query()->whereKey($id)->value('cart_id'))->toBe($cartId);
    }

    $this->artisan('akubica:a4-continuity-cart', $args + [
        '--cleanup' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ])->assertSuccessful();

    expect(Cart::query()->whereKey($cartId)->exists())->toBeFalse();
    foreach ($ledgerAfter['monitoring_cart_item_ids'] as $id) {
        expect(CartItem::query()->whereKey($id)->exists())->toBeFalse();
    }
});

test('A4 cleanup keeps a foreign item of the same study added after issue renews the ledger IDs', function () {
    $user = a4MonitoringResidueCustomer();
    $args = a4MonitoringCleanupArgs();

    $this->artisan('akubica:a4-continuity-cart', $args + [
        '--apply' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ])->assertSuccessful();
    a4IssueWindowLink($user);

    $ledger = a4ReadWindowLedger();
    $cartId = (int) $ledger['monitoring_cart_id'];
    $registeredIds = $ledger['monitoring_cart_item_ids'];
    $windowItem = CartItem::query()->whereKey($registeredIds[0])->first();
    $foreign = CartItem::query()->create([
        'cart_id' => $cartId,
        'product_id' => $windowItem->product_id,
        'name' => 'same study added after issue',
        'price' => $windowItem->price,
        'quantity' => 1,
    ]);

    $this->artisan('akubica:a4-continuity-cart', $args + [
        '--cleanup' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ])->expectsOutputToContain('ITEM_NOT_IN_LEDGER_PRESERVED')->assertSuccessful();

    expect(Cart::query()->whereKey($cartId)->exists())->toBeTrue()
        ->and(CartItem::query()->whereKey($foreign->id)->exists())->toBeTrue()
        ->and(CartItem::query()->where('cart_id', $cartId)->count())->toBe(1);
    foreach ($registeredIds as $id) {
        expect(CartItem::query()->whereKey($id)->exists())->toBeFalse();
    }
});

test('A4 apply rejects unaccredited Active-cart content before modifying it', function () {
    $user = a4MonitoringResidueCustomer();
    $preexisting = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab,
        'status' => MonitoringCartStatus::Active,
        'total' => 9,
    ]);
    $preexistingItem = CartItem::query()->create([
        'cart_id' => $preexisting->id,
        'product_id' => 'unaccredited-before-apply',
        'name' => 'present before apply',
        'price' => 9,
        'quantity' => 1,
    ]);

    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--apply' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ]))->expectsOutputToContain('A4_SYNTHETIC_SYNC_UNACCREDITED_CONTENT')->assertFailed();

    expect(Cart::query()->whereKey($preexisting->id)->exists())->toBeTrue()
        ->and(CartItem::query()->whereKey($preexistingItem->id)->exists())->toBeTrue()
        ->and(LaboratoryCartItem::query()->where('customer_id', $user->customer->id)->count())->toBe(0)
        ->and(a4ReadWindowLedger())->toBeNull();
});

test('A4 issue rejects unaccredited Active-cart content before modifying it', function () {
    $user = a4MonitoringResidueCustomer();
    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--apply' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ]))->assertSuccessful();

    $ledger = a4ReadWindowLedger();
    $registeredIds = $ledger['monitoring_cart_item_ids'];
    $windowItem = CartItem::query()->whereKey($registeredIds[0])->sole();
    $foreign = CartItem::query()->create([
        'cart_id' => $windowItem->cart_id,
        'product_id' => $windowItem->product_id,
        'name' => 'unaccredited before issue',
        'price' => $windowItem->price,
        'quantity' => 1,
    ]);

    expect(fn () => a4IssueWindowLink($user))
        ->toThrow(\DomainException::class, A4SyntheticMonitoringWindow::UNACCREDITED_CONTENT);

    expect(CartItem::query()->whereKey($foreign->id)->exists())->toBeTrue();
    foreach ($registeredIds as $id) {
        expect(CartItem::query()->whereKey($id)->exists())->toBeTrue();
    }
    expect(a4ReadWindowLedger()['monitoring_cart_item_ids'])->toBe($registeredIds);
});

test('A4 apply rolls back when ledger persistence fails and recovers on retry', function () {
    $user = a4MonitoringResidueCustomer();
    A4SyntheticMonitoringWindow::failNextDatabasePersist();

    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--apply' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ]))->expectsOutputToContain('A4_FIXTURE_LEDGER_PERSISTENCE_FAILED')->assertFailed();

    expect(LaboratoryCartItem::query()->where('customer_id', $user->customer->id)->count())->toBe(0)
        ->and(Cart::query()->where('user_id', $user->id)->where('type', MonitoringCartType::Lab)->count())->toBe(0)
        ->and(a4ReadWindowLedger())->toBeNull();

    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--apply' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ]))->assertSuccessful();

    $ledger = a4ReadWindowLedger();
    expect($ledger['customer_id'])->toBe($user->customer->id)
        ->and($ledger['monitoring_cart_item_ids'])->not->toBeEmpty()
        ->and(LaboratoryCartItem::query()->where('customer_id', $user->customer->id)->count())->toBe(1);
});

test('A4 apply does not report success when the ledger replica fails and recovers without adopting foreign items', function () {
    $user = a4MonitoringResidueCustomer();
    A4SyntheticMonitoringWindow::failNextReplica();

    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--apply' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ]))->expectsOutputToContain('A4_FIXTURE_LEDGER_REPLICA_PENDING')->assertFailed();

    $dbLedger = a4ReadWindowLedger();
    expect(LaboratoryCartItem::query()->where('customer_id', $user->customer->id)->count())->toBe(1)
        ->and($dbLedger['monitoring_cart_item_ids'])->not->toBeEmpty();
    $registeredIds = $dbLedger['monitoring_cart_item_ids'];

    $foreignCart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab,
        'status' => MonitoringCartStatus::Active,
        'total' => 3,
    ]);
    $foreignItem = CartItem::query()->create([
        'cart_id' => $foreignCart->id,
        'product_id' => (string) $dbLedger['test_id'],
        'name' => 'foreign during replica recovery',
        'price' => 3,
        'quantity' => 1,
    ]);

    $this->artisan('akubica:a4-continuity-cart', a4MonitoringCleanupArgs([
        '--apply' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ]))->assertSuccessful();

    $recovered = a4ReadWindowLedger();
    expect($recovered['monitoring_cart_item_ids'])->toBe($registeredIds)
        ->and(CartItem::query()->whereKey($foreignItem->id)->exists())->toBeTrue()
        ->and(Cart::query()->whereKey($foreignCart->id)->exists())->toBeTrue();
});

test('repeated A4 apply and issue do not adopt foreign carts or items of the same study', function () {
    $user = a4MonitoringResidueCustomer();
    $args = a4MonitoringCleanupArgs();

    $this->artisan('akubica:a4-continuity-cart', $args + [
        '--apply' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ])->assertSuccessful();

    $original = a4ReadWindowLedger();
    $otherCart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab,
        'status' => MonitoringCartStatus::Active,
        'total' => 8,
    ]);
    $otherItem = CartItem::query()->create([
        'cart_id' => $otherCart->id,
        'product_id' => (string) $original['test_id'],
        'name' => 'same study in another cart',
        'price' => 8,
        'quantity' => 1,
    ]);

    $this->artisan('akubica:a4-continuity-cart', $args + [
        '--apply' => true,
        '--confirm' => A4ContinuityCartFixturesCommand::CONFIRM,
    ])->assertSuccessful();

    expect(a4ReadWindowLedger()['monitoring_cart_item_ids'])->toBe($original['monitoring_cart_item_ids'])
        ->and(Cart::query()->whereKey($otherCart->id)->exists())->toBeTrue()
        ->and(CartItem::query()->whereKey($otherItem->id)->exists())->toBeTrue();

    a4IssueWindowLink($user);

    $renewed = a4ReadWindowLedger();
    expect($renewed['monitoring_cart_item_ids'])->not->toContain($otherItem->id)
        ->and($renewed['monitoring_cart_item_ids'])->not->toBe($original['monitoring_cart_item_ids'])
        ->and($renewed['monitoring_cart_id'])->toBe($original['monitoring_cart_id'])
        ->and(CartItem::query()->whereKey($otherItem->id)->exists())->toBeTrue()
        ->and(Cart::query()->whereKey($otherCart->id)->exists())->toBeTrue();
});
