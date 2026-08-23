<?php

use App\Actions\Laboratories\FulfillLaboratoryCartOrderAction;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\Address;
use App\Models\Contact;
use App\Models\Documentation;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryTest;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();

    Documentation::query()->create([
        'privacy_policy' => 'Privacy policy test.',
        'terms_of_service' => 'Terms of service test.',
    ]);
});

function pendingPurchasesUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create([
            'documentation_accepted_at' => now(),
        ])
        ->fresh(['customer']);
}

function addLaboratoryCartItem(User $user, LaboratoryBrand $brand, array $testAttributes = []): LaboratoryCartItem
{
    $test = LaboratoryTest::factory()->create(array_merge([
        'brand' => $brand->value,
        'requires_appointment' => false,
        'public_price_cents' => 50000,
        'famedic_price_cents' => 39900,
    ], $testAttributes));

    return LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);
}

function draftForPendingPurchase(User $user, LaboratoryBrand $brand, string $step): LaboratoryCheckoutDraft
{
    return LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => $brand->value,
        'checkout_step' => $step,
    ]);
}

test('ownership starts from authenticated customer only', function () {
    $userA = pendingPurchasesUser();
    $userB = pendingPurchasesUser();

    addLaboratoryCartItem($userA, LaboratoryBrand::SWISSLAB);

    $this->actingAs($userB)
        ->get(route('user.purchases.index', [
            'customer_id' => $userA->customer->id,
            'user_id' => $userA->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('User/Purchases')
            ->where('pendingPurchases', [])
            ->where('summary.total', 0)
        );
});

test('single laboratory brand with payment draft is checkout in progress', function () {
    $user = pendingPurchasesUser();
    $brand = LaboratoryBrand::SWISSLAB;

    addLaboratoryCartItem($user, $brand);
    addLaboratoryCartItem($user, $brand);
    addLaboratoryCartItem($user, $brand);
    draftForPendingPurchase($user, $brand, 'payment');

    $this->actingAs($user)
        ->get(route('user.purchases.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('User/Purchases')
            ->has('pendingPurchases', 1)
            ->where('pendingPurchases.0.key', 'laboratory:swisslab')
            ->where('pendingPurchases.0.status', 'checkout_in_progress')
            ->where('pendingPurchases.0.checkout.step', 'payment')
            ->where('pendingPurchases.0.items_count', 3)
        );
});

test('laboratory brands are independent pending purchases', function () {
    $user = pendingPurchasesUser();

    addLaboratoryCartItem($user, LaboratoryBrand::SWISSLAB);
    addLaboratoryCartItem($user, LaboratoryBrand::OLAB);

    $this->actingAs($user)
        ->get(route('user.purchases.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('pendingPurchases', 2)
            ->where('summary.total', 2)
            ->where('summary.items', 2)
        );
});

test('checkout steps use four steps without appointment and five with appointment', function () {
    $user = pendingPurchasesUser();

    addLaboratoryCartItem($user, LaboratoryBrand::SWISSLAB, ['requires_appointment' => false]);
    addLaboratoryCartItem($user, LaboratoryBrand::OLAB, ['requires_appointment' => true]);
    draftForPendingPurchase($user, LaboratoryBrand::SWISSLAB, 'payment');
    draftForPendingPurchase($user, LaboratoryBrand::OLAB, 'payment');

    $this->actingAs($user)
        ->get(route('user.purchases.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pendingPurchases', fn ($rows) => collect($rows)
                ->pluck('checkout.total_steps')
                ->sort()
                ->values()
                ->all() === [4, 5])
        );
});

test('orphan draft without operational items is not shown', function () {
    $user = pendingPurchasesUser();

    draftForPendingPurchase($user, LaboratoryBrand::SWISSLAB, 'payment');

    $this->actingAs($user)
        ->get(route('user.purchases.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pendingPurchases', [])
            ->where('summary.total', 0)
        );
});

test('cart items without draft are shown as saved cart', function () {
    $user = pendingPurchasesUser();

    addLaboratoryCartItem($user, LaboratoryBrand::SWISSLAB);

    $this->actingAs($user)
        ->get(route('user.purchases.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pendingPurchases.0.status', 'cart_saved')
            ->where('summary.carts', 1)
        );
});

test('buying one laboratory brand preserves other brand items and draft', function () {
    $user = pendingPurchasesUser();
    $customer = $user->customer;
    $swisslab = LaboratoryBrand::SWISSLAB;
    $olab = LaboratoryBrand::OLAB;

    addLaboratoryCartItem($user, $swisslab, ['famedic_price_cents' => 10000]);
    addLaboratoryCartItem($user, $swisslab, ['famedic_price_cents' => 20000]);
    $olabItem = addLaboratoryCartItem($user, $olab, ['famedic_price_cents' => 30000]);
    draftForPendingPurchase($user, $swisslab, 'payment');
    $olabDraft = draftForPendingPurchase($user, $olab, 'patient');

    $address = Address::factory()->create(['customer_id' => $customer->id]);
    $contact = Contact::factory()->create(['customer_id' => $customer->id]);
    $transaction = Transaction::factory()->create([
        'transaction_amount_cents' => 30000,
        'payment_method' => 'odessa',
        'reference_id' => 'test-swisslab-payment',
    ]);

    $swisslabItems = $customer->laboratoryCartItems()
        ->ofBrand($swisslab)
        ->with('laboratoryTest')
        ->get();

    app(FulfillLaboratoryCartOrderAction::class)(
        $customer,
        $swisslab,
        $address,
        $contact,
        $transaction,
        null,
        $swisslabItems,
        $swisslab->value,
    );

    expect($customer->laboratoryCartItems()->ofBrand($swisslab)->count())->toBe(0);
    expect($customer->laboratoryCartItems()->ofBrand($olab)->pluck('id')->all())->toBe([$olabItem->id]);
    expect(LaboratoryCheckoutDraft::query()->whereKey($olabDraft->id)->exists())->toBeTrue();
    expect(LaboratoryCheckoutDraft::query()
        ->where('customer_id', $customer->id)
        ->where('laboratory_brand', $swisslab->value)
        ->exists())->toBeFalse();

    $this->actingAs($user)
        ->get(route('user.purchases.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('pendingPurchases', 1)
            ->where('pendingPurchases.0.key', 'laboratory:olab')
        );
});

test('continue urls point to cart for saved carts and checkout for drafts', function () {
    $user = pendingPurchasesUser();

    addLaboratoryCartItem($user, LaboratoryBrand::SWISSLAB);
    addLaboratoryCartItem($user, LaboratoryBrand::OLAB);
    draftForPendingPurchase($user, LaboratoryBrand::OLAB, 'payment');

    $this->actingAs($user)
        ->get(route('user.purchases.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pendingPurchases', fn ($rows) => collect($rows)
                ->contains(fn (array $row) => $row['key'] === 'laboratory:olab'
                    && $row['urls']['continue'] === route('laboratory.checkout', [
                        'laboratory_brand' => LaboratoryBrand::OLAB,
                        'step' => 'payment',
                    ]))
                && collect($rows)->contains(fn (array $row) => $row['key'] === 'laboratory:swisslab'
                    && $row['urls']['continue'] === route('laboratory.shopping-cart', [
                        'laboratory_brand' => LaboratoryBrand::SWISSLAB,
                    ])))
        );
});

test('completed laboratory purchase without operational items is not shown', function () {
    $user = pendingPurchasesUser();

    LaboratoryPurchase::query()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::SWISSLAB,
        'gda_order_id' => 123456,
        'name' => 'Juan',
        'paternal_lastname' => 'Perez',
        'maternal_lastname' => 'Lopez',
        'phone' => '8112345678',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => Gender::MALE,
        'street' => 'Calle 1',
        'number' => '100',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo Leon',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => 10000,
    ]);

    $this->actingAs($user)
        ->get(route('user.purchases.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pendingPurchases', [])
            ->where('summary.total', 0)
        );
});
