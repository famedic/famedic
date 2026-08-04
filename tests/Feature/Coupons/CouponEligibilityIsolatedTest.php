<?php

namespace Tests\Feature\Coupons;

/**
 * TEMPORAL / AISLADO — Validación de vigencia y compra mínima sin migraciones históricas.
 *
 * @see docs/modulo-saldo-a-favor-creditos-cupones.md
 */
use App\Enums\CouponApprovalStatus;
use App\Enums\CouponPurchaseType;
use App\Enums\CouponType;
use App\Exceptions\CouponApplicationException;
use App\Models\Coupon;
use App\Models\CouponTransaction;
use App\Models\CouponUser;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use App\Services\CouponApplicationService;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

require_once __DIR__.'/couponIsolatedSchema.php';

class CouponEligibilityIsolatedTest extends TestCase
{
    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        bootstrapIsolatedCouponModuleSchema();
    }

    protected function tearDown(): void
    {
        tearDownIsolatedCouponReversalSchema();
        tearDownIsolatedCouponModuleSchema();

        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function cupon_sin_restricciones_funciona_como_hoy(): void
    {
        ['user' => $user, 'coupon' => $coupon] = $this->seedAssignableCoupon(50_000);

        app(CouponApplicationService::class)->validateApplication($user, $coupon->id, 60_000);

        $this->assertTrue($coupon->fresh()->isWithinValidityWindow());
        $this->assertTrue($coupon->meetsMinimumPurchase(60_000));
    }

    #[Test]
    public function cupon_con_valid_from_futuro_no_aplica(): void
    {
        ['user' => $user, 'coupon' => $coupon] = $this->seedAssignableCoupon(50_000, [
            'valid_from' => now()->addDay(),
        ]);

        $this->expectException(CouponApplicationException::class);
        $this->expectExceptionMessage('estará disponible a partir del');

        app(CouponApplicationService::class)->validateApplication($user, $coupon->id, 60_000);
    }

    #[Test]
    public function cupon_con_expires_at_pasado_no_aplica(): void
    {
        ['user' => $user, 'coupon' => $coupon] = $this->seedAssignableCoupon(50_000, [
            'expires_at' => now()->subDay(),
        ]);

        $this->expectException(CouponApplicationException::class);
        $this->expectExceptionMessage('venció el');

        app(CouponApplicationService::class)->validateApplication($user, $coupon->id, 60_000);
    }

    #[Test]
    public function cupon_dentro_de_ventana_de_vigencia_aplica(): void
    {
        ['user' => $user, 'coupon' => $coupon] = $this->seedAssignableCoupon(50_000, [
            'valid_from' => now()->subDay(),
            'expires_at' => now()->addDay(),
        ]);

        app(CouponApplicationService::class)->validateApplication($user, $coupon->id, 60_000);

        $this->assertSame('vigente', $coupon->fresh()->validity_status);
    }

    #[Test]
    public function cupon_con_min_purchase_y_total_menor_falla(): void
    {
        ['user' => $user, 'coupon' => $coupon] = $this->seedAssignableCoupon(50_000, [
            'min_purchase_cents' => 80_000,
        ]);

        $this->expectException(CouponApplicationException::class);
        $this->expectExceptionMessage('compra mínima');

        app(CouponApplicationService::class)->validateApplication($user, $coupon->id, 60_000);
    }

    #[Test]
    public function cupon_con_min_purchase_y_total_igual_aplica(): void
    {
        ['user' => $user, 'coupon' => $coupon] = $this->seedAssignableCoupon(50_000, [
            'min_purchase_cents' => 80_000,
        ]);

        app(CouponApplicationService::class)->validateApplication($user, $coupon->id, 80_000);

        $this->assertTrue($coupon->fresh()->meetsMinimumPurchase(80_000));
    }

    #[Test]
    public function cupon_con_min_purchase_y_total_mayor_aplica(): void
    {
        ['user' => $user, 'coupon' => $coupon] = $this->seedAssignableCoupon(50_000, [
            'min_purchase_cents' => 80_000,
        ]);

        app(CouponApplicationService::class)->validateApplication($user, $coupon->id, 100_000);

        $this->assertTrue($coupon->fresh()->meetsMinimumPurchase(100_000));
    }

    #[Test]
    public function get_user_balance_no_suma_cupones_vencidos(): void
    {
        $user = $this->createUser();
        $service = app(CouponService::class);

        $this->seedAssignableCoupon(30_000, ['expires_at' => now()->subHour()], $user);
        $this->seedAssignableCoupon(20_000, ['expires_at' => now()->addDay()], $user);

        $this->assertSame(20_000, $service->getUserBalance($user->id));
    }

    #[Test]
    public function get_available_coupons_no_devuelve_cupones_vencidos(): void
    {
        $user = $this->createUser();
        $service = app(CouponService::class);

        $this->seedAssignableCoupon(30_000, ['expires_at' => now()->subHour()], $user);
        $valid = $this->seedAssignableCoupon(25_000, ['expires_at' => now()->addDay()], $user);

        $available = $service->getAvailableCoupons($user->id);

        $this->assertCount(1, $available);
        $this->assertSame($valid['coupon']->id, $available->first()['id']);
    }

    #[Test]
    public function al_crear_cupon_hijo_se_copian_campos_de_vigencia_y_minimo(): void
    {
        bootstrapIsolatedCouponReversalSchema();

        $user = $this->createUser();
        $service = app(CouponService::class);

        $parent = Coupon::query()->create([
            'amount_cents' => 40_000,
            'remaining_cents' => 40_000,
            'valid_from' => now()->subDay(),
            'expires_at' => now()->addMonth(),
            'min_purchase_cents' => 90_000,
            'type' => CouponType::Balance,
            'is_active' => true,
            'approval_status' => CouponApprovalStatus::Active,
        ]);

        $child = $service->assignUserToCampaignCoupon($user, $parent, sendNotification: false);

        $this->assertSame(
            $parent->valid_from?->toIso8601String(),
            $child->valid_from?->toIso8601String()
        );
        $this->assertSame(
            $parent->expires_at?->toIso8601String(),
            $child->expires_at?->toIso8601String()
        );
        $this->assertSame(90_000, $child->min_purchase_cents);
    }

    #[Test]
    public function reverso_de_saldo_no_modifica_vigencia_ni_compra_minima(): void
    {
        bootstrapIsolatedCouponReversalSchema();

        $service = app(CouponApplicationService::class);

        $user = $this->createUser();
        $customer = Customer::query()->create(['user_id' => $user->id]);

        $coupon = Coupon::query()->create([
            'amount_cents' => 40_000,
            'remaining_cents' => 0,
            'valid_from' => now()->subWeek(),
            'expires_at' => now()->addWeek(),
            'min_purchase_cents' => 75_000,
            'type' => CouponType::Balance,
            'is_active' => true,
            'approval_status' => CouponApprovalStatus::Active,
        ]);

        CouponUser::query()->create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'assigned_at' => now()->subDay(),
            'used_at' => now(),
        ]);

        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $customer->id,
            'total_cents' => 100_000,
            'coupon_discount_cents' => 40_000,
        ]);

        CouponTransaction::query()->create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'purchase_type' => CouponPurchaseType::Lab,
            'purchase_id' => $purchase->id,
            'amount_used_cents' => 40_000,
        ]);

        $validFrom = $coupon->valid_from?->toIso8601String();
        $expiresAt = $coupon->expires_at?->toIso8601String();
        $minPurchase = $coupon->min_purchase_cents;

        $service->reverseForLaboratoryPurchase($purchase);

        $coupon->refresh();

        $this->assertSame($validFrom, $coupon->valid_from?->toIso8601String());
        $this->assertSame($expiresAt, $coupon->expires_at?->toIso8601String());
        $this->assertSame($minPurchase, $coupon->min_purchase_cents);
    }

    private function createUser(): User
    {
        return User::query()->create([
            'name' => 'Usuario Test',
            'email' => 'user-'.uniqid().'@test.local',
            'password' => 'secret',
        ]);
    }

    /**
     * @param  array<string, mixed>  $couponOverrides
     * @return array{user: User, coupon: Coupon, assignment: CouponUser}
     */
    private function seedAssignableCoupon(int $remainingCents, array $couponOverrides = [], ?User $user = null): array
    {
        $user ??= $this->createUser();

        $coupon = Coupon::query()->create(array_merge([
            'amount_cents' => $remainingCents,
            'remaining_cents' => $remainingCents,
            'type' => CouponType::Balance,
            'is_active' => true,
            'approval_status' => CouponApprovalStatus::Active,
        ], $couponOverrides));

        $assignment = CouponUser::query()->create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'assigned_at' => now(),
        ]);

        return compact('user', 'coupon', 'assignment');
    }
}
