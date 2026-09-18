<?php

use App\Enums\LaboratoryBrand;
use App\Models\Contact;
use App\Models\LaboratoryCapability;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryStoreHour;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutMiddleware([
        \App\Http\Middleware\RedirectIfUserProfileIsIncomplete::class,
        \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
        \App\Http\Middleware\EnsurePhoneIsVerified::class,
        \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
    ]);

    f7SeedCapabilities();
});

it('shares the selected compatible store with the checkout page', function (): void {
    $user = f7User();
    f7CartItem($user, f7Study('GLUCOSA EN SANGRE'));
    $store = f7Store('Swisslab Centro', LaboratoryBrand::SWISSLAB, ['laboratorio']);

    $this->actingAs($user)
        ->postJson(route('laboratory.checkout.selected-store.store', LaboratoryBrand::SWISSLAB), [
            'laboratory_store_id' => $store->id,
        ])
        ->assertOk();

    $this->actingAs($user)
        ->get(route('laboratory.checkout', LaboratoryBrand::SWISSLAB))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('LaboratoryCheckout')
            ->where('selectedLaboratoryStore.selected', true)
            ->where('selectedLaboratoryStore.store.id', $store->id)
            ->where('selectedLaboratoryStore.validation.status', 'valid')
        );
});

it('shows missing stale and invalid selection states without confirming an appointment store', function (): void {
    $user = f7User();
    f7CartItem($user, f7Study('GLUCOSA EN SANGRE'));
    $store = f7Store('Swisslab Centro', LaboratoryBrand::SWISSLAB, ['laboratorio']);

    $this->actingAs($user)
        ->get(route('laboratory.checkout', LaboratoryBrand::SWISSLAB))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedLaboratoryStore.selected', false)
            ->where('selectedLaboratoryStore.validation', null)
        );

    $this->actingAs($user)
        ->postJson(route('laboratory.checkout.selected-store.store', LaboratoryBrand::SWISSLAB), [
            'laboratory_store_id' => $store->id,
        ])
        ->assertOk();

    f7CartItem($user, f7Study('BIOMETRIA HEMATICA'));

    $this->actingAs($user)
        ->get(route('laboratory.checkout', LaboratoryBrand::SWISSLAB))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedLaboratoryStore.selected', false)
            ->where('selectedLaboratoryStore.validation.status', 'stale')
            ->where('selectedLaboratoryStore.validation.reason', 'cart_hash_changed')
        );

    $this->actingAs($user)
        ->postJson(route('laboratory.checkout.selected-store.store', LaboratoryBrand::SWISSLAB), [
            'laboratory_store_id' => $store->id,
        ])
        ->assertOk();

    $store->update(['is_active' => false]);

    $this->actingAs($user)
        ->get(route('laboratory.checkout', LaboratoryBrand::SWISSLAB))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedLaboratoryStore.selected', false)
            ->where('selectedLaboratoryStore.validation.status', 'invalid')
            ->where('selectedLaboratoryStore.validation.reason', 'branch_inactive')
        );

    expect(LaboratoryCheckoutDraft::query()
        ->where('customer_id', $user->customer->id)
        ->where('laboratory_brand', LaboratoryBrand::SWISSLAB->value)
        ->value('selected_laboratory_store_id'))->toBe($store->id);
});

it('allows advancing checkout after clearing a persisted postal code', function (): void {
    $user = f7User();
    f7CartItem($user, f7Study('GLUCOSA EN SANGRE'));
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::SWISSLAB->value,
            'postal_code' => '64000',
        ]))
        ->assertOk()
        ->assertJsonPath('data.meta.postal_code', '64000');

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::SWISSLAB->value,
            'clear_postal_code' => true,
        ]))
        ->assertOk()
        ->assertJsonPath('data.meta.postal_code', null);

    $this->actingAs($user)
        ->post(route('laboratory.checkout.draft.sync', LaboratoryBrand::SWISSLAB), [
            'step' => 'patient',
            'contact_id' => $contact->id,
        ])
        ->assertRedirect(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::SWISSLAB,
            'step' => 'address',
            'contact' => $contact->id,
        ]))
        ->assertSessionHasNoErrors();
});

it('allows advancing the checkout draft without selecting a store', function (): void {
    $user = f7User();
    f7CartItem($user, f7Study('GLUCOSA EN SANGRE'));
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);

    $this->actingAs($user)
        ->post(route('laboratory.checkout.draft.sync', LaboratoryBrand::SWISSLAB), [
            'step' => 'patient',
            'contact_id' => $contact->id,
        ])
        ->assertRedirect(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::SWISSLAB,
            'step' => 'address',
            'contact' => $contact->id,
        ]))
        ->assertSessionHasNoErrors();

    expect(LaboratoryCheckoutDraft::query()
        ->where('customer_id', $user->customer->id)
        ->where('laboratory_brand', LaboratoryBrand::SWISSLAB->value)
        ->value('contact_id'))->toBe($contact->id);
});

