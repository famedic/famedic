<?php

use App\Actions\Laboratories\FulfillLaboratoryCartOrderAction;
use App\Enums\LaboratoryBrand;
use App\Models\Address;
use App\Models\Contact;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCapability;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryStoreHour;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Queue::fake();
    preferredStoreSeedCapabilities();
});

it('persists null preferred store when checkout draft has no selected store', function (): void {
    $user = preferredStoreUser();
    preferredStoreCartItem($user, preferredStoreStudy('GLUCOSA EN SANGRE'));

    $purchase = preferredStoreFulfill($user, LaboratoryBrand::SWISSLAB);

    expect($purchase->preferred_laboratory_store_id)->toBeNull();
});

it('persists preferred store from checkout draft during fulfillment', function (): void {
    $user = preferredStoreUser();
    preferredStoreCartItem($user, preferredStoreStudy('GLUCOSA EN SANGRE'));
    $store = preferredStoreBranch('Alamos', LaboratoryBrand::SWISSLAB);

    preferredStoreDraft($user, LaboratoryBrand::SWISSLAB, $store);

    $purchase = preferredStoreFulfill($user, LaboratoryBrand::SWISSLAB);

    expect($purchase->fresh()->preferred_laboratory_store_id)->toBe($store->id);
});

it('keeps preferred store after checkout draft is deleted', function (): void {
    $user = preferredStoreUser();
    preferredStoreCartItem($user, preferredStoreStudy('GLUCOSA EN SANGRE'));
    $store = preferredStoreBranch('Alamos', LaboratoryBrand::SWISSLAB);

    preferredStoreDraft($user, LaboratoryBrand::SWISSLAB, $store);

    $purchase = preferredStoreFulfill($user, LaboratoryBrand::SWISSLAB);

    expect(LaboratoryCheckoutDraft::query()
        ->where('customer_id', $user->customer->id)
        ->where('laboratory_brand', LaboratoryBrand::SWISSLAB->value)
        ->exists())->toBeFalse()
        ->and($purchase->fresh()->preferred_laboratory_store_id)->toBe($store->id);
});

it('keeps preferred and confirmed appointment stores independent', function (): void {
    $user = preferredStoreUser();
    preferredStoreCartItem($user, preferredStoreStudy('TOMOGRAFIA', requiresAppointment: true));
    $preferred = preferredStoreBranch('Alamos', LaboratoryBrand::SWISSLAB);
    $confirmed = preferredStoreBranch('Valle Oriente', LaboratoryBrand::SWISSLAB);

    preferredStoreDraft($user, LaboratoryBrand::SWISSLAB, $preferred);

    $appointment = LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::SWISSLAB->value,
        'laboratory_store_id' => $confirmed->id,
    ]);

    $purchase = preferredStoreFulfill($user, LaboratoryBrand::SWISSLAB, $appointment);

    $purchase->refresh();
    $appointment->refresh();

    expect($purchase->preferred_laboratory_store_id)->toBe($preferred->id)
        ->and($appointment->laboratory_store_id)->toBe($confirmed->id);
});

it('persists preferred and confirmed stores separately even when they match', function (): void {
    $user = preferredStoreUser();
    preferredStoreCartItem($user, preferredStoreStudy('TOMOGRAFIA', requiresAppointment: true));
    $store = preferredStoreBranch('Alamos', LaboratoryBrand::SWISSLAB);

    preferredStoreDraft($user, LaboratoryBrand::SWISSLAB, $store);

    $appointment = LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::SWISSLAB->value,
        'laboratory_store_id' => $store->id,
    ]);

    $purchase = preferredStoreFulfill($user, LaboratoryBrand::SWISSLAB, $appointment);

    $purchase->refresh();
    $appointment->refresh();

    expect($purchase->preferred_laboratory_store_id)->toBe($store->id)
        ->and($appointment->laboratory_store_id)->toBe($store->id);
});

it('keeps preferred null when only confirmed appointment store exists', function (): void {
    $user = preferredStoreUser();
    preferredStoreCartItem($user, preferredStoreStudy('TOMOGRAFIA', requiresAppointment: true));
    $confirmed = preferredStoreBranch('Valle Oriente', LaboratoryBrand::SWISSLAB);

    $appointment = LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::SWISSLAB->value,
        'laboratory_store_id' => $confirmed->id,
    ]);

    $purchase = preferredStoreFulfill($user, LaboratoryBrand::SWISSLAB, $appointment);

    expect($purchase->preferred_laboratory_store_id)->toBeNull()
        ->and($purchase->laboratoryAppointment->laboratory_store_id)->toBe($confirmed->id);
});

