<?php

// RC2 Batch 2 · A4/D-2. Local isolated tests only; no staging certification.
use App\Models\AkubicaCheckoutLink;
use App\Models\LaboratoryCartItem;
use App\Models\User;
use App\Services\Api\V1\Continuity\LaboratoryCheckoutContinuity;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture;

const A4_HOST = 'famedic-qa.test';
const A4_CAPS = ['memberships:read', 'cart:read', 'checkout:prepare', 'cart:laboratory:read', 'cart:laboratory:write'];

function a4Headers(array $issued): array
{
    return [
        'Authorization' => 'Bearer '.$issued['token']->plainTextToken,
        'X-Synthetic-Machine-Credential' => $issued['machine']->plainTextToken,
    ];
}

function a4Leo(?User $user = null): array
{
    return SyntheticConsumerRegistryFixture::issue($user ?? User::factory()->withRegularCustomer()->create(), 'leo', A4_CAPS, ['olab']);
}

beforeEach(function () {
    SyntheticConsumerRegistryFixture::reset();
    config()->set('akubica_connectors.continuity.allowed_host', A4_HOST);
    config()->set('akubica_connectors.continuity.laboratory.enabled', false);
    config()->set('akubica_connectors.continuity.laboratory.certified', false);
    URL::forceRootUrl('https://'.A4_HOST);
    URL::forceScheme('https');
});

afterEach(fn () => URL::forceRootUrl(null));

test('A4 laboratory continuity is absent while its staging flag is off', function () {
    $leo = a4Leo();
    LaboratoryCartItem::query()->create(['customer_id' => $leo['user']->customer->id, 'laboratory_test_id' => createOlabTest()->id]);

    $response = $this->getJson('/api/v1/checkout/prepare?service_line=laboratory&brand=olab', a4Headers($leo))->assertOk();
    expect($response->json('data.continuity_url'))->toBeNull()
        ->and(AkubicaCheckoutLink::query()->count())->toBe(0);
});

test('A4 issues a first-party single-use laboratory link bound to cart, line and brand', function () {
    config()->set('akubica_connectors.continuity.laboratory.enabled', true);
    config()->set('akubica_connectors.continuity.laboratory.certified', true);
    $leo = a4Leo();
    LaboratoryCartItem::query()->create(['customer_id' => $leo['user']->customer->id, 'laboratory_test_id' => createOlabTest()->id]);

    $response = $this->getJson('/api/v1/checkout/prepare?service_line=laboratory&brand=olab', a4Headers($leo))->assertOk();
    $url = (string) $response->json('data.continuity_url');
    $token = Str::afterLast((string) parse_url($url, PHP_URL_PATH), '/');
    $link = AkubicaCheckoutLink::query()->sole();

    expect(parse_url($url, PHP_URL_SCHEME))->toBe('https')
        ->and(parse_url($url, PHP_URL_HOST))->toBe(A4_HOST)
        ->and(parse_url($url, PHP_URL_PATH))->toBe('/checkout/continue/'.$token)
        ->and(strlen($token))->toBe(64)
        ->and($link->customer_id)->toBe($leo['user']->customer->id)
        ->and($link->laboratory_brand->value)->toBe('olab')
        ->and($link->metadata['purpose'])->toBe(LaboratoryCheckoutContinuity::PURPOSE)
        ->and($link->metadata['service_line'])->toBe('laboratory')
        ->and($link->metadata['laboratory_cart_id'])->toBeInt()
        ->and($link->expires_at->diffInMinutes(now()))->toBeLessThanOrEqual(10)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->json('data.assistant_can_pay'))->toBeFalse()
        ->and($response->json('data.can_continue_to_payment_platform'))->toBeFalse()
        ->and($response->json('data.payment_link'))->toBeNull();
});

