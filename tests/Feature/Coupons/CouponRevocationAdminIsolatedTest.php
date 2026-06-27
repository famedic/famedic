<?php

namespace Tests\Feature\Coupons;

/**
 * TEMPORAL / AISLADO — Revocación y cancelación admin (Fase MC-2a).
 *
 * @see docs/modulo-saldo-a-favor-creditos-cupones.md
 */
use App\Enums\CouponApprovalStatus;
use App\Enums\CouponBeneficiarySource;
use App\Enums\CouponBeneficiaryStatus;
use App\Enums\CouponPurchaseType;
use App\Enums\CouponType;
use App\Models\Administrator;
use App\Models\Coupon;
use App\Models\CouponAuditLog;
use App\Models\CouponBeneficiary;
use App\Models\CouponTransaction;
use App\Models\CouponUser;
use App\Models\Permission;
use App\Models\User;
use App\Services\CouponBeneficiaryService;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

require_once __DIR__.'/couponIsolatedSchema.php';

class CouponRevocationAdminIsolatedTest extends TestCase
{
    private CouponService $couponService;

    private CouponBeneficiaryService $beneficiaryService;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        bootstrapIsolatedCouponRevocationSchema();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->couponService = app(CouponService::class);
        $this->beneficiaryService = app(CouponBeneficiaryService::class);
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
    public function cancelar_beneficiario_pendiente_cambia_status_a_cancelled(): void
    {
        $beneficiary = $this->createPendingBeneficiary();

        $this->beneficiaryService->cancelPendingBeneficiary($beneficiary->fresh(), $this->adminUser());

        $this->assertSame(CouponBeneficiaryStatus::Cancelled, $beneficiary->fresh()->status);
    }

    #[Test]
    public function cancelar_pendiente_setea_cancelled_at(): void
    {
        $beneficiary = $this->createPendingBeneficiary();

        $this->beneficiaryService->cancelPendingBeneficiary($beneficiary->fresh(), $this->adminUser());

        $this->assertNotNull($beneficiary->fresh()->cancelled_at);
    }

    #[Test]
    public function pendiente_cancelado_ya_no_cuenta_contra_cupo(): void
    {
        $parent = $this->createParentCoupon(maxBeneficiaries: 1);
        $this->createPendingBeneficiary($parent);

        $this->assertSame(1, $this->beneficiaryService->countActiveBeneficiarySlots($parent));

        $pending = CouponBeneficiary::query()->where('parent_coupon_id', $parent->id)->first();
        $this->beneficiaryService->cancelPendingBeneficiary($pending, $this->adminUser());

        $this->assertSame(0, $this->beneficiaryService->countActiveBeneficiarySlots($parent->fresh()));
        $this->assertSame(1, $this->beneficiaryService->remainingBeneficiarySlots($parent->fresh()));
    }

    #[Test]
    public function pendiente_cancelado_no_se_vincula_al_verificar_email(): void
    {
        $parent = $this->createParentCoupon();
        $beneficiary = $this->createPendingBeneficiary($parent, 'pendiente@example.com');
        $this->beneficiaryService->cancelPendingBeneficiary($beneficiary->fresh(), $this->adminUser());

        $user = User::factory()->create([
            'email' => 'pendiente@example.com',
            'email_verified_at' => now(),
        ]);

        $result = $this->beneficiaryService->linkPendingBeneficiariesForUser($user);

        $this->assertSame(0, $result['linked_count']);
        $this->assertNull($beneficiary->fresh()->child_coupon_id);
    }

    #[Test]
    public function pendiente_cancelado_no_permite_reenviar_invitacion(): void
    {
        $beneficiary = $this->createPendingBeneficiary();
        $this->beneficiaryService->cancelPendingBeneficiary($beneficiary->fresh(), $this->adminUser());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Este beneficiario pendiente fue cancelado.');

        $this->beneficiaryService->resendPendingInvitation($beneficiary->fresh(), $this->adminUser());
    }

