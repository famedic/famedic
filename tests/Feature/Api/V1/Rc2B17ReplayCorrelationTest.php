<?php

// RC2 B17: an idempotent replay of POST /cart/items (membership, Leo) returns the ORIGINAL response,
// Idempotency-Replayed: true and the ORIGINAL X-Correlation-ID (OpenAPI components.parameters.IdempotencyKey). LOCAL tests on MySQL 8.
use App\Models\MedicalServiceCartItem;
use App\Models\User;
use App\Support\Api\V1\AkubicaCorrelationId;
use Illuminate\Support\Str;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture;

beforeEach(function () {
    SyntheticConsumerRegistryFixture::reset();
    config()->set('api_v1.idempotency.enabled', true);
});

function b17LeoHeaders(string $key, string $correlation): array
{
    $user = User::factory()->withRegularCustomer()->create();
    $issued = SyntheticConsumerRegistryFixture::issue($user, 'leo', ['memberships:read', 'cart:read', 'checkout:prepare', 'cart:membership:write']);

    return [
        'Authorization' => 'Bearer '.$issued['token']->plainTextToken,
        'X-Synthetic-Machine-Credential' => $issued['machine']->plainTextToken,
        'Idempotency-Key' => $key,
        AkubicaCorrelationId::HEADER => $correlation,
    ];
}

test('B17 replay of a membership add returns the original body, Idempotency-Replayed and the ORIGINAL correlation id', function () {
    $key = 'rc2-b17-'.Str::lower(Str::random(12));
    $headers = b17LeoHeaders($key, 'corr-b17-original-01');
    $body = ['service_line' => 'medical_services', 'item_type' => 'membership', 'membership_plan_code' => 'basic_annual', 'quantity' => 1];

    $first = $this->postJson('/api/v1/cart/items', $body, $headers)
        ->assertStatus(201)
        ->assertHeader(AkubicaCorrelationId::HEADER, 'corr-b17-original-01');

    $replay = $this->postJson('/api/v1/cart/items', $body, array_merge($headers, [AkubicaCorrelationId::HEADER => 'corr-b17-replay-0002']))
        ->assertStatus(201)
        ->assertHeader('Idempotency-Replayed', 'true')
        ->assertHeader(AkubicaCorrelationId::HEADER, 'corr-b17-original-01');

    expect($replay->json())->toBe($first->json())
        ->and(MedicalServiceCartItem::query()->count())->toBe(1);
});
