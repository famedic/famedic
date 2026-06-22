<?php

namespace Tests\Feature\Coupons;

/**
 * TEMPORAL / AISLADO — Presentación de múltiples créditos (Fase MC-1).
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

class CouponMultipleCreditsPresentationIsolatedTest extends TestCase
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
    public function juan_scenario_carrito_451_distingue_aplicable_y_condicional(): void
    {
        $user = $this->createUser();
        $service = app(CouponService::class);

        $creditA = $this->seedAssignableCoupon(20_000, [], $user);
        $creditB = $this->seedAssignableCoupon(50_000, [
            'min_purchase_cents' => 200_000,
            'expires_at' => now()->endOfMonth(),
        ], $user);

        $presentation = $service->buildCheckoutCreditPresentation($user->id, 45_100);

        $this->assertSame(70_000, $presentation['total_balance_cents']);
        $this->assertSame(20_000, $presentation['applicable_balance_cents']);
        $this->assertSame(50_000, $presentation['conditional_balance_cents']);
        $this->assertSame(1, $presentation['applicable_coupons_count']);
        $this->assertSame(1, $presentation['conditional_coupons_count']);

        $byId = collect($presentation['coupons'])->keyBy('id');
        $this->assertSame('applicable', $byId[$creditA['coupon']->id]['reason']);
        $this->assertSame('below_minimum', $byId[$creditB['coupon']->id]['reason']);
        $this->assertSame(154_900, $byId[$creditB['coupon']->id]['missing_for_minimum_cents']);
        $this->assertSame($creditA['coupon']->id, $presentation['best_coupon']['id']);

        $this->assertSame(70_000, $presentation['balanceCouponsCents']);
        $this->assertCount(2, $presentation['availableBalanceCoupons']);
        $this->assertSame(45_100, $presentation['cartTotalCents']);
    }

    #[Test]
    public function carrito_2500_ambos_aplicables_y_recomendacion_hibrida(): void
    {
        $user = $this->createUser();
        $service = app(CouponService::class);

        $this->seedAssignableCoupon(20_000, [], $user);
        $expiringSoon = $this->seedAssignableCoupon(50_000, [
            'expires_at' => now()->addWeek(),
        ], $user);

        $presentation = $service->buildCheckoutCreditPresentation($user->id, 250_000);

        $this->assertSame(2, $presentation['applicable_coupons_count']);
        $this->assertSame(0, $presentation['conditional_coupons_count']);
        $this->assertSame(70_000, $presentation['applicable_balance_cents']);
        $this->assertSame($expiringSoon['coupon']->id, $presentation['best_coupon']['id']);
    }

    #[Test]
    public function recomendacion_hibrida_sin_vencimiento_elige_mayor_monto(): void
    {
        $user = $this->createUser();
        $service = app(CouponService::class);

        $this->seedAssignableCoupon(20_000, [], $user);
        $larger = $this->seedAssignableCoupon(50_000, [], $user);

        $presentation = $service->buildCheckoutCreditPresentation($user->id, 250_000);

        $this->assertSame($larger['coupon']->id, $presentation['best_coupon']['id']);
    }

    #[Test]
    public function cupon_vencido_no_aparece_en_presentacion_paciente(): void
    {
        $user = $this->createUser();
        $service = app(CouponService::class);

        $this->seedAssignableCoupon(40_000, ['expires_at' => now()->subHour()], $user);
        $valid = $this->seedAssignableCoupon(25_000, ['expires_at' => now()->addDay()], $user);

        $presentation = $service->buildCheckoutCreditPresentation($user->id, 30_000);

        $this->assertSame(25_000, $presentation['total_balance_cents']);
        $this->assertCount(1, $presentation['coupons']);
        $this->assertSame($valid['coupon']->id, $presentation['coupons'][0]['id']);
    }

    #[Test]
    public function cupon_programado_no_aparece_como_disponible(): void
    {
        $user = $this->createUser();
        $service = app(CouponService::class);

        $this->seedAssignableCoupon(30_000, ['valid_from' => now()->addWeek()], $user);
        $valid = $this->seedAssignableCoupon(20_000, [], $user);

        $presentation = $service->buildCheckoutCreditPresentation($user->id, 50_000);

        $this->assertSame(20_000, $presentation['total_balance_cents']);
        $this->assertCount(1, $presentation['coupons']);
        $this->assertSame($valid['coupon']->id, $presentation['coupons'][0]['id']);
    }

    #[Test]
    public function saldo_mayor_al_total_marca_balance_too_large(): void
    {
        $user = $this->createUser();
        $service = app(CouponService::class);

        $large = $this->seedAssignableCoupon(50_000, [], $user);

        $presentation = $service->buildCheckoutCreditPresentation($user->id, 30_000);

        $this->assertSame('balance_too_large', $presentation['coupons'][0]['reason']);
        $this->assertSame(0, $presentation['applicable_balance_cents']);
        $this->assertNull($presentation['best_coupon']);
    }

    #[Test]
    public function backend_permite_aplicar_credito_aplicable(): void
    {
        ['user' => $user, 'coupon' => $coupon] = $this->seedAssignableCoupon(20_000);

        app(CouponApplicationService::class)->validateApplication($user, $coupon->id, 45_100);

        $this->assertTrue(true);
    }

    #[Test]
    public function backend_rechaza_credito_con_compra_minima_no_cumplida(): void
    {
        ['user' => $user, 'coupon' => $coupon] = $this->seedAssignableCoupon(50_000, [
            'min_purchase_cents' => 200_000,
        ]);

        $this->expectException(CouponApplicationException::class);
        $this->expectExceptionMessage('Para usar tu saldo a favor necesitas una compra mínima');

        app(CouponApplicationService::class)->validateApplication($user, $coupon->id, 150_000);
    }

    #[Test]
    public function presentacion_excluye_beneficiarios_pending_user(): void
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

        $presentation = $service->buildCheckoutCreditPresentation($user->id, 60_000);

        $this->assertSame(0, $presentation['total_balance_cents']);
        $this->assertSame([], $presentation['coupons']);
        $this->assertSame(0, CouponUser::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function build_patient_balance_presentation_mantiene_compatibilidad(): void
    {
        $user = $this->createUser();
        $service = app(CouponService::class);

        $this->seedAssignableCoupon(20_000, [], $user);
        $this->seedAssignableCoupon(50_000, ['min_purchase_cents' => 200_000], $user);

        $presentation = $service->buildPatientBalancePresentation($user->id, 45_100);

        $this->assertArrayHasKey('balanceCouponsCents', $presentation);
        $this->assertArrayHasKey('availableBalanceCoupons', $presentation);
        $this->assertArrayHasKey('cartTotalCents', $presentation);
        $this->assertArrayHasKey('coupons', $presentation);
        $this->assertSame(70_000, $presentation['balanceCouponsCents']);
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
