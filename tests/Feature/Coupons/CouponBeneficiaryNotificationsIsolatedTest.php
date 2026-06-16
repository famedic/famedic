<?php

namespace Tests\Feature\Coupons;

/**
 * TEMPORAL / AISLADO — Invitaciones y activación de beneficiarios (Fase B2b).
 *
 * @see docs/modulo-saldo-a-favor-creditos-cupones.md
 */
use App\Enums\CouponApprovalStatus;
use App\Enums\CouponBeneficiaryStatus;
use App\Enums\CouponType;
use App\Listeners\LinkPendingCouponBeneficiaries;
use App\Mail\CouponBalanceActivatedMail;
use App\Mail\CouponPendingBalanceInvitationMail;
use App\Models\Coupon;
use App\Models\CouponBeneficiary;
use App\Models\CouponUser;
use App\Models\User;
use App\Services\CouponBeneficiaryService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use App\Services\NotificationService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

require_once __DIR__.'/couponIsolatedSchema.php';

class CouponBeneficiaryNotificationsIsolatedTest extends TestCase
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
    public function confirm_pendiente_envia_invitacion(): void
    {
        Mail::fake();

        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon();

        $service->confirmRows($parent, [
            ['email' => 'pendiente@test.local', 'first_name' => 'Ana'],
        ]);

        Mail::assertSent(CouponPendingBalanceInvitationMail::class, function (CouponPendingBalanceInvitationMail $mail) {
            return $mail->beneficiary->email === 'pendiente@test.local';
        });
    }

    #[Test]
    public function confirm_pendiente_actualiza_campos_invitacion(): void
    {
        Mail::fake();

        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon();

        $service->confirmRows($parent, [
            ['email' => 'invitado@test.local'],
        ]);

        $beneficiary = CouponBeneficiary::query()->first();
        $this->assertNotNull($beneficiary->invitation_sent_at);
        $this->assertNotNull($beneficiary->last_invitation_sent_at);
        $this->assertSame(1, $beneficiary->invitation_count);
    }

    #[Test]
    public function reenviar_invitacion_desde_servicio_funciona(): void
    {
        Mail::fake();

        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon();
        $beneficiary = $this->seedPendingBeneficiary($parent, 'reenvio@test.local');
        $beneficiary->update([
            'invitation_sent_at' => now()->subHour(),
            'last_invitation_sent_at' => now()->subHour(),
            'invitation_count' => 1,
        ]);

        $service->resendPendingInvitation($beneficiary->fresh(), actor: null);

        Mail::assertSent(CouponPendingBalanceInvitationMail::class, 1);
        $this->assertSame(2, $beneficiary->fresh()->invitation_count);
    }

    #[Test]
    public function reenviar_invitacion_respeta_cooldown(): void
    {
        Mail::fake();

        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon();
        $beneficiary = $this->seedPendingBeneficiary($parent, 'cooldown@test.local');
        $beneficiary->update([
            'invitation_sent_at' => now(),
            'last_invitation_sent_at' => now(),
            'invitation_count' => 1,
        ]);

        $this->expectException(\DomainException::class);
        $service->resendPendingInvitation($beneficiary->fresh(), actor: null);
    }

    #[Test]
    public function no_permite_reenviar_beneficiario_asignado(): void
    {
        Mail::fake();

        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon();
        $beneficiary = CouponBeneficiary::query()->create([
            'parent_coupon_id' => $parent->id,
            'email' => 'asignado@test.local',
            'email_normalized' => 'asignado@test.local',
            'status' => CouponBeneficiaryStatus::Assigned,
            'source' => 'manual',
        ]);

        $this->expectException(\DomainException::class);
        $service->resendPendingInvitation($beneficiary, actor: null);
    }

    #[Test]
    public function no_permite_reenviar_beneficiario_cancelled(): void
    {
        Mail::fake();

        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon();
        $beneficiary = CouponBeneficiary::query()->create([
            'parent_coupon_id' => $parent->id,
            'email' => 'cancel@test.local',
            'email_normalized' => 'cancel@test.local',
            'status' => CouponBeneficiaryStatus::Cancelled,
            'source' => 'manual',
            'cancelled_at' => now(),
        ]);

        $this->expectException(\DomainException::class);
        $service->sendPendingInvitation($beneficiary, actor: null);
    }

    #[Test]
    public function vincular_envia_email_activacion(): void
    {
        Mail::fake();

        $parent = $this->seedMasterCoupon();
        $user = $this->createVerifiedUser('activar@test.local');
        $this->seedPendingBeneficiary($parent, 'activar@test.local');

        app(LinkPendingCouponBeneficiaries::class)->handle(new Verified($user));

        Mail::assertSent(CouponBalanceActivatedMail::class, function (CouponBalanceActivatedMail $mail) use ($user) {
            return $mail->user->id === $user->id;
        });
    }

    #[Test]
    public function activacion_actualiza_activated_at_y_activation_notified_at(): void
    {
        Mail::fake();

        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon();
        $user = $this->createVerifiedUser('campos@test.local');
        $this->seedPendingBeneficiary($parent, 'campos@test.local');

        $service->linkPendingBeneficiariesForUser($user);

        $beneficiary = CouponBeneficiary::query()->first();
        $this->assertNotNull($beneficiary->activated_at);
        $this->assertNotNull($beneficiary->activation_notified_at);
    }

    #[Test]
    public function doble_vinculacion_no_duplica_email_activacion(): void
    {
        Mail::fake();

        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon();
        $user = $this->createVerifiedUser('idempotente@test.local');
        $this->seedPendingBeneficiary($parent, 'idempotente@test.local');

        $service->linkPendingBeneficiariesForUser($user);
        $service->linkPendingBeneficiariesForUser($user);

        Mail::assertSent(CouponBalanceActivatedMail::class, 1);
    }

    #[Test]
    public function fallo_notificacion_in_app_no_revierte_vinculacion(): void
    {
        Mail::fake();
        $this->mock(NotificationService::class)
            ->shouldReceive('createNotification')
            ->andThrow(new \RuntimeException('In-app down'));

        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon();
        $user = $this->createVerifiedUser('fallo@test.local');
        $this->seedPendingBeneficiary($parent, 'fallo@test.local');

        $service->linkPendingBeneficiariesForUser($user);

        $beneficiary = CouponBeneficiary::query()->first();
        $this->assertSame(CouponBeneficiaryStatus::Assigned, $beneficiary->status);
        $this->assertNotNull($beneficiary->child_coupon_id);
        $this->assertNull($beneficiary->activation_notified_at);
        Mail::assertSent(CouponBalanceActivatedMail::class, 1);
    }

    #[Test]
    public function invitacion_incluye_vigencia_y_compra_minima(): void
    {
        Mail::fake();

        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon([
            'valid_from' => now()->subDay(),
            'expires_at' => now()->addMonth(),
            'min_purchase_cents' => 50_000,
        ]);

        $beneficiary = $this->seedPendingBeneficiary($parent, 'detalle@test.local');
        $service->sendPendingInvitation($beneficiary, actor: null);

        Mail::assertSent(CouponPendingBalanceInvitationMail::class, function (CouponPendingBalanceInvitationMail $mail) {
            return $mail->parentCoupon->min_purchase_cents === 50_000
                && $mail->parentCoupon->expires_at !== null;
        });
    }

    #[Test]
    public function usuario_registrado_asignado_no_recibe_invitacion_pendiente(): void
    {
        Mail::fake();

        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon();
        $user = $this->createVerifiedUser('registrado@test.local');

        $service->confirmRows($parent, [
            ['email' => $user->email],
        ], sendNotifications: true);

        Mail::assertNotSent(CouponPendingBalanceInvitationMail::class);
        Mail::assertSent(\App\Mail\CouponAssignedMail::class, 1);
    }

    #[Test]
    public function comando_envia_invitaciones_pendientes_sin_enviar(): void
    {
        Mail::fake();

        $parent = $this->seedMasterCoupon();
        $this->seedPendingBeneficiary($parent, 'cmd@test.local');

        Artisan::call('coupons:send-pending-beneficiary-invitations');

        Mail::assertSent(CouponPendingBalanceInvitationMail::class, 1);
        $this->assertSame(1, CouponBeneficiary::query()->first()->invitation_count);
    }

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

    private function createVerifiedUser(string $email): User
    {
        return User::query()->create([
            'name' => 'Test User',
            'email' => $email,
            'password' => 'secret',
            'email_verified_at' => now(),
        ]);
    }

    private function seedPendingBeneficiary(Coupon $parent, string $email): CouponBeneficiary
    {
        return CouponBeneficiary::query()->create([
            'parent_coupon_id' => $parent->id,
            'email' => $email,
            'email_normalized' => CouponBeneficiary::normalizeEmail($email),
            'status' => CouponBeneficiaryStatus::PendingUser,
            'source' => 'manual',
        ]);
    }
}
