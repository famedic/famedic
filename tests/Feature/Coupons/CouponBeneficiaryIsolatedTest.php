<?php

namespace Tests\Feature\Coupons;

/**
 * TEMPORAL / AISLADO — Beneficiarios no registrados y carga masiva base (Fase B1).
 *
 * @see docs/modulo-saldo-a-favor-creditos-cupones.md
 */
use App\Enums\CouponApprovalStatus;
use App\Enums\CouponBeneficiaryStatus;
use App\Enums\CouponType;
use App\Models\Coupon;
use App\Models\CouponBeneficiary;
use App\Models\CouponUser;
use App\Models\User;
use App\Services\CouponBeneficiaryService;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

require_once __DIR__.'/couponIsolatedSchema.php';

class CouponBeneficiaryIsolatedTest extends TestCase
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
    public function preview_detecta_usuario_registrado(): void
    {
        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon();
        $user = $this->createUser('registrado@test.local');

        $preview = $service->previewRows($parent, [
            ['email' => $user->email, 'first_name' => 'Ana'],
        ]);

        $this->assertSame('valid_registered_user', $preview['rows'][0]['status']);
        $this->assertSame($user->id, $preview['rows'][0]['user_id']);
    }

    #[Test]
    public function preview_detecta_beneficiario_pendiente(): void
    {
        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon();

        $preview = $service->previewRows($parent, [
            ['email' => 'nuevo@test.local', 'first_name' => 'Luis'],
        ]);

        $this->assertSame('valid_pending_user', $preview['rows'][0]['status']);
        $this->assertNull($preview['rows'][0]['user_id']);
    }

    #[Test]
    public function preview_detecta_email_invalido(): void
    {
        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon();

        $preview = $service->previewRows($parent, [
            ['email' => 'no-es-email'],
        ]);

        $this->assertSame('invalid_email', $preview['rows'][0]['status']);
    }

    #[Test]
    public function preview_detecta_duplicado_en_archivo(): void
    {
        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon();

        $preview = $service->previewRows($parent, [
            ['email' => 'dup@test.local'],
            ['email' => 'dup@test.local'],
        ]);

        $this->assertSame('duplicate_in_file', $preview['rows'][1]['status']);
    }

    #[Test]
    public function preview_detecta_email_ya_beneficiario(): void
    {
        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon();

        CouponBeneficiary::query()->create([
            'parent_coupon_id' => $parent->id,
            'email' => 'ya@test.local',
            'email_normalized' => 'ya@test.local',
            'status' => CouponBeneficiaryStatus::PendingUser,
            'source' => 'manual',
        ]);

        $preview = $service->previewRows($parent, [
            ['email' => 'ya@test.local'],
        ]);

        $this->assertSame('already_beneficiary', $preview['rows'][0]['status']);
    }

    #[Test]
    public function confirm_asigna_usuario_registrado_con_coupon_user(): void
    {
        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon();
        $user = $this->createUser('asignar@test.local');

        $result = $service->confirmRows($parent, [
            ['email' => $user->email, 'first_name' => 'María'],
        ], sendNotifications: false);

        $this->assertSame(1, $result['assigned_count']);
        $this->assertSame(0, $result['pending_count']);
        $this->assertTrue(
            CouponUser::query()->where('user_id', $user->id)->whereHas('coupon', fn ($q) => $q->where('parent_coupon_id', $parent->id))->exists()
        );
    }

    #[Test]
    public function confirm_crea_pending_user_sin_coupon_user(): void
    {
        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon();

        $result = $service->confirmRows($parent, [
            ['email' => 'pendiente@test.local', 'first_name' => 'Pedro'],
        ]);

        $this->assertSame(0, $result['assigned_count']);
        $this->assertSame(1, $result['pending_count']);
        $this->assertSame(0, CouponUser::query()->count());

        $beneficiary = CouponBeneficiary::query()->first();
        $this->assertSame(CouponBeneficiaryStatus::PendingUser, $beneficiary->status);
        $this->assertNull($beneficiary->child_coupon_id);
    }

    #[Test]
    public function confirm_respeta_unique_campana_email(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        $parent = $this->seedMasterCoupon();

        CouponBeneficiary::query()->create([
            'parent_coupon_id' => $parent->id,
            'email' => 'dup@test.local',
            'email_normalized' => 'dup@test.local',
            'status' => CouponBeneficiaryStatus::PendingUser,
            'source' => 'manual',
        ]);

        CouponBeneficiary::query()->create([
            'parent_coupon_id' => $parent->id,
            'email' => 'dup@test.local',
            'email_normalized' => 'dup@test.local',
            'status' => CouponBeneficiaryStatus::PendingUser,
            'source' => 'manual',
        ]);
    }

    #[Test]
    public function confirm_respeta_max_beneficiaries_contando_pendientes(): void
    {
        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon(['max_beneficiaries' => 1]);

        $service->confirmRows($parent, [
            ['email' => 'pendiente1@test.local'],
        ]);

        $this->expectException(\DomainException::class);
        $service->confirmRows($parent, [
            ['email' => 'pendiente2@test.local'],
        ]);
    }

    #[Test]
    public function confirm_copia_vigencia_y_minimo_en_hijos(): void
    {
        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon([
            'valid_from' => now()->subDay(),
            'expires_at' => now()->addMonth(),
            'min_purchase_cents' => 50_000,
        ]);
        $user = $this->createUser('vigencia@test.local');

        $service->confirmRows($parent, [
            ['email' => $user->email],
        ], sendNotifications: false);

        $child = Coupon::query()->where('parent_coupon_id', $parent->id)->first();
        $this->assertNotNull($child);
        $this->assertSame(50_000, $child->min_purchase_cents);
        $this->assertNotNull($child->valid_from);
        $this->assertNotNull($child->expires_at);
    }

    #[Test]
    public function get_user_balance_no_cuenta_pendientes_sin_usuario(): void
    {
        $couponService = app(CouponService::class);
        $beneficiaryService = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon();
        $user = $this->createUser('balance@test.local');

        $beneficiaryService->confirmRows($parent, [
            ['email' => 'solo-pendiente@test.local'],
        ]);
        $beneficiaryService->confirmRows($parent, [
            ['email' => $user->email],
        ], sendNotifications: false);

        $this->assertSame($parent->amount_cents, $couponService->getUserBalance($user->id));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedMasterCoupon(array $overrides = []): Coupon
    {
        return Coupon::query()->create(array_merge([
            'amount_cents' => 100_000,
            'remaining_cents' => 100_000,
            'type' => CouponType::Balance,
            'is_active' => true,
            'approval_status' => CouponApprovalStatus::Active,
        ], $overrides));
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'name' => 'Test User',
            'email' => $email,
            'password' => 'secret',
        ]);
    }
}
