<?php

// RC2 B22 (D4): pharmacy purchase attempts get a typed 503 PHARMACY_SUSPENDED with zero effects. LOCAL tests on MySQL 8.
use App\Models\CartItem;
use App\Models\LaboratoryCartItem;
use App\Models\MedicalServiceCart;
use App\Models\MedicalServiceCartItem;
use App\Models\OnlinePharmacyCartItem;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture;

function b22Counts(): array
{
    return [
        MedicalServiceCart::query()->count(),
        MedicalServiceCartItem::query()->count(),
        CartItem::query()->count(),
        LaboratoryCartItem::query()->count(),
        OnlinePharmacyCartItem::query()->count(),
    ];
}

function b22LeoHeaders(): array
{
    $user = User::factory()->withRegularCustomer()->create();
    $issued = SyntheticConsumerRegistryFixture::issue($user, 'leo', ['memberships:read', 'cart:read', 'checkout:prepare', 'cart:membership:write']);

    return [
        'Authorization' => 'Bearer '.$issued['token']->plainTextToken,
        'X-Synthetic-Machine-Credential' => $issued['machine']->plainTextToken,
        'Idempotency-Key' => (string) Str::uuid(),
    ];
}

beforeEach(fn () => SyntheticConsumerRegistryFixture::reset());

dataset('pharmacy_intents', [
    'service_line pharmacy' => [['service_line' => 'pharmacy', 'quantity' => 1]],
    'service_line online_pharmacy' => [['service_line' => 'online_pharmacy', 'quantity' => 1]],
    'item_type medication' => [['service_line' => 'medical_services', 'item_type' => 'medication', 'quantity' => 1]],
    'medication_id' => [['service_line' => 'pharmacy', 'medication_id' => 1, 'quantity' => 1]],
    'uppercase PHARMACY' => [['service_line' => 'PHARMACY', 'quantity' => 1]],
]);

test('B22 leo pharmacy purchase attempt returns typed 503 PHARMACY_SUSPENDED with zero effects', function (array $body) {
    $headers = b22LeoHeaders();
    $before = b22Counts();

    $this->postJson('/api/v1/cart/items', $body, $headers)
        ->assertStatus(503)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'PHARMACY_SUSPENDED')
        ->assertJsonPath('error.retryable', false)
        ->assertHeader('X-Correlation-ID');

    expect(b22Counts())->toBe($before);
})->with('pharmacy_intents');

test('B22 native consumer gets the same typed rejection with zero effects', function () {
    [, $token] = akubicaCustomerToken();
    $before = b22Counts();

    $this->postJson('/api/v1/cart/items', ['service_line' => 'pharmacy', 'medication_id' => 10, 'quantity' => 1], authHeaders($token) + ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'PHARMACY_SUSPENDED');

    expect(b22Counts())->toBe($before);
});

test('B22 unauthenticated pharmacy attempt is 401 (no information disclosure, no effect)', function () {
    $before = b22Counts();

    $this->postJson('/api/v1/cart/items', ['service_line' => 'pharmacy'])
        ->assertStatus(401);

    expect(b22Counts())->toBe($before);
});

test('B22 regression: leo membership add is unaffected (201)', function () {
    $headers = b22LeoHeaders();

    $this->postJson('/api/v1/cart/items', ['service_line' => 'medical_services', 'item_type' => 'membership', 'membership_plan_code' => 'basic_annual', 'quantity' => 1], $headers)
        ->assertStatus(201);

    expect(MedicalServiceCartItem::query()->count())->toBe(1);
});
