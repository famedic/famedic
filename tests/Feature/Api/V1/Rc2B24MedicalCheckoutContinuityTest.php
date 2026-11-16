<?php

// RC2 B24 (D2): Leo -> FAMEDIC medical (membership) checkout continuity. LOCAL tests on MySQL 8.
use App\Models\AkubicaCheckoutLink;
use App\Models\MedicalServiceCartItem;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\Api\V1\Continuity\MedicalCheckoutContinuity;
use App\Services\Api\V1\MedicalServiceCartService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture;

const B24_LEO_CAPS = ['memberships:read', 'cart:read', 'checkout:prepare', 'cart:membership:write'];
const B24_HOST = 'famedic-b24.test';

function b24Headers(array $issued): array
{
    return [
        'Authorization' => 'Bearer '.$issued['token']->plainTextToken,
        'X-Synthetic-Machine-Credential' => $issued['machine']->plainTextToken,
    ];
}

/** @return array{0: User, 1: array} */
function b24LeoWithMembershipCart(): array
{
    $user = User::factory()->withRegularCustomer()->create();
    $issued = SyntheticConsumerRegistryFixture::issue($user, 'leo', B24_LEO_CAPS);
    app(MedicalServiceCartService::class)->addBasicPlan($user->customer);

    return [$user, $issued];
}

function b24EnableContinuity(bool $enabled = true): void
{
    config()->set('akubica_connectors.continuity.medical_services', ['enabled' => $enabled, 'certified' => $enabled]);
}

function b24PlainToken(string $url): string
{
    return Str::afterLast((string) parse_url($url, PHP_URL_PATH), '/');
}

beforeEach(function () {
    SyntheticConsumerRegistryFixture::reset();
    config()->set('akubica_connectors.continuity.allowed_host', B24_HOST);
    URL::forceRootUrl('https://'.B24_HOST);
    URL::forceScheme('https');
});

afterEach(function () {
    URL::forceRootUrl(null);
});

test('B24-C0 flag is off by default and prepare exposes no continuity', function () {
    expect(config('akubica_connectors.continuity.medical_services.enabled'))->toBeFalse()
        ->and(config('akubica_connectors.continuity.medical_services.certified'))->toBeFalse()
        ->and(MedicalCheckoutContinuity::enabled())->toBeFalse();

    [, $leo] = b24LeoWithMembershipCart();

    $this->getJson('/api/v1/checkout/prepare?service_line=medical_services', b24Headers($leo))
        ->assertOk()
        ->assertJsonPath('data.assistant_can_pay', false)
        ->assertJsonPath('data.can_continue_to_payment_platform', false)
        ->assertJsonMissingPath('data.continuity_url')
        ->assertJsonMissingPath('data.continuity_expires_at');

    expect(AkubicaCheckoutLink::query()->count())->toBe(0);
});

test('B24-C1 leo prepare issues a single-use https link bound to user and cart with TTL <= 10 min', function () {
    b24EnableContinuity();
    [$user, $leo] = b24LeoWithMembershipCart();
    $paymentsBefore = PaymentAttempt::query()->count();

    $response = $this->getJson('/api/v1/checkout/prepare?service_line=medical_services', b24Headers($leo))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.service_line', 'medical_services')
        ->assertJsonPath('data.assistant_can_pay', false)
        ->assertJsonPath('data.can_continue_on_platform', true)
        ->assertJsonPath('data.can_continue_to_payment_platform', false);

    $url = (string) $response->json('data.continuity_url');
    $plain = b24PlainToken($url);
    $expiresAt = \Carbon\Carbon::parse($response->json('data.continuity_expires_at'));

    expect(parse_url($url, PHP_URL_SCHEME))->toBe('https')
        ->and(parse_url($url, PHP_URL_HOST))->toBe(B24_HOST)
        ->and(parse_url($url, PHP_URL_PATH))->toBe('/checkout/continue/'.$plain)
        ->and(strlen($plain))->toBe(64)
        ->and($expiresAt->lessThanOrEqualTo(now()->addMinutes(10)->addSecond()))->toBeTrue()
        ->and($expiresAt->greaterThan(now()))->toBeTrue();

    $link = AkubicaCheckoutLink::query()->sole();
    $cart = $user->customer->medicalServiceCarts()->first();
    expect($link->customer_id)->toBe($user->customer->id)
        ->and($link->token_hash)->toBe(hash('sha256', $plain))
        ->and($link->used_at)->toBeNull()
        ->and($link->laboratory_brand)->toBeNull()
        ->and($link->metadata['purpose'])->toBe(MedicalCheckoutContinuity::PURPOSE)
        ->and($link->metadata['medical_service_cart_id'])->toBe($cart->id)
        ->and($link->metadata['cart_fingerprint'])->toBe(MedicalCheckoutContinuity::fingerprint($cart))
        ->and(json_encode($link->getAttributes()))->not->toContain($plain);

    // No payments, no payment links, no membership activation from Leo.
    expect(strtolower(json_encode($response->json())))->not->toContain('payment_link')
        ->and(PaymentAttempt::query()->count())->toBe($paymentsBefore)
        ->and($user->customer->medicalAttentionSubscriptions()->count())->toBe(0);
});