test('A4 link is not consumed by another session and is consumed exactly once by the owner', function () {
    config()->set('akubica_connectors.continuity.laboratory.enabled', true);
    config()->set('akubica_connectors.continuity.laboratory.certified', true);
    $leo = a4Leo();
    LaboratoryCartItem::query()->create(['customer_id' => $leo['user']->customer->id, 'laboratory_test_id' => createOlabTest()->id]);
    $token = Str::afterLast((string) parse_url($this->getJson('/api/v1/checkout/prepare?service_line=laboratory&brand=olab', a4Headers($leo))->json('data.continuity_url'), PHP_URL_PATH), '/');

    $other = User::factory()->withRegularCustomer()->create();
    $this->actingAs($other)->get('/checkout/continue/'.$token)->assertRedirect(route('home'))->assertSessionHas('error');
    $this->actingAs($leo['user'])->get('/checkout/continue/'.$token)->assertRedirect();
    expect(AkubicaCheckoutLink::query()->sole()->used_at)->not->toBeNull();
    $this->actingAs($leo['user'])->get('/checkout/continue/'.$token)->assertRedirect(route('home'))->assertSessionHas('error');
});

test('A4 rejects an expired link and preserves it as unused', function () {
    config()->set('akubica_connectors.continuity.laboratory.enabled', true);
    config()->set('akubica_connectors.continuity.laboratory.certified', true);
    $leo = a4Leo();
    LaboratoryCartItem::query()->create(['customer_id' => $leo['user']->customer->id, 'laboratory_test_id' => createOlabTest()->id]);
    $token = Str::afterLast((string) parse_url($this->getJson('/api/v1/checkout/prepare?service_line=laboratory&brand=olab', a4Headers($leo))->json('data.continuity_url'), PHP_URL_PATH), '/');

    AkubicaCheckoutLink::query()->sole()->update(['expires_at' => now()->subMinute()]);

    $this->actingAs($leo['user'])->get('/checkout/continue/'.$token)->assertRedirect(route('home'))->assertSessionHas('error');
    expect(AkubicaCheckoutLink::query()->sole()->used_at)->toBeNull();
});

test('A4 rejects a changed cart binding and preserves the link as unused', function () {
    config()->set('akubica_connectors.continuity.laboratory.enabled', true);
    config()->set('akubica_connectors.continuity.laboratory.certified', true);
    $leo = a4Leo();
    LaboratoryCartItem::query()->create(['customer_id' => $leo['user']->customer->id, 'laboratory_test_id' => createOlabTest()->id]);
    $token = Str::afterLast((string) parse_url($this->getJson('/api/v1/checkout/prepare?service_line=laboratory&brand=olab', a4Headers($leo))->json('data.continuity_url'), PHP_URL_PATH), '/');
    LaboratoryCartItem::query()->create(['customer_id' => $leo['user']->customer->id, 'laboratory_test_id' => createOlabTest()->id]);

    $this->actingAs($leo['user'])->get('/checkout/continue/'.$token)->assertRedirect(route('home'))->assertSessionHas('error');
    expect(AkubicaCheckoutLink::query()->sole()->used_at)->toBeNull();
});

test('A4 atomic claim permits one redemption only under a race', function () {
    config()->set('akubica_connectors.continuity.laboratory.enabled', true);
    config()->set('akubica_connectors.continuity.laboratory.certified', true);
    $leo = a4Leo();
    LaboratoryCartItem::query()->create(['customer_id' => $leo['user']->customer->id, 'laboratory_test_id' => createOlabTest()->id]);
    $this->getJson('/api/v1/checkout/prepare?service_line=laboratory&brand=olab', a4Headers($leo))->assertOk();
    $link = AkubicaCheckoutLink::query()->sole();

    expect($link->claim())->toBeTrue()->and($link->fresh()->claim())->toBeFalse();
});

// INT-025 (A4-W3 2026-11-26): an authenticated rejection must end on a visible non-checkout page,
// keep the link untouched and log the reason (link id and reason only, never the token).
function a4EnableContinuity(): void
{
    config()->set('akubica_connectors.continuity.laboratory.enabled', true);
    config()->set('akubica_connectors.continuity.laboratory.certified', true);
}

