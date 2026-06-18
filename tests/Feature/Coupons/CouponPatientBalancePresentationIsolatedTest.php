<?php

namespace Tests\Feature\Coupons;

/**
 * TEMPORAL / AISLADO — Presentación de saldo a favor para carrito/checkout de laboratorio.
 *
 * @see docs/modulo-saldo-a-favor-creditos-cupones.md
 */
use App\Enums\CouponApprovalStatus;
use App\Enums\CouponType;
use App\Exceptions\CouponApplicationException;
use App\Models\Coupon;
use App\Models\CouponUser;
use App\Models\User;
use App\Services\CouponApplicationService;
use App\Services\CouponBeneficiaryService;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

require_once __DIR__.'/couponIsolatedSchema.php';

class CouponPatientBalancePresentationIsolatedTest extends TestCase
{
    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        bootstrapIsolatedCouponModuleSchema();
    }

    protected function tearDown(): void
    {
        tearDownIsolatedCouponModuleSchema();

        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function build_patient_balance_presentation_incluye_saldo_y_cupones(): void
    {
        $user = $this->createUser();
        $service = app(CouponService::class);

        $this->seedAssignableCoupon(50_000, ['expires_at' => now()->addMonth()], $user);
        $this->seedAssignableCoupon(30_000, ['expires_at' => now()->addWeek()], $user);

        $presentation = $service->buildPatientBalancePresentation($user->id, 60_000);

        $this->assertSame(80_000, $presentation['balanceCouponsCents']);
        $this->assertSame('$800.00 MXN', $presentation['formattedBalanceCoupons']);
        $this->assertCount(2, $presentation['availableBalanceCoupons']);
        $this->assertSame(60_000, $presentation['cartTotalCents']);
    }

    #[Test]
    public function build_patient_balance_presentation_sin_saldo_devuelve_cero(): void
    {
        $user = $this->createUser();
        $service = app(CouponService::class);

        $presentation = $service->buildPatientBalancePresentation($user->id, 25_000);

        $this->assertSame(0, $presentation['balanceCouponsCents']);
        $this->assertNull($presentation['formattedBalanceCoupons']);
        $this->assertSame([], $presentation['availableBalanceCoupons']);
    }

    #[Test]
    public function build_patient_balance_presentation_excluye_cupones_vencidos(): void
    {
        $user = $this->createUser();
        $service = app(CouponService::class);

        $this->seedAssignableCoupon(40_000, ['expires_at' => now()->subHour()], $user);
        $valid = $this->seedAssignableCoupon(25_000, ['expires_at' => now()->addDay()], $user);

        $presentation = $service->buildPatientBalancePresentation($user->id, 30_000);

        $this->assertSame(25_000, $presentation['balanceCouponsCents']);
        $this->assertCount(1, $presentation['availableBalanceCoupons']);
        $this->assertSame($valid['coupon']->id, $presentation['availableBalanceCoupons'][0]['id']);
    }

    #[Test]
    public function build_patient_balance_presentation_no_incluye_beneficiarios_pending_user(): void
    {
        $service = app(CouponService::class);
        $beneficiaryService = app(CouponBeneficiaryService::class);
        $parent = Coupon::query()->create([
            'amount_cents' => 50_000,
            'remaining_cents' => 50_000,
            'type' => CouponType::Balance,
            'is_active' => true,
            'approval_status' => CouponApprovalStatus::Active,
        ]);

        $beneficiaryService->confirmRows($parent, [
            ['email' => 'pendiente@test.local', 'first_name' => 'Pedro'],
        ]);

        $user = $this->createUser('pendiente@test.local');

        $presentation = $service->buildPatientBalancePresentation($user->id, 60_000);

        $this->assertSame(0, $presentation['balanceCouponsCents']);
        $this->assertSame([], $presentation['availableBalanceCoupons']);
        $this->assertSame(0, CouponUser::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function build_patient_balance_presentation_incluye_min_purchase_en_cupones(): void
    {
        $user = $this->createUser();
        $service = app(CouponService::class);

        $this->seedAssignableCoupon(50_000, [
            'min_purchase_cents' => 80_000,
            'expires_at' => now()->addMonth(),
        ], $user);

        $presentation = $service->buildPatientBalancePresentation($user->id, 60_000);

        $this->assertSame(80_000, $presentation['availableBalanceCoupons'][0]['min_purchase_cents']);
        $this->assertSame('$800.00 MXN', $presentation['availableBalanceCoupons'][0]['formatted_min_purchase']);
    }

    #[Test]
    public function checkout_rechaza_cupon_con_saldo_mayor_al_total(): void
    {
        ['user' => $user, 'coupon' => $coupon] = $this->seedAssignableCoupon(50_000);

        $this->expectException(CouponApplicationException::class);
        $this->expectExceptionMessage('mayor al total de la compra');

        app(CouponApplicationService::class)->validateApplication($user, $coupon->id, 30_000);
    }

    #[Test]
    public function checkout_rechaza_cupon_si_no_cumple_compra_minima(): void
    {
        ['user' => $user, 'coupon' => $coupon] = $this->seedAssignableCoupon(40_000, [
            'min_purchase_cents' => 80_000,
        ]);

        $this->expectException(CouponApplicationException::class);
        $this->expectExceptionMessage('Para usar tu saldo a favor necesitas una compra mínima');

        app(CouponApplicationService::class)->validateApplication($user, $coupon->id, 60_000);
    }

    #[Test]
    public function checkout_permite_cupon_aplicable_cuando_cumple_reglas(): void
    {
        ['user' => $user, 'coupon' => $coupon] = $this->seedAssignableCoupon(40_000, [
            'min_purchase_cents' => 30_000,
        ]);

        app(CouponApplicationService::class)->validateApplication($user, $coupon->id, 50_000);

        $this->assertTrue(true);
    }

    private function createUser(?string $email = null): User
    {
        return User::query()->create([
            'name' => 'Usuario Test',
            'email' => $email ?? 'user-'.uniqid().'@test.local',
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
