<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CouponType;
use App\Models\Coupon;
use App\Models\CouponTransaction;
use App\Models\LaboratoryCartItem;
use App\Models\MedicalServiceCartBenefit;
use App\Models\User;
use App\Services\Api\V1\Idempotency\IdempotencyKey;
use App\Services\Api\V1\MedicalServiceCartService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** M2C isolated suite for medical benefits, coupons, and credit. */
final class AkubicaMedicalServicesCartM2CIsolatedTest extends TestCase
{
    private string $runId;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();
        $this->runId = substr((string) uniqid(), -8);
        config()->set('api_v1.idempotency.enabled', true);
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function it_applies_partial_credit_without_consuming_the_balance(): void
    {
        [$user, $token] = akubicaCustomerToken();
        $cart = app(MedicalServiceCartService::class)->addBasicPlan($user->customer);
        $code = $this->code('CREDIT');
        $credit = $this->coupon($user, $code, CouponType::Balance, 50000);

        $this->postJson('/api/v1/cart/medical-benefit', [
            'service_line' => 'medical_services', 'code' => $code,
        ], authHeaders($token))->assertOk()
            ->assertJsonPath('data.cart.totals.credit_applied_cents', 30000)
            ->assertJsonPath('data.cart.totals.total_cents', 0)
            ->assertJsonPath('data.cart.applied_benefits.0.applied_cents', 30000);

        $this->assertSame(50000, $credit->fresh()->remaining_cents);
        $this->assertSame(0, CouponTransaction::query()->where('coupon_id', $credit->id)->count());
    }

    #[Test]
    public function it_allows_one_discount_or_credit_but_never_both(): void
    {
        [$user, $token] = akubicaCustomerToken();
        $cart = app(MedicalServiceCartService::class)->addBasicPlan($user->customer);
        $couponCode = $this->code('COUPON');
        $balanceCode = $this->code('BALANCE');
        $this->coupon($user, $couponCode, CouponType::Coupon, 7000);
        $this->coupon($user, $balanceCode, CouponType::Balance, 7000);

        $this->postJson('/api/v1/cart/medical-benefit', [
            'service_line' => 'medical_services', 'code' => $couponCode,
        ], authHeaders($token))->assertOk()
            ->assertJsonPath('data.cart.totals.discount_cents', 7000)
            ->assertJsonPath('data.cart.totals.credit_applied_cents', 0)
            ->assertJsonPath('data.cart.totals.total_cents', 23000);

        $this->postJson('/api/v1/cart/medical-benefit', [
            'service_line' => 'medical_services', 'code' => $balanceCode,
        ], authHeaders($token))->assertStatus(409)
            ->assertJsonPath('error.code', 'BENEFIT_ALREADY_APPLIED');
        $this->assertSame(1, $cart->fresh()->benefits()->count());
    }

