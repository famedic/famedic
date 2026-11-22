<?php

// RC2 Batch 2 · A4/D-2. Local isolated tests only; no staging certification.
use App\Models\AkubicaCheckoutLink;
use App\Models\LaboratoryCartItem;
use App\Models\User;
use App\Services\Api\V1\Continuity\LaboratoryCheckoutContinuity;
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
    $this->actingAs($other)->get('/checkout/continue/'.$token)->assertRedirect(route('login'));
    $this->actingAs($leo['user'])->get('/checkout/continue/'.$token)->assertRedirect();
    expect(AkubicaCheckoutLink::query()->sole()->used_at)->not->toBeNull();
    $this->actingAs($leo['user'])->get('/checkout/continue/'.$token)->assertRedirect(route('login'));
});

test('A4 rejects an expired link and preserves it as unused', function () {
    config()->set('akubica_connectors.continuity.laboratory.enabled', true);
    config()->set('akubica_connectors.continuity.laboratory.certified', true);
    $leo = a4Leo();
    LaboratoryCartItem::query()->create(['customer_id' => $leo['user']->customer->id, 'laboratory_test_id' => createOlabTest()->id]);
    $token = Str::afterLast((string) parse_url($this->getJson('/api/v1/checkout/prepare?service_line=laboratory&brand=olab', a4Headers($leo))->json('data.continuity_url'), PHP_URL_PATH), '/');

    AkubicaCheckoutLink::query()->sole()->update(['expires_at' => now()->subMinute()]);

    $this->actingAs($leo['user'])->get('/checkout/continue/'.$token)->assertRedirect(route('login'));
    expect(AkubicaCheckoutLink::query()->sole()->used_at)->toBeNull();
});

test('A4 rejects a changed cart binding and preserves the link as unused', function () {
    config()->set('akubica_connectors.continuity.laboratory.enabled', true);
    config()->set('akubica_connectors.continuity.laboratory.certified', true);
    $leo = a4Leo();
    LaboratoryCartItem::query()->create(['customer_id' => $leo['user']->customer->id, 'laboratory_test_id' => createOlabTest()->id]);
    $token = Str::afterLast((string) parse_url($this->getJson('/api/v1/checkout/prepare?service_line=laboratory&brand=olab', a4Headers($leo))->json('data.continuity_url'), PHP_URL_PATH), '/');
    LaboratoryCartItem::query()->create(['customer_id' => $leo['user']->customer->id, 'laboratory_test_id' => createOlabTest()->id]);

    $this->actingAs($leo['user'])->get('/checkout/continue/'.$token)->assertRedirect(route('login'));
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
