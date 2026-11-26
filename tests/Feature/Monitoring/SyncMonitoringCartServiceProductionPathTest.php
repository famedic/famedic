<?php

/**
 * Characterization of the PRODUCTION path of SyncMonitoringCartService
 * (synthetic A4 window is null: a regular customer, no A4 fixture manifest).
 *
 * This file must stay free of A4-only types (A4SyntheticMonitoringWindow,
 * AkubicaUatFixtureContract, etc.) so the same content can run on the
 * base SHA 5a5ad14e16dd6fda9a6046abe1ef736f891bae85 and on the PR branch.
 */

use App\Actions\OnlinePharmacy\FetchProductAction;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryTest;
use App\Models\OnlinePharmacyCartItem;
use App\Models\User;
use App\Services\Monitoring\SyncMonitoringCartService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

function prodMonCustomer(): User
{
    return User::factory()->withRegularCustomer()->create();
}

function prodMonBindPharmacyCatalog(): void
{
    app()->bind(FetchProductAction::class, fn () => new class extends FetchProductAction
    {
        public function __invoke(string $productId): array
        {
            return [
                'name' => 'Vitau producto '.$productId,
                'price' => 12.5,
            ];
        }
    });
}

function prodMonAssertNoSideChannels(): void
{
    Queue::assertNothingPushed();
    Mail::assertNothingSent();
    Notification::assertNothingSent();
    expect(Http::recorded())->toHaveCount(0);
}

function prodMonCompletedCart(User $user, MonitoringCartType $type, string $productId, float $price): Cart
{
    $cart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => $type,
        'status' => MonitoringCartStatus::Completed,
        'total' => $price,
        'completed_at' => now()->subHour(),
    ]);
    CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_id' => $productId,
        'name' => 'completed '.$productId,
        'price' => $price,
        'quantity' => 1,
    ]);

    return $cart;
}

function prodMonAssertCompletedUntouched(Cart $cart, string $productId): void
{
    $fresh = $cart->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->status)->toBe(MonitoringCartStatus::Completed)
        ->and($fresh->completed_at)->not->toBeNull()
        ->and(CartItem::query()->where('cart_id', $cart->id)->pluck('product_id')->all())->toBe([$productId]);
}

beforeEach(function () {
    config()->set('scout.driver', 'null');
    config()->set('scout.queue', false);
    Http::fake();
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    Bus::fake();
    Notification::fake();
    prodMonBindPharmacyCatalog();
});

test('production laboratory sync with items creates and updates monitoring cart items', function () {
    $user = prodMonCustomer();
    $testA = LaboratoryTest::factory()->create([
        'name' => 'Glucosa en ayuno',
        'famedic_price_cents' => 15000,
    ]);
    $testB = LaboratoryTest::factory()->create([
        'name' => 'Perfil de lipidos',
        'famedic_price_cents' => 4000,
    ]);

    LaboratoryCartItem::withoutEvents(function () use ($user, $testA) {
        LaboratoryCartItem::factory()->create([
            'customer_id' => $user->customer->id,
            'laboratory_test_id' => $testA->id,
        ]);
    });

    expect(Cart::query()->where('user_id', $user->id)->where('type', MonitoringCartType::Lab)->count())->toBe(0);

    app(SyncMonitoringCartService::class)->syncLaboratory($user->customer->fresh());

    $cart = Cart::query()
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->where('status', MonitoringCartStatus::Active)
        ->sole();
    $item = CartItem::query()->where('cart_id', $cart->id)->sole();
    $priceA = numberCents(15000);

    expect($item->product_id)->toBe((string) $testA->id)
        ->and($item->name)->toBe('Glucosa en ayuno')
        ->and((float) $item->price)->toBe($priceA)
        ->and((int) $item->quantity)->toBe(1)
        ->and((float) $cart->total)->toBe($priceA);

    $testA->update([
        'name' => 'Glucosa actualizada',
        'famedic_price_cents' => 18000,
    ]);
    LaboratoryCartItem::withoutEvents(function () use ($user, $testB) {
        LaboratoryCartItem::factory()->create([
            'customer_id' => $user->customer->id,
            'laboratory_test_id' => $testB->id,
        ]);
    });

    app(SyncMonitoringCartService::class)->syncLaboratory($user->customer->fresh());

    $cart = $cart->fresh();
    $items = CartItem::query()->where('cart_id', $cart->id)->orderBy('name')->get();
    $priceA2 = numberCents(18000);
    $priceB = numberCents(4000);

    expect($items)->toHaveCount(2)
        ->and($items->pluck('product_id')->sort()->values()->all())->toBe(
            collect([(string) $testA->id, (string) $testB->id])->sort()->values()->all()
        )
        ->and($items->firstWhere('product_id', (string) $testA->id)->name)->toBe('Glucosa actualizada')
        ->and((float) $items->firstWhere('product_id', (string) $testA->id)->price)->toBe($priceA2)
        ->and($items->firstWhere('product_id', (string) $testB->id)->name)->toBe('Perfil de lipidos')
        ->and((float) $items->firstWhere('product_id', (string) $testB->id)->price)->toBe($priceB)
        ->and((int) $items->firstWhere('product_id', (string) $testB->id)->quantity)->toBe(1)
        ->and($cart->status)->toBe(MonitoringCartStatus::Active)
        ->and((float) $cart->total)->toBe(round($priceA2 + $priceB, 2));

    prodMonAssertNoSideChannels();
});