test('B24-C2 native consumers and empty carts never receive a continuity link', function () {
    b24EnableContinuity();
    $native = User::factory()->withRegularCustomer()->create();
    $issuedNative = SyntheticConsumerRegistryFixture::issue($native, 'native');
    app(MedicalServiceCartService::class)->addBasicPlan($native->customer);

    $this->getJson('/api/v1/checkout/prepare?service_line=medical_services', b24Headers($issuedNative))
        ->assertOk()->assertJsonMissingPath('data.continuity_url');

    $empty = User::factory()->withRegularCustomer()->create();
    $issuedEmpty = SyntheticConsumerRegistryFixture::issue($empty, 'leo', B24_LEO_CAPS);
    $this->getJson('/api/v1/checkout/prepare?service_line=medical_services', b24Headers($issuedEmpty))
        ->assertOk()->assertJsonPath('data.requirements.has_items', false)->assertJsonMissingPath('data.continuity_url');

    expect(AkubicaCheckoutLink::query()->count())->toBe(0);
});

test('B24-C3 host not allowed fails closed with typed 503 and no link', function () {
    b24EnableContinuity();
    [, $leo] = b24LeoWithMembershipCart();
    config()->set('akubica_connectors.continuity.allowed_host', 'otro-host.test');

    $this->getJson('/api/v1/checkout/prepare?service_line=medical_services', b24Headers($leo))
        ->assertStatus(503)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'CONTINUITY_UNAVAILABLE');

    expect(AkubicaCheckoutLink::query()->count())->toBe(0);
});

test('B24-C4 anonymous visit stores the token in session, never logs in and never claims', function () {
    b24EnableContinuity();
    [, $leo] = b24LeoWithMembershipCart();
    $plain = b24PlainToken($this->getJson('/api/v1/checkout/prepare?service_line=medical_services', b24Headers($leo))->json('data.continuity_url'));

    $this->get('/checkout/continue/'.$plain)->assertRedirect(route('login'));

    $this->assertGuest('web');
    expect(session('akubica.checkout.continue_token'))->toBe($plain)
        ->and(AkubicaCheckoutLink::query()->sole()->used_at)->toBeNull();
});

test('B24-C5 owner session opens membership checkout once; replay is rejected; nothing is paid or activated', function () {
    b24EnableContinuity();
    [$user, $leo] = b24LeoWithMembershipCart();
    $plain = b24PlainToken($this->getJson('/api/v1/checkout/prepare?service_line=medical_services', b24Headers($leo))->json('data.continuity_url'));
    $paymentsBefore = PaymentAttempt::query()->count();

    $this->actingAs($user, 'web')
        ->get('/checkout/continue/'.$plain)
        ->assertRedirect(route('medical-attention.checkout'));

    expect(AkubicaCheckoutLink::query()->sole()->used_at)->not->toBeNull();

    $this->get('/checkout/continue/'.$plain)
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', 'La liga de checkout ya fue utilizada.');

    expect(PaymentAttempt::query()->count())->toBe($paymentsBefore)
        ->and($user->customer->medicalAttentionSubscriptions()->count())->toBe(0)
        ->and(MedicalServiceCartItem::query()->count())->toBe(1);
});

test('B24-C6 resume after login uses the session token and claims for the owner', function () {
    b24EnableContinuity();
    [$user, $leo] = b24LeoWithMembershipCart();
    $plain = b24PlainToken($this->getJson('/api/v1/checkout/prepare?service_line=medical_services', b24Headers($leo))->json('data.continuity_url'));

    $this->get('/checkout/continue/'.$plain)->assertRedirect(route('login'));

    $this->actingAs($user, 'web')
        ->get('/checkout/continue')
        ->assertRedirect(route('medical-attention.checkout'));

    expect(AkubicaCheckoutLink::query()->sole()->used_at)->not->toBeNull()
        ->and(session('akubica.checkout.continue_token'))->toBeNull();
});

test('B24-C7 another authenticated user is rejected without claiming', function () {
    b24EnableContinuity();
    [, $leo] = b24LeoWithMembershipCart();
    $plain = b24PlainToken($this->getJson('/api/v1/checkout/prepare?service_line=medical_services', b24Headers($leo))->json('data.continuity_url'));
    $other = User::factory()->withRegularCustomer()->create();

    $this->actingAs($other, 'web')
        ->get('/checkout/continue/'.$plain)
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', 'La liga de checkout no corresponde a la sesión activa.');

    expect(AkubicaCheckoutLink::query()->sole()->used_at)->toBeNull();
});

test('B24-C8 expired link is rejected without claiming', function () {
    b24EnableContinuity();
    [$user, $leo] = b24LeoWithMembershipCart();
    $plain = b24PlainToken($this->getJson('/api/v1/checkout/prepare?service_line=medical_services', b24Headers($leo))->json('data.continuity_url'));

    $this->travel(11)->minutes();

    $this->actingAs($user, 'web')
        ->get('/checkout/continue/'.$plain)
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', 'La liga de checkout ha expirado.');

    expect(AkubicaCheckoutLink::query()->sole()->used_at)->toBeNull();
});

