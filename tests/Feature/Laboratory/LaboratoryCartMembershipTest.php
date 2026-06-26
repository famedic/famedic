<?php

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryTest;
use App\Models\User;
use App\Services\LaboratoryCartMembershipService;

beforeEach(function () {
    $this->withoutMiddleware([
        \App\Http\Middleware\RedirectIfEmptyLaboratoryCartItems::class,
        \App\Http\Middleware\RedirectIfUserProfileIsIncomplete::class,
        \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
        \App\Http\Middleware\EnsurePhoneIsVerified::class,
        \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
    ]);
});

function makeCartMembershipUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create([
            'documentation_accepted_at' => now(),
        ])
        ->fresh()
        ->load('customer');
}

function seedSwisslabCart(User $user): void
{
    $test = LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::SWISSLAB->value,
        'requires_appointment' => false,
        'famedic_price_cents' => 80000,
    ]);

    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);
}

test('customer can remove laboratory cart membership from shopping cart', function () {
    $user = makeCartMembershipUser();
    seedSwisslabCart($user);

    app(LaboratoryCartMembershipService::class)->add(
        $user->customer,
        LaboratoryBrand::SWISSLAB,
    );

    expect($user->customer->laboratoryCartMemberships()->count())->toBe(1);

    $response = $this->actingAs($user)
        ->from(route('laboratory.shopping-cart', [
            'laboratory_brand' => LaboratoryBrand::SWISSLAB,
        ]))
        ->delete(route('laboratory.cart-membership.destroy', [
            'laboratory_brand' => LaboratoryBrand::SWISSLAB,
        ]));

    $response->assertRedirect(route('laboratory.shopping-cart', [
        'laboratory_brand' => LaboratoryBrand::SWISSLAB,
    ]));

    expect($user->customer->fresh()->laboratoryCartMemberships()->count())->toBe(0);
});

test('customer can remove laboratory cart membership from checkout', function () {
    $user = makeCartMembershipUser();
    seedSwisslabCart($user);

    app(LaboratoryCartMembershipService::class)->add(
        $user->customer,
        LaboratoryBrand::SWISSLAB,
    );

    $checkoutUrl = route('laboratory.checkout', [
        'laboratory_brand' => LaboratoryBrand::SWISSLAB,
        'step' => 'confirmation',
    ]);

    $response = $this->actingAs($user)
        ->from($checkoutUrl)
        ->delete(route('laboratory.cart-membership.destroy', [
            'laboratory_brand' => LaboratoryBrand::SWISSLAB,
        ]));

    $response->assertRedirect($checkoutUrl);
    expect($user->customer->fresh()->laboratoryCartMemberships()->count())->toBe(0);
});

test('destroying cart membership is idempotent', function () {
    $user = makeCartMembershipUser();
    seedSwisslabCart($user);

    $response = $this->actingAs($user)
        ->from(route('laboratory.shopping-cart', [
            'laboratory_brand' => LaboratoryBrand::SWISSLAB,
        ]))
        ->delete(route('laboratory.cart-membership.destroy', [
            'laboratory_brand' => LaboratoryBrand::SWISSLAB,
        ]));

    $response->assertRedirect();
    expect($user->customer->fresh()->laboratoryCartMemberships()->count())->toBe(0);
});

test('guest cannot remove laboratory cart membership', function () {
    $this->delete(route('laboratory.cart-membership.destroy', [
        'laboratory_brand' => LaboratoryBrand::SWISSLAB,
    ]))->assertRedirect();
});
