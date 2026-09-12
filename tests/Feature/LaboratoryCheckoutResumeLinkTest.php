<?php

use App\Actions\Laboratories\GenerateLaboratoryCheckoutResumeLinkAction;
use App\Actions\Laboratories\PrepareCustomerLaboratoryCheckoutLinkAction;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Contact;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryCheckoutResumeLink;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryTest;
use App\Models\User;
use App\Services\Monitoring\SyncMonitoringCartService;

function resumeLinkUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

function resumeLinkActiveCart(User $user, LaboratoryBrand $brand = LaboratoryBrand::OLAB): Cart
{
    $test = LaboratoryTest::factory()->create([
        'brand' => $brand->value,
        'requires_appointment' => false,
        'famedic_price_cents' => 50000,
    ]);

    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);

    app(SyncMonitoringCartService::class)->syncLaboratory($user->customer);

    return app(SyncMonitoringCartService::class)
        ->activeLaboratoryCart($user->customer->fresh(), $brand)
        ->fresh(['items']);
}

function resumeLinkDraft(User $user, LaboratoryBrand $brand = LaboratoryBrand::OLAB, string $step = 'payment'): LaboratoryCheckoutDraft
{
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);

    return LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => $brand->value,
        'contact_id' => $contact->id,
        'address_id' => $address->id,
        'checkout_step' => $step,
        'payment_method' => 'paypal',
    ]);
}

function resumeLinkPurchase(User $user, Cart $cart, LaboratoryBrand $brand = LaboratoryBrand::OLAB): LaboratoryPurchase
{
    return LaboratoryPurchase::query()->create([
        'customer_id' => $user->customer->id,
        'cart_id' => $cart->id,
        'brand' => $brand->value,
        'gda_order_id' => 'gda-resume-'.fake()->unique()->numerify('######'),
        'name' => 'Paciente',
        'paternal_lastname' => 'Test',
        'maternal_lastname' => 'Resume',
        'phone' => '8111111111',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => null,
        'street' => 'Calle',
        'number' => '1',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo Leon',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => 50000,
    ]);
}

it('generates an opaque resume url without customer cart contact or address ids', function () {
    $user = resumeLinkUser();
    $cart = resumeLinkActiveCart($user);
    resumeLinkDraft($user);

    $url = app(GenerateLaboratoryCheckoutResumeLinkAction::class)->forCart($cart);

    expect($url)->toContain('/laboratory/checkout/resume/')
        ->and(parse_url($url, PHP_URL_QUERY))->toBeNull()
        ->and($url)->not->toContain('customer_id')
        ->and($url)->not->toContain('cart_id')
        ->and($url)->not->toContain('contact=')
        ->and($url)->not->toContain('address=');

    $stored = LaboratoryCheckoutResumeLink::query()->firstOrFail();
    expect($stored->token_hash)->toHaveLength(64)
        ->and($url)->not->toContain($stored->token_hash);
});

it('redirects the owner to protected checkout with the saved step and no internal ids in the redirect url', function () {
    $user = resumeLinkUser();
    $cart = resumeLinkActiveCart($user);
    resumeLinkDraft($user);

    $url = app(GenerateLaboratoryCheckoutResumeLinkAction::class)->forCart($cart);

    $response = $this->actingAs($user)->get($url);

    $response->assertRedirect(route('laboratory.checkout', [
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'step' => 'payment',
    ]));

    expect($response->headers->get('Location'))
        ->not->toContain('contact=')
        ->not->toContain('address=')
        ->not->toContain('cart_id=');
});

it('keeps the laboratory brand from the cart when resuming checkout', function () {
    $user = resumeLinkUser();
    $cart = resumeLinkActiveCart($user, LaboratoryBrand::SWISSLAB);
    resumeLinkDraft($user, LaboratoryBrand::SWISSLAB, 'patient');

    $url = app(GenerateLaboratoryCheckoutResumeLinkAction::class)->forCart($cart, LaboratoryBrand::SWISSLAB);

    $this->actingAs($user)
        ->get($url)
        ->assertRedirect(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::SWISSLAB,
            'step' => 'patient',
        ]));
});

it('preserves the intended resume destination when the user is not authenticated', function () {
    $user = resumeLinkUser();
    $cart = resumeLinkActiveCart($user);

    $url = app(GenerateLaboratoryCheckoutResumeLinkAction::class)->forCart($cart);

    $this->get($url)->assertRedirect(route('login'));

    expect(session()->get('url.intended'))->toBe($url);
});