    #[Test]
    public function revocar_credito_asignado_no_usado_lo_oculta_de_checkout(): void
    {
        [$child, $assignment, $user] = $this->createAssignedCredit();

        $this->couponService->revokeAssignment($assignment->fresh(), $this->adminUser());

        $presentation = $this->couponService->buildCheckoutCreditPresentation($user->id, 100000);
        $ids = array_column($presentation['coupons'] ?? [], 'id');

        $this->assertNotContains($child->id, $ids);
    }

    #[Test]
    public function revocar_credito_asignado_no_usado_pone_hijo_inactivo(): void
    {
        [$child, $assignment] = $this->createAssignedCredit();

        $this->couponService->revokeAssignment($assignment->fresh(), $this->adminUser());

        $this->assertFalse($child->fresh()->is_active);
    }

    #[Test]
    public function revocar_credito_asignado_no_usado_pone_remaining_cents_en_cero(): void
    {
        [$child, $assignment] = $this->createAssignedCredit();

        $this->couponService->revokeAssignment($assignment->fresh(), $this->adminUser());

        $this->assertSame(0, $child->fresh()->remaining_cents);
    }

    #[Test]
    public function revocar_credito_sincroniza_beneficiary_a_cancelled(): void
    {
        [$child, $assignment, $user, $parent] = $this->createAssignedCredit(withBeneficiary: true);
        unset($user, $parent);

        $this->couponService->revokeAssignment($assignment->fresh(), $this->adminUser());

        $beneficiary = CouponBeneficiary::query()->where('child_coupon_id', $child->id)->first();
        $this->assertNotNull($beneficiary);
        $this->assertSame(CouponBeneficiaryStatus::Cancelled, $beneficiary->status);
        $this->assertNotNull($beneficiary->cancelled_at);
    }