test('production empty laboratory path deletes Active lab carts and leaves Completed carts untouched', function () {
    $user = prodMonCustomer();
    $test = LaboratoryTest::factory()->create([
        'name' => 'Estudio vaciable',
        'famedic_price_cents' => 2500,
    ]);

    LaboratoryCartItem::withoutEvents(function () use ($user, $test) {
        LaboratoryCartItem::factory()->create([
            'customer_id' => $user->customer->id,
            'laboratory_test_id' => $test->id,
        ]);
    });
    app(SyncMonitoringCartService::class)->syncLaboratory($user->customer->fresh());

    $activeLabId = Cart::query()
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->where('status', MonitoringCartStatus::Active)
        ->value('id');
    expect($activeLabId)->not->toBeNull();
    $activeLabItemIds = CartItem::query()->where('cart_id', $activeLabId)->pluck('id')->all();
    expect($activeLabItemIds)->not->toBeEmpty();

    $completedLab = prodMonCompletedCart($user, MonitoringCartType::Lab, 'completed-lab', 19.0);
    $completedPharmacy = prodMonCompletedCart($user, MonitoringCartType::Pharmacy, 'completed-pharmacy', 21.0);
    $activePharmacy = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Pharmacy,
        'status' => MonitoringCartStatus::Active,
        'total' => 7,
    ]);
    CartItem::query()->create([
        'cart_id' => $activePharmacy->id,
        'product_id' => 'active-pharmacy-sibling',
        'name' => 'active pharmacy sibling',
        'price' => 7,
        'quantity' => 1,
    ]);

    LaboratoryCartItem::withoutEvents(function () use ($user) {
        LaboratoryCartItem::query()->where('customer_id', $user->customer->id)->forceDelete();
    });

    expect(Cart::query()->whereKey($activeLabId)->exists())->toBeTrue();

    app(SyncMonitoringCartService::class)->syncLaboratory($user->customer->fresh());

    expect(Cart::query()->whereKey($activeLabId)->exists())->toBeFalse();
    foreach ($activeLabItemIds as $id) {
        expect(CartItem::query()->whereKey($id)->exists())->toBeFalse();
    }
    prodMonAssertCompletedUntouched($completedLab, 'completed-lab');
    prodMonAssertCompletedUntouched($completedPharmacy, 'completed-pharmacy');
    expect(Cart::query()->whereKey($activePharmacy->id)->exists())->toBeTrue()
        ->and($activePharmacy->fresh()->status)->toBe(MonitoringCartStatus::Active)
        ->and(CartItem::query()->where('cart_id', $activePharmacy->id)->pluck('product_id')->all())->toBe(['active-pharmacy-sibling']);

    prodMonAssertNoSideChannels();
});

