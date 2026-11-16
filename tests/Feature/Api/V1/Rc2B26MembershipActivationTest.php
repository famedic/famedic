<?php

// RC2 B26 (D7): the membership stays inactive while the cart is prepared but not paid. No payment is executed or simulated.
use App\Models\MedicalAttentionSubscription;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture;

function b26Headers(array $issued, bool $idempotent = false): array
{
    $headers = [
        'Authorization' => 'Bearer '.$issued['token']->plainTextToken,
        'X-Synthetic-Machine-Credential' => $issued['machine']->plainTextToken,
    ];

    return $idempotent ? $headers + ['Idempotency-Key' => (string) Str::uuid()] : $headers;
}

beforeEach(function () {
    SyntheticConsumerRegistryFixture::reset();
    URL::forceRootUrl('https://famedic-b26.test');
    URL::forceScheme('https');
    config()->set('akubica_connectors.continuity.allowed_host', 'famedic-b26.test');
});

afterEach(fn () => URL::forceRootUrl(null));

test('B26 status before and after the unpaid Leo flow stays none/eligible; no subscription, payment or activation', function (bool $continuityEnabled) {
    config()->set('akubica_connectors.continuity.medical_services', ['enabled' => $continuityEnabled, 'certified' => $continuityEnabled]);
    $user = User::factory()->withRegularCustomer()->create();
    $leo = SyntheticConsumerRegistryFixture::issue($user, 'leo', ['memberships:read', 'cart:read', 'checkout:prepare', 'cart:membership:write']);
    $paymentsBefore = PaymentAttempt::query()->count();

    $this->getJson('/api/v1/memberships/status', b26Headers($leo))->assertOk()
        ->assertJsonPath('data.status', 'none')->assertJsonPath('data.purchase_eligible', true);

    $this->postJson('/api/v1/cart/items', ['service_line' => 'medical_services', 'item_type' => 'membership', 'membership_plan_code' => 'basic_annual', 'quantity' => 1], b26Headers($leo, true))
        ->assertStatus(201);
    $prepare = $this->getJson('/api/v1/checkout/prepare?service_line=medical_services', b26Headers($leo))->assertOk()
        ->assertJsonPath('data.assistant_can_pay', false);

    $this->getJson('/api/v1/memberships/status', b26Headers($leo))->assertOk()
        ->assertJsonPath('data.status', 'none')->assertJsonPath('data.purchase_eligible', true);

    if ($continuityEnabled) {
        $plain = Str::afterLast((string) parse_url($prepare->json('data.continuity_url'), PHP_URL_PATH), '/');
        $this->actingAs($user, 'web')->get('/checkout/continue/'.$plain)->assertRedirect(route('medical-attention.checkout'));
    }

    expect(MedicalAttentionSubscription::query()->where('customer_id', $user->customer->id)->count())->toBe(0)
        ->and($user->customer->fresh()->medical_attention_subscription_expires_at)->toBeNull()
        ->and(PaymentAttempt::query()->count())->toBe($paymentsBefore);
})->with(['continuity off' => [false], 'continuity on (local only)' => [true]]);

test('B26 leo bearer cannot reach the web membership purchase endpoints', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $leo = SyntheticConsumerRegistryFixture::issue($user, 'leo', ['memberships:read', 'cart:read', 'checkout:prepare', 'cart:membership:write']);

    $response = $this->post('/medical-attention/subscription', [], b26Headers($leo));
    expect($response->status())->toBeIn([302, 401, 403, 419]);
    $this->assertGuest('web');
    expect(MedicalAttentionSubscription::query()->where('customer_id', $user->customer->id)->count())->toBe(0);
});