    #[Test]
    public function no_permite_revocar_credito_usado_con_transaccion_activa(): void
    {
        [$child, $assignment, $user] = $this->createAssignedCredit();
        $assignment->update(['used_at' => now()]);
        CouponTransaction::query()->create([
            'coupon_id' => $child->id,
            'user_id' => $user->id,
            'purchase_type' => CouponPurchaseType::Lab,
            'purchase_id' => 1,
            'amount_used_cents' => $child->amount_cents,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Este crédito ya fue utilizado');

        $this->couponService->revokeAssignment($assignment->fresh(), $this->adminUser());
    }

    #[Test]
    public function permite_revocar_credito_revertido_restaurado(): void
    {
        [$child, $assignment, $user] = $this->createAssignedCredit();
        CouponTransaction::query()->create([
            'coupon_id' => $child->id,
            'user_id' => $user->id,
            'purchase_type' => CouponPurchaseType::Lab,
            'purchase_id' => 1,
            'amount_used_cents' => $child->amount_cents,
            'reversed_at' => now(),
            'reversal_reason' => 'laboratory_purchase_cancelled',
        ]);
        $child->update(['remaining_cents' => $child->amount_cents, 'is_active' => true]);
        $assignment->update(['used_at' => null]);

        $this->couponService->revokeAssignment($assignment->fresh(), $this->adminUser());

        $this->assertFalse($child->fresh()->is_active);
        $this->assertSame(0, $child->fresh()->remaining_cents);
    }

    #[Test]
    public function revocacion_genera_audit_log(): void
    {
        [, $assignment] = $this->createAssignedCredit();
        $admin = $this->adminUser();

        $this->couponService->revokeAssignment($assignment->fresh(), $admin);

        $this->assertDatabaseHas('coupon_audit_logs', [
            'action' => 'coupon_assignment_revoked',
            'actor_user_id' => $admin->id,
        ]);
    }

    #[Test]
    public function cancelacion_pendiente_genera_audit_log(): void
    {
        $beneficiary = $this->createPendingBeneficiary();
        $admin = $this->adminUser();

        $this->beneficiaryService->cancelPendingBeneficiary($beneficiary->fresh(), $admin);

        $this->assertDatabaseHas('coupon_audit_logs', [
            'action' => 'coupon_beneficiary_cancelled',
            'actor_user_id' => $admin->id,
        ]);
    }

    #[Test]
    public function endpoint_cancelar_pendiente_requiere_permiso_edit(): void
    {
        $parent = $this->createParentCoupon();
        $beneficiary = $this->createPendingBeneficiary($parent);
        $user = User::factory()->create();
        Administrator::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('admin.coupons.beneficiaries.cancel', [
                'coupon' => $parent->id,
                'beneficiary' => $beneficiary->id,
            ]))
            ->assertRedirect();

        $this->assertSame(CouponBeneficiaryStatus::PendingUser, $beneficiary->fresh()->status);
        $this->assertNull($beneficiary->fresh()->cancelled_at);
    }

    #[Test]
    public function endpoint_revocar_requiere_permiso_edit(): void
    {
        [$child, $assignment] = $this->createAssignedCredit();
        $adminUser = User::factory()->create();
        Administrator::factory()->create(['user_id' => $adminUser->id]);

        $this->actingAs($adminUser)
            ->delete(route('admin.coupons.assignments.destroy', [
                'coupon' => $child->id,
                'couponUser' => $assignment->id,
            ]))
            ->assertRedirect();

        $this->assertTrue($child->fresh()->is_active);
        $this->assertGreaterThan(0, $child->fresh()->remaining_cents);
    }

    private function adminUser(): User
    {
        $user = User::factory()->create();
        $administrator = Administrator::factory()->create(['user_id' => $user->id]);
        $permission = Permission::query()->firstOrCreate([
            'name' => 'cupones.edit',
            'guard_name' => 'web',
        ]);
        $administrator->givePermissionTo($permission);

        return $user;
    }

    private function createParentCoupon(?int $maxBeneficiaries = 5): Coupon
    {
        return Coupon::query()->create([
            'amount_cents' => 50000,
            'remaining_cents' => 0,
            'type' => CouponType::Balance,
            'is_active' => true,
            'approval_status' => CouponApprovalStatus::Active,
            'max_beneficiaries' => $maxBeneficiaries,
        ]);
    }

    private function createPendingBeneficiary(?Coupon $parent = null, string $email = 'pendiente@example.com'): CouponBeneficiary
    {
        $parent ??= $this->createParentCoupon();

        return CouponBeneficiary::query()->create([
            'parent_coupon_id' => $parent->id,
            'email' => $email,
            'email_normalized' => CouponBeneficiary::normalizeEmail($email),
            'status' => CouponBeneficiaryStatus::PendingUser,
            'source' => CouponBeneficiarySource::Manual,
        ]);
    }

    /**
     * @return array{0: Coupon, 1: CouponUser, 2: User, 3?: Coupon}
     */
    private function createAssignedCredit(bool $withBeneficiary = false): array
    {
        $parent = $this->createParentCoupon();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $child = Coupon::query()->create([
            'parent_coupon_id' => $parent->id,
            'amount_cents' => 50000,
            'remaining_cents' => 50000,
            'type' => CouponType::Balance,
            'is_active' => true,
            'approval_status' => CouponApprovalStatus::Active,
        ]);
        $assignment = CouponUser::query()->create([
            'coupon_id' => $child->id,
            'user_id' => $user->id,
            'assigned_at' => now(),
        ]);

        if ($withBeneficiary) {
            CouponBeneficiary::query()->create([
                'parent_coupon_id' => $parent->id,
                'child_coupon_id' => $child->id,
                'user_id' => $user->id,
                'email' => $user->email,
                'email_normalized' => CouponBeneficiary::normalizeEmail($user->email),
                'status' => CouponBeneficiaryStatus::Assigned,
                'source' => CouponBeneficiarySource::Manual,
                'assigned_at' => now(),
            ]);
        }

        return [$child, $assignment, $user, $parent];
    }
}