it('allows advancing the checkout draft with valid stale and invalid selected store states', function (): void {
    $user = f7User();
    f7CartItem($user, f7Study('GLUCOSA EN SANGRE'));

    $swissStore = f7Store('Swisslab Centro', LaboratoryBrand::SWISSLAB, ['laboratorio']);

    $this->actingAs($user)
        ->postJson(route('laboratory.checkout.selected-store.store', LaboratoryBrand::SWISSLAB), [
            'laboratory_store_id' => $swissStore->id,
        ])
        ->assertOk();

    $validContact = Contact::factory()->create(['customer_id' => $user->customer->id]);

    $this->actingAs($user)
        ->post(route('laboratory.checkout.draft.sync', LaboratoryBrand::SWISSLAB), [
            'step' => 'patient',
            'contact_id' => $validContact->id,
        ])
        ->assertRedirect(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::SWISSLAB,
            'step' => 'address',
            'contact' => $validContact->id,
        ]))
        ->assertSessionHasNoErrors();

    f7CartItem($user, f7Study('BIOMETRIA HEMATICA'));
    $staleContact = Contact::factory()->create(['customer_id' => $user->customer->id]);

    $this->actingAs($user)
        ->post(route('laboratory.checkout.draft.sync', LaboratoryBrand::SWISSLAB), [
            'step' => 'patient',
            'contact_id' => $staleContact->id,
        ])
        ->assertRedirect(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::SWISSLAB,
            'step' => 'address',
            'contact' => $staleContact->id,
        ]))
        ->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->postJson(route('laboratory.checkout.selected-store.store', LaboratoryBrand::SWISSLAB), [
            'laboratory_store_id' => $swissStore->id,
        ])
        ->assertOk();

    $swissStore->update(['is_active' => false]);
    $invalidContact = Contact::factory()->create(['customer_id' => $user->customer->id]);

    $this->actingAs($user)
        ->post(route('laboratory.checkout.draft.sync', LaboratoryBrand::SWISSLAB), [
            'step' => 'patient',
            'contact_id' => $invalidContact->id,
        ])
        ->assertRedirect(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::SWISSLAB,
            'step' => 'address',
            'contact' => $invalidContact->id,
        ]))
        ->assertSessionHasNoErrors();

    expect(LaboratoryCheckoutDraft::query()
        ->where('customer_id', $user->customer->id)
        ->where('laboratory_brand', LaboratoryBrand::SWISSLAB->value)
        ->value('contact_id'))->toBe($invalidContact->id);
});

it('keeps selected stores isolated by customer and laboratory brand in checkout', function (): void {
    $user = f7User();
    f7CartItem($user, f7Study('GLUCOSA SWISS', LaboratoryBrand::SWISSLAB));
    f7CartItem($user, f7Study('GLUCOSA OLAB', LaboratoryBrand::OLAB));

    $swissStore = f7Store('Swisslab Centro', LaboratoryBrand::SWISSLAB, ['laboratorio']);
    $olabStore = f7Store('Olab Centro', LaboratoryBrand::OLAB, ['laboratorio']);

    $this->actingAs($user)
        ->postJson(route('laboratory.checkout.selected-store.store', LaboratoryBrand::SWISSLAB), [
            'laboratory_store_id' => $swissStore->id,
        ])
        ->assertOk();

    $this->actingAs($user)
        ->postJson(route('laboratory.checkout.selected-store.store', LaboratoryBrand::OLAB), [
            'laboratory_store_id' => $olabStore->id,
        ])
        ->assertOk();

    $this->actingAs($user)
        ->get(route('laboratory.checkout', LaboratoryBrand::OLAB))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedLaboratoryStore.store.id', $olabStore->id)
            ->where('selectedLaboratoryStore.validation.status', 'valid')
        );
});

it('does not expose another customer selected store in checkout', function (): void {
    $firstUser = f7User();
    $secondUser = f7User();

    f7CartItem($firstUser, f7Study('GLUCOSA PRIMER CLIENTE'));
    f7CartItem($secondUser, f7Study('GLUCOSA SEGUNDO CLIENTE'));

    $store = f7Store('Swisslab Centro', LaboratoryBrand::SWISSLAB, ['laboratorio']);

    $this->actingAs($firstUser)
        ->postJson(route('laboratory.checkout.selected-store.store', LaboratoryBrand::SWISSLAB), [
            'laboratory_store_id' => $store->id,
        ])
        ->assertOk();

    $this->actingAs($secondUser)
        ->get(route('laboratory.checkout', LaboratoryBrand::SWISSLAB))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedLaboratoryStore.selected', false)
            ->where('selectedLaboratoryStore.store', null)
            ->where('selectedLaboratoryStore.validation', null)
        );
});

function f7User(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

function f7SeedCapabilities(): void
{
    collect([
        'laboratorio' => 'Laboratorio',
        'tomografia' => 'Tomografia',
        'papanicolaou' => 'Papanicolaou',
        'ultrasonido_convencional' => 'Ultrasonido Convencional',
        'ultrasonido_especial' => 'Ultrasonido Especial',
    ])->each(fn (string $name, string $slug) => LaboratoryCapability::query()->firstOrCreate(
        ['slug' => $slug],
        ['name' => $name, 'is_active' => true],
    ));
}

function f7Study(string $name, LaboratoryBrand $brand = LaboratoryBrand::SWISSLAB): LaboratoryTest
{
    $category = LaboratoryTestCategory::query()->firstOrCreate(['name' => 'Sanguíneo']);

    return LaboratoryTest::factory()->create([
        'brand' => $brand->value,
        'gda_id' => fake()->unique()->numerify('8#####'),
        'name' => $name,
        'laboratory_test_category_id' => $category->id,
    ]);
}

function f7CartItem(User $user, LaboratoryTest $test): LaboratoryCartItem
{
    return LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);
}

function f7Store(
    string $name,
    LaboratoryBrand $brand,
    array $capabilitySlugs,
    array $attributes = [],
): LaboratoryStore {
    $store = LaboratoryStore::query()->create(array_merge([
        'name' => $name,
        'brand' => $brand->value,
        'state' => 'Ciudad de Mexico',
        'address' => $name.' address',
        'weekly_hours' => '07:00-15:00',
        'saturday_hours' => '07:00-15:00',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.test',
        'is_active' => true,
        'phone' => '5512345678',
        'latitude' => '19.3902300',
        'longitude' => '-99.1740300',
    ], $attributes));

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
