<?php

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryCapability;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryStoreHour;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Models\User;

beforeEach(function (): void {
    $this->withoutMiddleware([
        \App\Http\Middleware\RedirectIfUserProfileIsIncomplete::class,
        \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
        \App\Http\Middleware\EnsurePhoneIsVerified::class,
        \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
    ]);

    f6bSeedCapabilities();
});

it('requires authentication to manage the selected checkout store', function (): void {
    $this->getJson(route('laboratory.checkout.selected-store.show', LaboratoryBrand::SWISSLAB))
        ->assertUnauthorized();

    $this->postJson(route('laboratory.checkout.selected-store.store', LaboratoryBrand::SWISSLAB), [
        'laboratory_store_id' => 1,
    ])->assertUnauthorized();

    $this->deleteJson(route('laboratory.checkout.selected-store.destroy', LaboratoryBrand::SWISSLAB))
        ->assertUnauthorized();
});

it('creates gets updates and deletes a selected store for the checkout draft', function (): void {
    $user = f6bUser();
    f6bCartItem($user, f6bStudy('GLUCOSA EN SANGRE'));
    $first = f6bStore('Swisslab Uno', LaboratoryBrand::SWISSLAB, ['laboratorio']);
    $second = f6bStore('Swisslab Dos', LaboratoryBrand::SWISSLAB, ['laboratorio']);

    $this->actingAs($user)
        ->postJson(route('laboratory.checkout.selected-store.store', LaboratoryBrand::SWISSLAB), [
            'laboratory_store_id' => $first->id,
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.selected', true)
        ->assertJsonPath('data.store.id', $first->id)
        ->assertJsonPath('data.validation.status', 'valid');

    $draft = LaboratoryCheckoutDraft::query()->firstWhere([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::SWISSLAB->value,
    ]);

    expect($draft)->not->toBeNull()
        ->and($draft->selected_laboratory_store_id)->toBe($first->id)
        ->and($draft->selected_laboratory_store_cart_hash)->not->toBeNull()
        ->and($draft->selected_laboratory_store_validated_at)->not->toBeNull();

    $this->actingAs($user)
        ->getJson(route('laboratory.checkout.selected-store.show', LaboratoryBrand::SWISSLAB))
        ->assertOk()
        ->assertJsonPath('data.selected', true)
        ->assertJsonPath('data.store.id', $first->id)
        ->assertJsonPath('data.validation.status', 'valid');

    $this->actingAs($user)
        ->postJson(route('laboratory.checkout.selected-store.store', LaboratoryBrand::SWISSLAB), [
            'laboratory_store_id' => $second->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.store.id', $second->id);

    expect(LaboratoryCheckoutDraft::query()->where('customer_id', $user->customer->id)->count())->toBe(1);

    $this->actingAs($user)
        ->deleteJson(route('laboratory.checkout.selected-store.destroy', LaboratoryBrand::SWISSLAB))
        ->assertOk()
        ->assertJsonPath('data.selected', false)
        ->assertJsonPath('data.store', null);

    $draft->refresh();
    expect($draft->selected_laboratory_store_id)->toBeNull()
        ->and($draft->selected_laboratory_store_cart_hash)->toBeNull()
        ->and($draft->selected_laboratory_store_validated_at)->toBeNull();
});

it('rejects invalid inactive wrong-brand incompatible and empty-cart selections', function (): void {
    $user = f6bUser();
    f6bCartItem($user, f6bStudy('GLUCOSA EN SANGRE'));

    $inactive = f6bStore('Inactiva', LaboratoryBrand::SWISSLAB, ['laboratorio'], ['is_active' => false]);
    $otherBrand = f6bStore('Olab Lab', LaboratoryBrand::OLAB, ['laboratorio']);
    $incompatible = f6bStore('Swiss Tomo', LaboratoryBrand::SWISSLAB, ['tomografia']);

    $this->actingAs($user)
        ->postJson(route('laboratory.checkout.selected-store.store', LaboratoryBrand::SWISSLAB), [
            'laboratory_store_id' => 999999,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('laboratory_store_id');

    $this->actingAs($user)
        ->postJson(route('laboratory.checkout.selected-store.store', LaboratoryBrand::SWISSLAB), [
            'laboratory_store_id' => $inactive->id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('data.reason', 'branch_inactive');

    $this->actingAs($user)
        ->postJson(route('laboratory.checkout.selected-store.store', LaboratoryBrand::SWISSLAB), [
            'laboratory_store_id' => $otherBrand->id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('data.reason', 'branch_brand_mismatch');

    $this->actingAs($user)
        ->postJson(route('laboratory.checkout.selected-store.store', LaboratoryBrand::SWISSLAB), [
            'laboratory_store_id' => $incompatible->id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('data.reason', 'branch_not_compatible');

    $emptyUser = f6bUser();
    $compatible = f6bStore('Swiss Lab', LaboratoryBrand::SWISSLAB, ['laboratorio']);

    $this->actingAs($emptyUser)
        ->postJson(route('laboratory.checkout.selected-store.store', LaboratoryBrand::SWISSLAB), [
            'laboratory_store_id' => $compatible->id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No hay estudios en este carrito para seleccionar una sucursal.')
        ->assertJsonPath('data.reason', 'empty_cart');
});

it('detects stale selections when the brand cart hash changes', function (): void {
    $user = f6bUser();
    f6bCartItem($user, f6bStudy('GLUCOSA EN SANGRE'));
    $store = f6bStore('Swisslab Lab', LaboratoryBrand::SWISSLAB, ['laboratorio']);

    $this->actingAs($user)
        ->postJson(route('laboratory.checkout.selected-store.store', LaboratoryBrand::SWISSLAB), [
            'laboratory_store_id' => $store->id,
        ])
        ->assertOk();

    f6bCartItem($user, f6bStudy('BIOMETRIA HEMATICA'));

    $this->actingAs($user)
        ->getJson(route('laboratory.checkout.selected-store.show', LaboratoryBrand::SWISSLAB))
        ->assertOk()
        ->assertJsonPath('data.selected', false)
        ->assertJsonPath('data.store', null)
        ->assertJsonPath('data.validation.status', 'stale');
});

it('keeps selected stores isolated by customer and laboratory brand', function (): void {
    $firstUser = f6bUser();
    $secondUser = f6bUser();

    f6bCartItem($firstUser, f6bStudy('GLUCOSA EN SANGRE', LaboratoryBrand::SWISSLAB));
    f6bCartItem($firstUser, f6bStudy('GLUCOSA OLAB', LaboratoryBrand::OLAB));
    f6bCartItem($secondUser, f6bStudy('GLUCOSA OTRO CLIENTE', LaboratoryBrand::SWISSLAB));

    $swissStore = f6bStore('Swisslab Lab', LaboratoryBrand::SWISSLAB, ['laboratorio']);
    $olabStore = f6bStore('Olab Lab', LaboratoryBrand::OLAB, ['laboratorio']);

    $this->actingAs($firstUser)
        ->postJson(route('laboratory.checkout.selected-store.store', LaboratoryBrand::SWISSLAB), [
            'laboratory_store_id' => $swissStore->id,
            'customer_id' => $secondUser->customer->id,
        ])
        ->assertOk();

    $this->actingAs($firstUser)
        ->postJson(route('laboratory.checkout.selected-store.store', LaboratoryBrand::OLAB), [
            'laboratory_store_id' => $olabStore->id,
        ])
        ->assertOk();

    $this->actingAs($secondUser)
        ->getJson(route('laboratory.checkout.selected-store.show', LaboratoryBrand::SWISSLAB))
        ->assertOk()
        ->assertJsonPath('data.selected', false);

    expect(LaboratoryCheckoutDraft::query()
        ->where('customer_id', $firstUser->customer->id)
        ->where('laboratory_brand', LaboratoryBrand::SWISSLAB->value)
        ->value('selected_laboratory_store_id'))->toBe($swissStore->id)
        ->and(LaboratoryCheckoutDraft::query()
            ->where('customer_id', $firstUser->customer->id)
            ->where('laboratory_brand', LaboratoryBrand::OLAB->value)
            ->value('selected_laboratory_store_id'))->toBe($olabStore->id)
        ->and(LaboratoryCheckoutDraft::query()
            ->where('customer_id', $secondUser->customer->id)
            ->exists())->toBeFalse();
});

function f6bUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

function f6bSeedCapabilities(): void
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

function f6bStudy(string $name, LaboratoryBrand $brand = LaboratoryBrand::SWISSLAB): LaboratoryTest
{
    $category = LaboratoryTestCategory::query()->firstOrCreate(['name' => 'Sanguíneo']);

    return LaboratoryTest::factory()->create([
        'brand' => $brand->value,
        'gda_id' => fake()->unique()->numerify('7#####'),
        'name' => $name,
        'laboratory_test_category_id' => $category->id,
    ]);
}

function f6bCartItem(User $user, LaboratoryTest $test): LaboratoryCartItem
{
    return LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);
}

function f6bStore(
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