it('rejects expired tokens safely', function () {
    $user = resumeLinkUser();
    $cart = resumeLinkActiveCart($user);
    $url = app(GenerateLaboratoryCheckoutResumeLinkAction::class)->forCart($cart);

    LaboratoryCheckoutResumeLink::query()->where('cart_id', $cart->id)->update([
        'expires_at' => now()->subMinute(),
    ]);

    $this->actingAs($user)
        ->get($url)
        ->assertRedirect(route('laboratory-brand-selection'));
});

it('rejects random tokens safely', function () {
    $user = resumeLinkUser();

    $this->actingAs($user)
        ->get(route('laboratory.checkout.resume', ['token' => str_repeat('a', 64)]))
        ->assertRedirect(route('laboratory-brand-selection'));
});

it('rejects manipulated tokens safely', function () {
    $user = resumeLinkUser();
    $cart = resumeLinkActiveCart($user);
    $url = app(GenerateLaboratoryCheckoutResumeLinkAction::class)->forCart($cart);
    $tampered = substr($url, 0, -1).(str_ends_with($url, 'a') ? 'b' : 'a');

    $this->actingAs($user)
        ->get($tampered)
        ->assertRedirect(route('laboratory-brand-selection'));
});

it('prevents another customer from using a valid resume token', function () {
    $owner = resumeLinkUser();
    $other = resumeLinkUser();
    $cart = resumeLinkActiveCart($owner);
    $url = app(GenerateLaboratoryCheckoutResumeLinkAction::class)->forCart($cart);

    $this->actingAs($other)
        ->get($url)
        ->assertForbidden();
});

it('allows the same valid link to be opened more than once while the cart is pending', function () {
    $user = resumeLinkUser();
    $cart = resumeLinkActiveCart($user);
    $url = app(GenerateLaboratoryCheckoutResumeLinkAction::class)->forCart($cart);

    $this->actingAs($user)
        ->get($url)
        ->assertRedirect(route('laboratory.checkout', ['laboratory_brand' => LaboratoryBrand::OLAB]));

    $this->actingAs($user)
        ->get($url)
        ->assertRedirect(route('laboratory.checkout', ['laboratory_brand' => LaboratoryBrand::OLAB]));

    expect(Cart::query()->where('user_id', $user->id)->where('status', MonitoringCartStatus::Active)->count())->toBe(1)
        ->and(LaboratoryCheckoutResumeLink::query()->where('cart_id', $cart->id)->count())->toBe(1);
});

it('does not reopen checkout when the cart already has a purchase', function () {
    $user = resumeLinkUser();
    $cart = resumeLinkActiveCart($user);
    $purchase = resumeLinkPurchase($user, $cart);
    $cart->update([
        'status' => MonitoringCartStatus::Completed,
        'completed_at' => now(),
    ]);
    $url = app(GenerateLaboratoryCheckoutResumeLinkAction::class)->forCart($cart);

    $this->actingAs($user)
        ->get($url)
        ->assertRedirect(route('laboratory-purchases.show', ['laboratory_purchase' => $purchase->id]));
});

it('reuses the same database row for repeated generations of the same cart', function () {
    $user = resumeLinkUser();
    $cart = resumeLinkActiveCart($user);
    $generator = app(GenerateLaboratoryCheckoutResumeLinkAction::class);

    $firstUrl = $generator->forCart($cart);
    $firstRow = LaboratoryCheckoutResumeLink::query()->where('cart_id', $cart->id)->firstOrFail();
    $secondUrl = $generator->forCart($cart);
    $secondRow = LaboratoryCheckoutResumeLink::query()->where('cart_id', $cart->id)->firstOrFail();

    expect(LaboratoryCheckoutResumeLink::query()->where('cart_id', $cart->id)->count())->toBe(1)
        ->and($secondRow->id)->toBe($firstRow->id)
        ->and($secondRow->token_hash)->not->toBe($firstRow->token_hash);

    $this->actingAs($user)
        ->get($firstUrl)
        ->assertRedirect(route('laboratory-brand-selection'));

    $this->actingAs($user)
        ->get($secondUrl)
        ->assertRedirect(route('laboratory.checkout', ['laboratory_brand' => LaboratoryBrand::OLAB]));
});

it('uses the secure resume url when preparing pending checkout links for external delivery', function () {
    $user = resumeLinkUser();
    resumeLinkActiveCart($user);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);

    $url = app(PrepareCustomerLaboratoryCheckoutLinkAction::class)(
        $user->customer,
        LaboratoryBrand::OLAB,
        $contact->id,
        'payment',
        $address->id,
    );

    expect($url)->toContain('/laboratory/checkout/resume/')
        ->and($url)->not->toContain('contact=')
        ->and($url)->not->toContain('address=');

    $this->assertDatabaseHas('laboratory_checkout_drafts', [
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::OLAB->value,
        'contact_id' => $contact->id,
        'address_id' => $address->id,
        'checkout_step' => 'payment',
    ]);
});
