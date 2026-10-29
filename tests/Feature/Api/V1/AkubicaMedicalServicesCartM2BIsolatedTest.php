<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MedicalSubscriptionType;
use App\Models\MedicalAttentionSubscription;
use App\Models\MedicalServiceCart;
use App\Models\MedicalServiceCartItem;
use App\Models\User;
use App\Services\Api\V1\Idempotency\IdempotencyKey;
use App\Services\Api\V1\MedicalServiceCartService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** M2A/M2B isolated suite against the prepared testing schema. */
final class AkubicaMedicalServicesCartM2BIsolatedTest extends TestCase
{
    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();
        config()->set('api_v1.idempotency.enabled', true);
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function it_adds_reads_totals_and_removes_only_a_medical_membership_item(): void
    {
        [$user, $token] = akubicaCustomerToken();
        $headers = array_merge(authHeaders($token), ['X-Correlation-ID' => 'm2b-isolated-cart']);

        $this->postJson('/api/v1/cart/items', [
            'service_line' => 'medical_services', 'item_type' => 'membership',
            'membership_plan_code' => 'basic_annual',
        ], $headers)->assertCreated()
            ->assertJsonPath('data.service_line', 'medical_services')
            ->assertJsonPath('data.items.0.membership_plan_code', 'basic_annual')
            ->assertJsonPath('data.totals.total_cents', 30000);

        $this->getJson('/api/v1/cart?service_line=medical_services', $headers)
            ->assertOk()->assertJsonPath('data.service_line', 'medical_services')
            ->assertJsonCount(1, 'data.items');

        $this->getJson('/api/v1/cart/totals?service_line=medical_services', $headers)
            ->assertOk()->assertJsonPath('data.total_cents', 30000);

        $itemId = $user->customer->medicalServiceCarts()->first()->items()->sole()->id;
        $this->deleteJson("/api/v1/cart/items/{$itemId}", [], $headers)
            ->assertOk()->assertJsonPath('data.totals.total_cents', 0);
    }

    #[Test]
    public function it_accepts_only_one_basic_annual_item_and_rejects_other_service_lines(): void
    {
        [, $token] = akubicaCustomerToken();
        $headers = authHeaders($token);
        $payload = [
            'service_line' => 'medical_services', 'item_type' => 'membership',
            'membership_plan_code' => 'basic_annual',
        ];

        $this->postJson('/api/v1/cart/items', $payload, $headers)->assertCreated();
        $this->postJson('/api/v1/cart/items', $payload, $headers)
            ->assertStatus(409)->assertJsonPath('error.code', 'MEMBERSHIP_ALREADY_IN_CART');

        $this->postJson('/api/v1/cart/items', array_merge($payload, ['brand' => 'olab']), $headers)
            ->assertStatus(409)->assertJsonPath('error.code', 'CART_SERVICE_LINE_MISMATCH');
    }

    #[Test]
    public function it_rejects_an_active_membership_and_preserves_ownership(): void
    {
        $owner = User::factory()->withRegularCustomer()->create(['email' => 'm2b-owner-'.uniqid().'@example.test']);
        $ownerToken = $owner->createToken('m2b-owner')->plainTextToken;
        $otherUser = User::factory()->withRegularCustomer()->create(['email' => 'm2b-other-'.uniqid().'@example.test']);
        $other = $otherUser->createToken('m2b-other')->plainTextToken;
        $this->assertNotSame($owner->id, $otherUser->id);
        MedicalAttentionSubscription::query()->create([
            'customer_id' => $owner->customer->id,
            'type' => MedicalSubscriptionType::REGULAR,
            'price_cents' => 30000,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
        ]);

        $payload = [
            'service_line' => 'medical_services', 'item_type' => 'membership',
            'membership_plan_code' => 'basic_annual',
        ];
        $this->postJson('/api/v1/cart/items', $payload, authHeaders($ownerToken))
            ->assertStatus(409)->assertJsonPath('error.code', 'MEMBERSHIP_ALREADY_ACTIVE');

        $cart = MedicalServiceCart::factory()->create(['customer_id' => $owner->customer->id]);
        MedicalServiceCartItem::factory()->create(['cart_id' => $cart->id]);
        $itemId = $cart->items->sole()->id;
        $this->actingAs($otherUser, 'sanctum')->deleteJson("/api/v1/cart/items/{$itemId}")
            ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
    }

    #[Test]
    public function it_replays_the_same_addition_idempotently_without_duplicates(): void
    {
        [$user, $token] = akubicaCustomerToken();
        $headers = array_merge(authHeaders($token), [IdempotencyKey::HEADER => 'm2b-isolated-idempotency']);
        $payload = [
            'service_line' => 'medical_services', 'item_type' => 'membership',
            'membership_plan_code' => 'basic_annual',
        ];

        $first = $this->postJson('/api/v1/cart/items', $payload, $headers)->assertCreated();
        $second = $this->postJson('/api/v1/cart/items', $payload, $headers)->assertCreated();

        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, $user->customer->medicalServiceCarts()->first()->items()->count());
    }

    #[Test]
    public function it_prepares_medical_checkout_without_payment_links_or_activation(): void
    {
        [$user, $token] = akubicaCustomerToken();
        app(MedicalServiceCartService::class)->addBasicPlan($user->customer);

        $response = $this->getJson('/api/v1/checkout/prepare?service_line=medical_services', authHeaders($token))
            ->assertOk()->assertJsonPath('data.service_line', 'medical_services')
            ->assertJsonPath('data.cart.totals.total_cents', 30000)
            ->assertJsonPath('data.can_continue_to_payment_platform', false)
            ->assertJsonPath('data.assistant_can_pay', false)
            ->assertJsonPath('data.can_continue_on_platform', true);

        $serialized = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('payment_link', $serialized);
        $this->assertSame(0, $user->customer->medicalAttentionSubscriptions()->count());
        $this->assertSame(1, MedicalServiceCartItem::query()->where('cart_id', $user->customer->medicalServiceCarts()->first()->id)->count());
    }
}