// Real titulares in QA have a complete, verified profile; factory defaults would bounce /home to CompleteProfile.
function a4CompleteUser(): User
{
    return User::factory()->withRegularCustomer()->withCompleteProfile()->withVerifiedPhone()->withVerifiedEmail()->create();
}

function a4IssueToken($test, array $leo): string
{
    $url = (string) $test->getJson('/api/v1/checkout/prepare?service_line=laboratory&brand=olab', a4Headers($leo))->json('data.continuity_url');

    return Str::afterLast((string) parse_url($url, PHP_URL_PATH), '/');
}

function a4FinalComponent($response): ?string
{
    // The followed page must actually render: a 500 or a non-Inertia page would make "not the checkout" vacuous.
    $response->assertOk();
    $data = $response->baseResponse->getOriginalContent();
    $page = is_object($data) && method_exists($data, 'getData') ? data_get($data->getData(), 'page') : null;

    return data_get($page, 'component');
}

function a4VisibleError($response): ?string
{
    $page = data_get($response->baseResponse->getOriginalContent()->getData(), 'page');

    return data_get($page, 'props.flashMessage.type') === 'error' ? (string) data_get($page, 'props.flashMessage.message') : null;
}

function a4ExpectRejectionLogged(int $linkId, string $reason, string $token): void
{
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === 'akubica_laboratory_continuity_rejected'
        && ($context['link_id'] ?? null) === $linkId
        && ($context['reason'] ?? null) === $reason
        && ! str_contains(json_encode($context), $token))->once();
    Log::shouldNotHaveReceived('info', fn ($message, $context = []) => $message === 'akubica_laboratory_continuity_redeemed');
}

test('INT-025 another titular with a session lands on a non-checkout page, link untouched, reason session_mismatch', function () {
    a4EnableContinuity();
    $leo = a4Leo(a4CompleteUser());
    LaboratoryCartItem::query()->create(['customer_id' => $leo['user']->customer->id, 'laboratory_test_id' => createOlabTest()->id]);
    $token = a4IssueToken($this, $leo);
    $link = AkubicaCheckoutLink::query()->sole();
    $other = a4CompleteUser();
    LaboratoryCartItem::query()->create(['customer_id' => $other->customer->id, 'laboratory_test_id' => createOlabTest()->id]);
    Log::spy();
    $this->withoutVite();

    $response = $this->actingAs($other)->followingRedirects()->get('/checkout/continue/'.$token);

    expect(a4FinalComponent($response))->toBe('Home')
        ->and(a4VisibleError($response))->not->toBeNull()
        ->and($link->fresh()->used_at)->toBeNull()
        ->and($link->fresh()->expires_at->equalTo($link->expires_at))->toBeTrue();
    a4ExpectRejectionLogged($link->id, 'session_mismatch', $token);
});

test('INT-025 replay by the owner lands on a non-checkout page, used_at unchanged, reason already_used', function () {
    a4EnableContinuity();
    $leo = a4Leo(a4CompleteUser());
    LaboratoryCartItem::query()->create(['customer_id' => $leo['user']->customer->id, 'laboratory_test_id' => createOlabTest()->id]);
    $token = a4IssueToken($this, $leo);
    $this->actingAs($leo['user'])->get('/checkout/continue/'.$token)->assertRedirect();
    $usedAt = AkubicaCheckoutLink::query()->sole()->used_at;
    expect($usedAt)->not->toBeNull();
    Log::spy();
    $this->withoutVite();

    $response = $this->actingAs($leo['user'])->followingRedirects()->get('/checkout/continue/'.$token);

    expect(a4FinalComponent($response))->toBe('Home')
        ->and(a4VisibleError($response))->not->toBeNull()
        ->and(AkubicaCheckoutLink::query()->sole()->used_at->equalTo($usedAt))->toBeTrue();
    a4ExpectRejectionLogged(AkubicaCheckoutLink::query()->sole()->id, 'already_used', $token);
});