it('resolves soft deleted preferred store for historical purchase reads', function (): void {
    $user = preferredStoreUser();
    preferredStoreCartItem($user, preferredStoreStudy('GLUCOSA EN SANGRE'));
    $store = preferredStoreBranch('Alamos', LaboratoryBrand::SWISSLAB);

    preferredStoreDraft($user, LaboratoryBrand::SWISSLAB, $store);

    $purchase = preferredStoreFulfill($user, LaboratoryBrand::SWISSLAB);
    $store->delete();

    $historical = LaboratoryPurchase::query()
        ->with('preferredLaboratoryStore')
        ->findOrFail($purchase->id);

    expect($historical->preferredLaboratoryStore)->not->toBeNull()
        ->and($historical->preferredLaboratoryStore->name)->toBe('Alamos')
        ->and($historical->preferredLaboratoryStore->trashed())->toBeTrue();
});

it('persists stale selected store snapshots without blocking fulfillment', function (): void {
    $user = preferredStoreUser();
    preferredStoreCartItem($user, preferredStoreStudy('GLUCOSA EN SANGRE'));
    $store = preferredStoreBranch('Alamos', LaboratoryBrand::SWISSLAB);

    LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::SWISSLAB->value,
        'selected_laboratory_store_id' => $store->id,
        'selected_laboratory_store_validated_at' => now()->subHour(),
        'selected_laboratory_store_cart_hash' => 'stale-cart-hash',
    ]);

    preferredStoreCartItem($user, preferredStoreStudy('BIOMETRIA HEMATICA'));

    $purchase = preferredStoreFulfill($user, LaboratoryBrand::SWISSLAB);

    expect($purchase->fresh()->preferred_laboratory_store_id)->toBe($store->id);
});

it('reads preferred store only from the matching laboratory brand draft', function (): void {
    $user = preferredStoreUser();
    preferredStoreCartItem($user, preferredStoreStudy('GLUCOSA SWISS', LaboratoryBrand::SWISSLAB));
    preferredStoreCartItem($user, preferredStoreStudy('GLUCOSA OLAB', LaboratoryBrand::OLAB));

    $swissStore = preferredStoreBranch('Swiss Alamos', LaboratoryBrand::SWISSLAB);
    $olabStore = preferredStoreBranch('Olab Centro', LaboratoryBrand::OLAB);

    preferredStoreDraft($user, LaboratoryBrand::SWISSLAB, $swissStore);
    preferredStoreDraft($user, LaboratoryBrand::OLAB, $olabStore);

    $swissPurchase = preferredStoreFulfill($user, LaboratoryBrand::SWISSLAB);

    expect($swissPurchase->fresh()->preferred_laboratory_store_id)->toBe($swissStore->id)
        ->and(LaboratoryCheckoutDraft::query()
            ->where('customer_id', $user->customer->id)
            ->where('laboratory_brand', LaboratoryBrand::OLAB->value)
            ->exists())->toBeTrue()
        ->and(LaboratoryCheckoutDraft::query()
            ->where('customer_id', $user->customer->id)
            ->where('laboratory_brand', LaboratoryBrand::OLAB->value)
            ->value('selected_laboratory_store_id'))->toBe($olabStore->id);
});

it('does not copy preferred store into appointment store during fulfillment', function (): void {
    $user = preferredStoreUser();
    preferredStoreCartItem($user, preferredStoreStudy('TOMOGRAFIA', requiresAppointment: true));
    $preferred = preferredStoreBranch('Alamos', LaboratoryBrand::SWISSLAB);

    preferredStoreDraft($user, LaboratoryBrand::SWISSLAB, $preferred);

    $appointment = LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::SWISSLAB->value,
        'laboratory_store_id' => null,
    ]);

    preferredStoreFulfill($user, LaboratoryBrand::SWISSLAB, $appointment);

    expect($appointment->fresh()->laboratory_store_id)->toBeNull()
        ->and($appointment->fresh()->laboratory_purchase_id)->not->toBeNull();
});