test('production syncPharmacy creates monitoring items and the pharmacy observer syncs on save and delete', function () {
    $user = prodMonCustomer();
    $completedPharmacy = prodMonCompletedCart($user, MonitoringCartType::Pharmacy, 'completed-pharmacy', 33.0);
    $completedLab = prodMonCompletedCart($user, MonitoringCartType::Lab, 'completed-lab', 34.0);

    expect(Cart::query()->where('user_id', $user->id)->where('type', MonitoringCartType::Pharmacy)->where('status', MonitoringCartStatus::Active)->count())->toBe(0);

    $pharmacyItem = OnlinePharmacyCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'vitau_product_id' => 555001,
        'quantity' => 3,
    ]);

    $cart = Cart::query()
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Pharmacy)
        ->where('status', MonitoringCartStatus::Active)
        ->sole();
    $item = CartItem::query()->where('cart_id', $cart->id)->sole();

    expect($item->product_id)->toBe('555001')
        ->and($item->name)->toBe('Vitau producto 555001')
        ->and((float) $item->price)->toBe(12.5)
        ->and((int) $item->quantity)->toBe(3)
        ->and((float) $cart->total)->toBe(37.5);

    $pharmacyItem->update(['quantity' => 4]);
    $cart = $cart->fresh();
    $item = CartItem::query()->where('cart_id', $cart->id)->sole();
    expect((int) $item->quantity)->toBe(4)
        ->and((float) $item->price)->toBe(12.5)
        ->and((float) $cart->total)->toBe(50.0)
        ->and($cart->status)->toBe(MonitoringCartStatus::Active);

    app(SyncMonitoringCartService::class)->syncPharmacy($user->customer->fresh());
    $cart = $cart->fresh();
    $item = CartItem::query()->where('cart_id', $cart->id)->sole();
    expect($item->product_id)->toBe('555001')
        ->and($item->name)->toBe('Vitau producto 555001')
        ->and((int) $item->quantity)->toBe(4)
        ->and((float) $cart->total)->toBe(50.0);

    $pharmacyItem->delete();

    expect(Cart::query()->whereKey($cart->id)->exists())->toBeFalse()
        ->and(CartItem::query()->where('cart_id', $cart->id)->count())->toBe(0);
    prodMonAssertCompletedUntouched($completedPharmacy, 'completed-pharmacy');
    prodMonAssertCompletedUntouched($completedLab, 'completed-lab');

    prodMonAssertNoSideChannels();
});

test('production markPharmacyCartCompleted marks the Active pharmacy cart completed and does not touch existing Completed carts', function () {
    $user = prodMonCustomer();
    $completedPharmacy = prodMonCompletedCart($user, MonitoringCartType::Pharmacy, 'completed-pharmacy', 41.0);
    $completedLab = prodMonCompletedCart($user, MonitoringCartType::Lab, 'completed-lab', 42.0);

    OnlinePharmacyCartItem::withoutEvents(function () use ($user) {
        OnlinePharmacyCartItem::factory()->create([
            'customer_id' => $user->customer->id,
            'vitau_product_id' => 555002,
            'quantity' => 2,
        ]);
    });

    app(SyncMonitoringCartService::class)->markPharmacyCartCompleted($user->customer->fresh());

    $cart = Cart::query()
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Pharmacy)
        ->where('status', MonitoringCartStatus::Completed)
        ->where('id', '!=', $completedPharmacy->id)
        ->sole();
    $item = CartItem::query()->where('cart_id', $cart->id)->sole();

    expect($item->product_id)->toBe('555002')
        ->and($item->name)->toBe('Vitau producto 555002')
        ->and((float) $item->price)->toBe(12.5)
        ->and((int) $item->quantity)->toBe(2)
        ->and((float) $cart->total)->toBe(25.0)
        ->and($cart->completed_at)->not->toBeNull();

    prodMonAssertCompletedUntouched($completedPharmacy, 'completed-pharmacy');
    prodMonAssertCompletedUntouched($completedLab, 'completed-lab');

    prodMonAssertNoSideChannels();
});
