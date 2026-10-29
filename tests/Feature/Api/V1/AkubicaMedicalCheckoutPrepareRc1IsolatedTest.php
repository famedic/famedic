<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CouponType;
use App\Models\Coupon;
use App\Models\MedicalServiceCartItem;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\Api\V1\MedicalServiceCartService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AkubicaMedicalCheckoutPrepareRc1IsolatedTest extends TestCase
{
    private string $runId;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();
        $this->runId = substr((string) uniqid(), -8);
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function medical_checkout_exposes_rc1_assistant_and_platform_flags(): void
    {
        [$user, $token] = akubicaCustomerToken();
        app(MedicalServiceCartService::class)->addBasicPlan($user->customer);

        $this->getJson('/api/v1/checkout/prepare?service_line=medical_services', authHeaders($token))
            ->assertOk()
            ->assertJsonPath('data.assistant_can_pay', false)
            ->assertJsonPath('data.can_continue_on_platform', true)
            ->assertJsonPath('data.can_continue_to_payment_platform', false);
    }

    #[Test]
    public function medical_checkout_does_not_create_payments_or_payment_links(): void
    {
        [$user, $token] = akubicaCustomerToken();
        app(MedicalServiceCartService::class)->addBasicPlan($user->customer);
        $paymentsBefore = PaymentAttempt::query()->count();

        $response = $this->getJson('/api/v1/checkout/prepare?service_line=medical_services', authHeaders($token))
            ->assertOk();

        $serialized = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('payment_link', strtolower($serialized));
        $this->assertSame($paymentsBefore, PaymentAttempt::query()->count());
    }

    #[Test]
    public function medical_checkout_does_not_activate_memberships(): void
    {
        [$user, $token] = akubicaCustomerToken();
        app(MedicalServiceCartService::class)->addBasicPlan($user->customer);

        $this->getJson('/api/v1/checkout/prepare?service_line=medical_services', authHeaders($token))
            ->assertOk();

        $this->assertSame(0, $user->customer->medicalAttentionSubscriptions()->count());
        $this->assertSame(1, $user->customer->medicalServiceCarts()->first()->items()->count());
    }

    #[Test]
    public function medical_checkout_reflects_cart_totals_and_applied_benefits(): void
    {
        [$user, $token] = akubicaCustomerToken();
        app(MedicalServiceCartService::class)->addBasicPlan($user->customer);
        $code = 'RC1-BEN-'.$this->runId;
        $coupon = Coupon::factory()->create([
            'code' => $code,
            'type' => CouponType::Balance,
            'amount_cents' => 50000,
            'remaining_cents' => 50000,
        ]);
        assignUserCoupon($user, $coupon);

        $this->postJson('/api/v1/cart/medical-benefit', [
            'service_line' => 'medical_services',
            'code' => $code,
        ], authHeaders($token))->assertOk();

        $this->getJson('/api/v1/checkout/prepare?service_line=medical_services', authHeaders($token))
            ->assertOk()
            ->assertJsonPath('data.cart.totals.total_cents', 0)
            ->assertJsonPath('data.cart.totals.credit_applied_cents', 30000)
            ->assertJsonPath('data.cart.applied_benefits.0.code', $code)
            ->assertJsonPath('data.requirements.has_items', true);
    }

    #[Test]
    public function laboratory_checkout_prepare_is_unchanged(): void
    {
        [$user, $token] = akubicaCustomerToken();
        addOlabCartItem($user);

        $this->getJson('/api/v1/checkout/prepare?brand=olab', authHeaders($token))
            ->assertOk()
            ->assertJsonPath('data.can_continue_to_payment_platform', true)
            ->assertJsonMissingPath('data.assistant_can_pay')
            ->assertJsonMissingPath('data.can_continue_on_platform');
    }

    #[Test]
    public function medical_checkout_ownership_isolated_per_customer(): void
    {
        [$owner] = akubicaCustomerToken();
        $cart = app(MedicalServiceCartService::class)->addBasicPlan($owner->customer);
        $itemId = $cart->items->sole()->id;

        [$other] = akubicaCustomerToken();

        $this->actingAs($other, 'sanctum')
            ->deleteJson("/api/v1/cart/items/{$itemId}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/v1/checkout/prepare?service_line=medical_services')
            ->assertOk()
            ->assertJsonPath('data.cart.totals.item_count', 0);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/checkout/prepare?service_line=medical_services')
            ->assertOk()
            ->assertJsonPath('data.cart.totals.item_count', 1)
            ->assertJsonPath('data.assistant_can_pay', false)
            ->assertJsonPath('data.can_continue_on_platform', true);
    }
}