it('eager loads preferred store on purchase show', function (): void {
    $user = preferredStoreUser();
    preferredStoreCartItem($user, preferredStoreStudy('GLUCOSA EN SANGRE'));
    $store = preferredStoreBranch('Alamos', LaboratoryBrand::SWISSLAB);

    preferredStoreDraft($user, LaboratoryBrand::SWISSLAB, $store);

    $purchase = preferredStoreFulfill($user, LaboratoryBrand::SWISSLAB);

    $this->actingAs($user)
        ->get(route('laboratory-purchases.show', $purchase))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('LaboratoryPurchase')
            ->where('laboratoryPurchase.preferred_laboratory_store_id', $store->id)
            ->where('laboratoryPurchase.preferred_laboratory_store.id', $store->id)
            ->where('laboratoryPurchase.preferred_laboratory_store.name', 'Alamos')
        );
});

function preferredStoreUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

function preferredStoreSeedCapabilities(): void
{
    collect([
        'laboratorio' => 'Laboratorio',
        'tomografia' => 'Tomografia',
    ])->each(fn (string $name, string $slug) => LaboratoryCapability::query()->firstOrCreate(
        ['slug' => $slug],
        ['name' => $name, 'is_active' => true],
    ));
}

function preferredStoreStudy(
    string $name,
    LaboratoryBrand $brand = LaboratoryBrand::SWISSLAB,
    bool $requiresAppointment = false,
): LaboratoryTest {
    $category = LaboratoryTestCategory::query()->firstOrCreate(['name' => 'Sanguíneo']);

    return LaboratoryTest::factory()->create([
        'brand' => $brand->value,
        'gda_id' => fake()->unique()->numerify('7#####'),
        'name' => $name,
        'requires_appointment' => $requiresAppointment,
        'famedic_price_cents' => 39900,
        'laboratory_test_category_id' => $category->id,
    ]);
}

function preferredStoreCartItem(User $user, LaboratoryTest $test): LaboratoryCartItem
{
    return LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);
}

function preferredStoreBranch(
    string $name,
    LaboratoryBrand $brand,
    array $capabilitySlugs = ['laboratorio'],
): LaboratoryStore {
    $store = LaboratoryStore::query()->create([
        'name' => $name,
        'brand' => $brand->value,
        'state' => 'Nuevo León',
        'address' => $name.' address',
        'weekly_hours' => '07:00-15:00',
        'saturday_hours' => '07:00-15:00',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.test/'.$name,
        'is_active' => true,
        'phone' => '5512345678',
        'latitude' => '19.3902300',
        'longitude' => '-99.1740300',
    ]);

    $store->capabilities()->attach(
        LaboratoryCapability::query()->whereIn('slug', $capabilitySlugs)->pluck('id')->all(),
    );

    foreach (range(1, 7) as $day) {
        LaboratoryStoreHour::query()->create([
            'laboratory_store_id' => $store->id,
            'day_of_week' => $day,
            'opens_at' => $day === 7 ? null : '07:00:00',
            'closes_at' => $day === 7 ? null : '15:00:00',
            'is_closed' => $day === 7,
            'raw_text' => $day === 7 ? 'Cerrado' : '07:00-15:00',
        ]);
    }

    return $store;
}

function preferredStoreDraft(User $user, LaboratoryBrand $brand, LaboratoryStore $store): LaboratoryCheckoutDraft
{
    return LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => $brand->value,
        'selected_laboratory_store_id' => $store->id,
        'selected_laboratory_store_validated_at' => now(),
        'selected_laboratory_store_cart_hash' => 'valid-cart-hash',
    ]);
}

function preferredStoreFulfill(
    User $user,
    LaboratoryBrand $brand,
    ?LaboratoryAppointment $appointment = null,
): LaboratoryPurchase {
    $customer = $user->customer;
    $address = Address::factory()->create(['customer_id' => $customer->id]);
    $contact = Contact::factory()->create(['customer_id' => $customer->id]);
    $items = $customer->laboratoryCartItems()
        ->ofBrand($brand)
        ->with('laboratoryTest')
        ->get();

    $transaction = Transaction::factory()->create([
        'transaction_amount_cents' => (int) $items->sum(fn ($item) => $item->laboratoryTest->famedic_price_cents),
        'payment_method' => 'odessa',
        'reference_id' => 'preferred-store-'.fake()->uuid(),
    ]);

    return app(FulfillLaboratoryCartOrderAction::class)(
        $customer,
        $brand,
        $address,
        $contact,
        $transaction,
        $appointment,
        $items,
        $brand->value,
    );
}