test('B24-C9 a newer prepare invalidates the previous unused link (only the latest is valid)', function () {
    b24EnableContinuity();
    [$user, $leo] = b24LeoWithMembershipCart();
    $first = b24PlainToken($this->getJson('/api/v1/checkout/prepare?service_line=medical_services', b24Headers($leo))->json('data.continuity_url'));
    $second = b24PlainToken($this->getJson('/api/v1/checkout/prepare?service_line=medical_services', b24Headers($leo))->json('data.continuity_url'));

    expect($first)->not->toBe($second);

    $this->actingAs($user, 'web')
        ->get('/checkout/continue/'.$first)
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', 'La liga de checkout ha expirado.');

    $this->get('/checkout/continue/'.$second)->assertRedirect(route('medical-attention.checkout'));
});

test('B24-C10 cart binding: a changed or emptied cart invalidates the link without claiming', function () {
    b24EnableContinuity();
    [$user, $leo] = b24LeoWithMembershipCart();
    $plain = b24PlainToken($this->getJson('/api/v1/checkout/prepare?service_line=medical_services', b24Headers($leo))->json('data.continuity_url'));

    $service = app(MedicalServiceCartService::class);
    $itemId = MedicalServiceCartItem::query()->sole()->id;
    $service->removeItem($user->customer, $itemId);

    $this->actingAs($user, 'web')
        ->get('/checkout/continue/'.$plain)
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', 'El carrito ya no tiene servicios.');

    $service->addBasicPlan($user->customer); // new item id -> different fingerprint

    $this->get('/checkout/continue/'.$plain)
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', 'El carrito cambió; solicita una nueva liga desde el asistente.');

    expect(AkubicaCheckoutLink::query()->sole()->used_at)->toBeNull();
});

test('B24-C11 kill switch: disabling the flag invalidates already issued links', function () {
    b24EnableContinuity();
    [$user, $leo] = b24LeoWithMembershipCart();
    $plain = b24PlainToken($this->getJson('/api/v1/checkout/prepare?service_line=medical_services', b24Headers($leo))->json('data.continuity_url'));

    b24EnableContinuity(false);

    $this->actingAs($user, 'web')
        ->get('/checkout/continue/'.$plain)
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', 'La liga de checkout no es válida.');

    expect(AkubicaCheckoutLink::query()->sole()->used_at)->toBeNull();
});

test('B24-C12 continuity tokens are never accepted by the legacy payment-link route (no auto login)', function () {
    b24EnableContinuity();
    [, $leo] = b24LeoWithMembershipCart();
    $plain = b24PlainToken($this->getJson('/api/v1/checkout/prepare?service_line=medical_services', b24Headers($leo))->json('data.continuity_url'));

    $this->get('/akubica/checkout/'.$plain)
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', 'La liga de pago no es válida.');

    $this->assertGuest('web');
    expect(AkubicaCheckoutLink::query()->sole()->used_at)->toBeNull();
});

test('B24-C13 an active membership at redemption time is rejected without claiming', function () {
    b24EnableContinuity();
    [$user, $leo] = b24LeoWithMembershipCart();
    $plain = b24PlainToken($this->getJson('/api/v1/checkout/prepare?service_line=medical_services', b24Headers($leo))->json('data.continuity_url'));

    $user->customer->update(['medical_attention_subscription_expires_at' => now()->addYear()]);

    $this->actingAs($user->fresh(), 'web')
        ->get('/checkout/continue/'.$plain)
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', 'Ya cuentas con una membresía activa.');

    expect(AkubicaCheckoutLink::query()->sole()->used_at)->toBeNull();
});

test('B24-C14 logs never contain the plain token or the continuity URL', function () {
    b24EnableContinuity();
    Log::spy();
    [$user, $leo] = b24LeoWithMembershipCart();
    $url = (string) $this->getJson('/api/v1/checkout/prepare?service_line=medical_services', b24Headers($leo))->json('data.continuity_url');
    $plain = b24PlainToken($url);

    $this->actingAs($user, 'web')->get('/checkout/continue/'.$plain)->assertRedirect(route('medical-attention.checkout'));

    Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => $message === 'akubica_medical_continuity_issued'
        && ! str_contains(json_encode($context), $plain))->once();
    Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => $message === 'akubica_medical_continuity_redeemed'
        && ! str_contains(json_encode($context), $plain))->once();
    Log::shouldNotHaveReceived('info', fn ($message, $context = []) => str_contains($message.json_encode($context), $plain));
});

test('B24-C15 leo still cannot obtain a payment link for the medical cart', function () {
    b24EnableContinuity();
    [, $leo] = b24LeoWithMembershipCart();

    $response = $this->postJson('/api/v1/checkout/payment-link', ['service_line' => 'medical_services'], b24Headers($leo) + ['Idempotency-Key' => (string) Str::uuid()]);

    expect($response->status())->toBeIn([401, 403]);
    expect(AkubicaCheckoutLink::query()->whereNull('metadata->purpose')->count())->toBe(0);
});
