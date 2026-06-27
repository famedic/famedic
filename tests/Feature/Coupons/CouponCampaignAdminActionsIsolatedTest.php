<?php

namespace Tests\Feature\Coupons;

/**
 * TEMPORAL / AISLADO — Desactivación y eliminación de campañas admin (Fase MC-2b).
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

class CouponCampaignAdminActionsIsolatedTest extends TestCase
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
    public function desactivar_campana_activa_cambia_is_active_a_false(): void
    {
        $parent = $this->createParentCoupon();

        $this->couponService->deactivateCampaign($parent, $this->adminUser());

        $this->assertFalse($parent->fresh()->is_active);
    }

    #[Test]
    public function desactivar_campana_genera_audit_log(): void
    {
        $parent = $this->createParentCoupon();
        $actor = $this->adminUser();

        $this->couponService->deactivateCampaign($parent, $actor);

        $log = CouponAuditLog::query()
            ->where('coupon_id', $parent->id)
            ->where('action', 'coupon_campaign_deactivated')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($actor->id, $log->actor_user_id);
    }

    #[Test]
    public function desactivar_campana_no_revoca_hijos_existentes(): void
    {
        [$child, $assignment] = $this->createAssignedCreditPair();

        $this->couponService->deactivateCampaign($child->parentCoupon, $this->adminUser());

        $this->assertTrue($child->fresh()->is_active);
        $this->assertSame(50000, $child->fresh()->remaining_cents);
        $this->assertNull($assignment->fresh()->used_at);
    }

    #[Test]
    public function desactivar_campana_no_cancela_pendientes_existentes(): void
    {
        $parent = $this->createParentCoupon();
        $beneficiary = $this->createPendingBeneficiary($parent);

        $this->couponService->deactivateCampaign($parent, $this->adminUser());

        $this->assertSame(CouponBeneficiaryStatus::PendingUser, $beneficiary->fresh()->status);
        $this->assertNull($beneficiary->fresh()->cancelled_at);
    }

    #[Test]
    public function campana_inactiva_no_permite_nuevas_asignaciones(): void
    {
        $parent = $this->createParentCoupon(['is_active' => false]);
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Esta campaña está inactiva y no permite nuevas asignaciones.');

        $this->couponService->assignUserToCampaignCoupon($user, $parent);
    }

    #[Test]
    public function campana_inactiva_no_permite_carga_masiva(): void
    {
        $parent = $this->createParentCoupon(['is_active' => false]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Esta campaña está inactiva y no permite nuevas asignaciones.');

        $this->beneficiaryService->confirmRows(
            $parent,
            [['email' => 'nuevo@example.com']],
            $this->adminUser(),
        );
    }

    #[Test]
    public function campana_inactiva_no_permite_reenviar_invitacion(): void
    {
        $parent = $this->createParentCoupon(['is_active' => false]);
        $beneficiary = $this->createPendingBeneficiary($parent);
        $beneficiary->update([
            'invitation_sent_at' => now()->subHour(),
            'last_invitation_sent_at' => now()->subHour(),
            'invitation_count' => 1,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Esta campaña está inactiva y no permite nuevas asignaciones.');

        $this->beneficiaryService->resendPendingInvitation($beneficiary->fresh(), $this->adminUser());
    }

    #[Test]
    public function eliminar_campana_sin_actividad_funciona(): void
    {
        $parent = $this->createParentCoupon();
        $id = $parent->id;

        $this->couponService->deleteCampaignIfUnused($parent, $this->adminUser());

        $this->assertNull(Coupon::query()->find($id));
    }

    #[Test]
    public function eliminar_campana_sin_actividad_genera_audit_log(): void
    {
        $parent = $this->createParentCoupon();
        $id = $parent->id;
        $actor = $this->adminUser();

        $this->couponService->deleteCampaignIfUnused($parent, $actor);

        $log = CouponAuditLog::query()
            ->where('action', 'coupon_campaign_deleted')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($id, (int) ($log->context['coupon_id'] ?? 0));
        $this->assertSame($actor->id, $log->actor_user_id);
    }

    #[Test]
    public function eliminar_campana_con_hijo_falla(): void
    {
        [$child] = $this->createAssignedCreditPair();
        $parent = $child->parentCoupon;

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('No se puede eliminar esta campaña porque ya tiene actividad.');

        $this->couponService->deleteCampaignIfUnused($parent, $this->adminUser());
    }

    #[Test]
    public function eliminar_campana_con_beneficiario_falla(): void
    {
        $parent = $this->createParentCoupon();
        $this->createPendingBeneficiary($parent);

        $this->expectException(\DomainException::class);

        $this->couponService->deleteCampaignIfUnused($parent, $this->adminUser());
    }

    #[Test]
    public function eliminar_campana_con_transaccion_falla(): void
    {
        [$child, , , $parent] = $this->createAssignedCreditPair();
        $user = User::factory()->create();
        CouponTransaction::query()->create([
            'coupon_id' => $child->id,
            'user_id' => $user->id,
            'purchase_type' => CouponPurchaseType::Lab,
            'purchase_id' => 1,
            'amount_used_cents' => 10000,
        ]);

        $this->expectException(\DomainException::class);

        $this->couponService->deleteCampaignIfUnused($parent, $this->adminUser());
    }

    #[Test]
    public function eliminar_campana_con_asignacion_directa_falla(): void
    {
        $parent = $this->createParentCoupon();
        $user = User::factory()->create();
        CouponUser::query()->create([
            'coupon_id' => $parent->id,
            'user_id' => $user->id,
            'assigned_at' => now(),
        ]);

        $this->expectException(\DomainException::class);

        $this->couponService->deleteCampaignIfUnused($parent, $this->adminUser());
    }

    #[Test]
    public function resumen_actividad_y_flags_para_ui(): void
    {
        $parent = $this->createParentCoupon();
        $summary = $this->couponService->getCampaignActivitySummary($parent);

        $this->assertFalse($summary['has_activity']);
        $this->assertSame(0, $summary['children_count']);
        $this->assertSame(0, $summary['beneficiaries_count']);
        $this->assertSame(0, $summary['assignments_count']);
        $this->assertSame(0, $summary['transactions_count']);
        $this->assertTrue($parent->is_active);
    }

    #[Test]
    public function campana_con_actividad_reporta_has_activity_true(): void
    {
        $parent = $this->createParentCoupon();
        $this->createPendingBeneficiary($parent);

        $summary = $this->couponService->getCampaignActivitySummary($parent);

        $this->assertTrue($summary['has_activity']);
        $this->assertSame(1, $summary['beneficiaries_count']);
    }

    #[Test]
    public function accion_delete_rechazada_genera_audit_log(): void
    {
        $parent = $this->createParentCoupon();
        $this->createPendingBeneficiary($parent);
        $actor = $this->adminUser();

        try {
            $this->couponService->deleteCampaignIfUnused($parent, $actor);
        } catch (\DomainException) {
            // expected
        }

        $log = CouponAuditLog::query()
            ->where('coupon_id', $parent->id)
            ->where('action', 'coupon_campaign_delete_rejected')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($actor->id, $log->actor_user_id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createParentCoupon(array $overrides = []): Coupon
    {
        return Coupon::query()->create(array_merge([
            'amount_cents' => 50000,
            'remaining_cents' => 0,
            'type' => CouponType::Balance,
            'is_active' => true,
            'approval_status' => CouponApprovalStatus::Active,
            'max_beneficiaries' => 5,
        ], $overrides));
    }

    private function createPendingBeneficiary(Coupon $parent, string $email = 'pendiente@example.com'): CouponBeneficiary
    {
        return CouponBeneficiary::query()->create([
            'parent_coupon_id' => $parent->id,
            'email' => $email,
            'email_normalized' => CouponBeneficiary::normalizeEmail($email),
            'status' => CouponBeneficiaryStatus::PendingUser,
            'source' => CouponBeneficiarySource::Manual,
        ]);
    }

    /**
     * @return array{0: Coupon, 1: CouponUser, 2: User, 3: Coupon}
     */
    private function createAssignedCreditPair(): array
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

        return [$child, $assignment, $user, $parent];
    }

    private function adminUser(): User
    {
        return $this->adminUserWithPermissions(['cupones.edit']);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function adminUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $administrator = Administrator::factory()->create(['user_id' => $user->id]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
            $administrator->givePermissionTo($permission);
        }

        return $user;
    }
}