test('INT-025 expired link with the owner session lands on a non-checkout page, unused, reason expired', function () {
    a4EnableContinuity();
    $leo = a4Leo(a4CompleteUser());
    LaboratoryCartItem::query()->create(['customer_id' => $leo['user']->customer->id, 'laboratory_test_id' => createOlabTest()->id]);
    $token = a4IssueToken($this, $leo);
    $this->travel(11)->minutes();
    Log::spy();
    $this->withoutVite();

    $response = $this->actingAs($leo['user'])->followingRedirects()->get('/checkout/continue/'.$token);

    expect(a4FinalComponent($response))->toBe('Home')
        ->and(a4VisibleError($response))->not->toBeNull()
        ->and(AkubicaCheckoutLink::query()->sole()->used_at)->toBeNull();
    a4ExpectRejectionLogged(AkubicaCheckoutLink::query()->sole()->id, 'expired', $token);
});

test('INT-025 changed cart with the owner session lands on a non-checkout page, unused, reason cart_binding', function () {
    a4EnableContinuity();
    $leo = a4Leo(a4CompleteUser());
    LaboratoryCartItem::query()->create(['customer_id' => $leo['user']->customer->id, 'laboratory_test_id' => createOlabTest()->id]);
    $token = a4IssueToken($this, $leo);
    LaboratoryCartItem::query()->create(['customer_id' => $leo['user']->customer->id, 'laboratory_test_id' => createOlabTest()->id]);
    Log::spy();
    $this->withoutVite();

    $response = $this->actingAs($leo['user'])->followingRedirects()->get('/checkout/continue/'.$token);

    expect(a4FinalComponent($response))->toBe('Home')
        ->and(a4VisibleError($response))->not->toBeNull()
        ->and(AkubicaCheckoutLink::query()->sole()->used_at)->toBeNull();
    a4ExpectRejectionLogged(AkubicaCheckoutLink::query()->sole()->id, 'cart_binding', $token);
});

test('INT-025 disabled continuity with a session is rejected with reason disabled and the link stays unused', function () {
    a4EnableContinuity();
    $leo = a4Leo(a4CompleteUser());
    LaboratoryCartItem::query()->create(['customer_id' => $leo['user']->customer->id, 'laboratory_test_id' => createOlabTest()->id]);
    $token = a4IssueToken($this, $leo);
    config()->set('akubica_connectors.continuity.laboratory.enabled', false);
    Log::spy();

    $this->actingAs($leo['user'])->get('/checkout/continue/'.$token)->assertRedirect(route('home'))->assertSessionHas('error');

    expect(AkubicaCheckoutLink::query()->sole()->used_at)->toBeNull();
    a4ExpectRejectionLogged(AkubicaCheckoutLink::query()->sole()->id, 'disabled', $token);
});

test('INT-025 the valid owner redemption reaches the laboratory checkout and logs redeemed without the token', function () {
    a4EnableContinuity();
    $leo = a4Leo(a4CompleteUser());
    LaboratoryCartItem::query()->create(['customer_id' => $leo['user']->customer->id, 'laboratory_test_id' => createOlabTest()->id]);
    $token = a4IssueToken($this, $leo);
    $link = AkubicaCheckoutLink::query()->sole();
    Log::spy();

    $this->withoutVite();

    $this->actingAs($leo['user'])->get('/checkout/continue/'.$token)
        ->assertRedirect(route('laboratory.checkout', ['laboratory_brand' => 'olab']));
    expect($link->fresh()->used_at)->not->toBeNull();
    $page = $this->actingAs($leo['user'])->get(route('laboratory.checkout', ['laboratory_brand' => 'olab']));
    expect(a4FinalComponent($page))->toBe('LaboratoryCheckout');
    Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => $message === 'akubica_laboratory_continuity_redeemed'
        && ($context['link_id'] ?? null) === $link->id
        && ! str_contains(json_encode($context), $token))->once();
    Log::shouldNotHaveReceived('warning', fn ($message, $context = []) => $message === 'akubica_laboratory_continuity_rejected');
});