    #[Test]
    public function it_rejects_unassigned_and_expired_benefits(): void
    {
        [$user, $token] = akubicaCustomerToken();
        app(MedicalServiceCartService::class)->addBasicPlan($user->customer);
        $code = $this->code('EXPIRED');
        $this->coupon($user, $code, CouponType::Balance, 5000, ['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/cart/medical-benefit', [
            'service_line' => 'medical_services', 'code' => $code,
        ], authHeaders($token))->assertStatus(409)->assertJsonPath('error.code', 'COUPON_EXPIRED');

        $this->postJson('/api/v1/cart/medical-benefit', [
            'service_line' => 'medical_services', 'code' => 'M2C-ISO-NOT-ASSIGNED',
        ], authHeaders($token))->assertNotFound()->assertJsonPath('error.code', 'COUPON_NOT_FOUND');
    }

    #[Test]
    public function it_isolates_medical_benefits_and_ownership_from_laboratory(): void
    {
        $owner = User::factory()->withRegularCustomer()->create(['email' => 'm2c-owner-'.uniqid().'@example.test']);
        $ownerToken = $owner->createToken('m2c-owner')->plainTextToken;
        $other = User::factory()->withRegularCustomer()->create(['email' => 'm2c-other-'.uniqid().'@example.test']);
        $otherToken = $other->createToken('m2c-other')->plainTextToken;
        $this->assertNotSame($owner->id, $other->id);
        app(MedicalServiceCartService::class)->addBasicPlan($owner->customer);
        LaboratoryCartItem::factory()->create(['customer_id' => $owner->customer->id]);
        $code = $this->code('OWNED');
        $this->coupon($owner, $code, CouponType::Balance, 5000);

        $this->actingAs($other, 'sanctum');
        $this->postJson('/api/v1/cart/medical-benefit', [
            'service_line' => 'medical_services', 'code' => $code,
        ])->assertNotFound();

        $this->actingAs($owner, 'sanctum');
        $this->postJson('/api/v1/cart/medical-benefit', [
            'service_line' => 'medical_services', 'code' => $code,
        ])->assertOk();
        $this->assertSame(1, $owner->customer->laboratoryCartItems()->count());
    }

    #[Test]
    public function it_recalculates_and_removes_without_accumulating_or_consuming(): void
    {
        [$user, $token] = akubicaCustomerToken();
        app(MedicalServiceCartService::class)->addBasicPlan($user->customer);
        $code = $this->code('RECALC');
        $coupon = $this->coupon($user, $code, CouponType::Coupon, 5000);
        $headers = authHeaders($token);

        $this->postJson('/api/v1/cart/medical-benefit', [
            'service_line' => 'medical_services', 'code' => $code,
        ], $headers)->assertOk();
        $itemId = $user->customer->medicalServiceCarts()->first()->items()->sole()->id;
        $this->deleteJson("/api/v1/cart/items/{$itemId}", [], $headers)->assertOk()
            ->assertJsonPath('data.totals.discount_cents', 0)
            ->assertJsonPath('data.totals.credit_applied_cents', 0)
            ->assertJsonPath('data.totals.total_cents', 0);
        $this->assertSame(5000, $coupon->fresh()->remaining_cents);
    }

    #[Test]
    public function it_replays_benefit_application_idempotently(): void
    {
        $user = User::factory()->withRegularCustomer()->create(['email' => 'm2c-idemp-'.uniqid().'@example.test']);
        $code = $this->code('IDEMP');
        $cart = app(MedicalServiceCartService::class)->addBasicPlan($user->customer);
        $this->coupon($user, $code, CouponType::Coupon, 5000);
        $this->actingAs($user, 'sanctum');
        $headers = [IdempotencyKey::HEADER => 'm2c-isolated-idempotency-'.$this->runId];
        $payload = ['service_line' => 'medical_services', 'code' => $code];

        $first = $this->postJson('/api/v1/cart/medical-benefit', $payload, $headers)->assertOk();
        $second = $this->postJson('/api/v1/cart/medical-benefit', $payload, $headers)->assertOk();
        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, $cart->fresh()->benefits()->count());
    }

    #[Test]
    public function it_removes_a_benefit_idempotently_and_leaves_totals_canonical(): void
    {
        [$user, $token] = akubicaCustomerToken();
        app(MedicalServiceCartService::class)->addBasicPlan($user->customer);
        $code = $this->code('REMOVE');
        $this->coupon($user, $code, CouponType::Balance, 5000);
        $headers = authHeaders($token);
        $this->postJson('/api/v1/cart/medical-benefit', [
            'service_line' => 'medical_services', 'code' => $code,
        ], $headers)->assertOk();

        $this->deleteJson('/api/v1/cart/medical-benefit?service_line=medical_services', [], $headers)
            ->assertOk()->assertJsonPath('data.removed', true)
            ->assertJsonPath('data.cart.totals.credit_applied_cents', 0)
            ->assertJsonPath('data.cart.totals.total_cents', 30000);
        $this->deleteJson('/api/v1/cart/medical-benefit?service_line=medical_services', [], $headers)
            ->assertOk()->assertJsonPath('data.removed', false);
    }

    private function coupon(User $user, string $code, CouponType $type, int $amountCents, array $attributes = []): Coupon
    {
        $coupon = Coupon::factory()->create(array_merge([
            'code' => $code, 'type' => $type,
            'amount_cents' => $amountCents, 'remaining_cents' => $amountCents,
        ], $attributes));
        assignUserCoupon($user, $coupon);

        return $coupon;
    }

    private function code(string $prefix): string
    {
        return 'M2C-ISO-'.$prefix.'-'.$this->runId;
    }
}
