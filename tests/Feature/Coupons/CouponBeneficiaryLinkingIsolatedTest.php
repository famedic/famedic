<?php

namespace Tests\Feature\Coupons;

/**
 * TEMPORAL / AISLADO — Vinculación automática de beneficiarios pendientes (Fase B2a).
 *
 * @see docs/modulo-saldo-a-favor-creditos-cupones.md
 */
use App\Enums\CouponApprovalStatus;
use App\Enums\CouponBeneficiaryStatus;
use App\Enums\CouponType;
use App\Listeners\LinkPendingCouponBeneficiaries;
use App\Models\Coupon;
use App\Models\CouponBeneficiary;
use App\Models\CouponUser;
use App\Models\User;
use App\Services\CouponBeneficiaryService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

require_once __DIR__.'/couponIsolatedSchema.php';

class CouponBeneficiaryLinkingIsolatedTest extends TestCase
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
    public function usuario_verificado_vincula_beneficiario_pendiente(): void
    {
        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon();
        $user = $this->createVerifiedUser('vincular@test.local');

        $beneficiary = $this->seedPendingBeneficiary($parent, 'vincular@test.local');

        $result = $service->linkPendingBeneficiariesForUser($user);

        $this->assertSame(1, $result['linked_count']);
        $this->assertSame(0, $result['skipped_count']);

        $beneficiary->refresh();
        $this->assertSame(CouponBeneficiaryStatus::Assigned, $beneficiary->status);
        $this->assertSame($user->id, $beneficiary->user_id);
        $this->assertNotNull($beneficiary->child_coupon_id);
        $this->assertNotNull($beneficiary->assigned_at);
        $this->assertNotNull($beneficiary->claimed_at);

        $this->assertTrue(
            CouponUser::query()
                ->where('user_id', $user->id)
                ->where('coupon_id', $beneficiary->child_coupon_id)
                ->exists()
        );

        $child = Coupon::query()->find($beneficiary->child_coupon_id);
        $this->assertNotNull($child);
        $this->assertSame($parent->id, $child->parent_coupon_id);
    }

    #[Test]
    public function usuario_no_verificado_no_vincula(): void
    {
        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon();
        $user = $this->createUser('no-verificado@test.local');
        $this->seedPendingBeneficiary($parent, 'no-verificado@test.local');

        $result = $service->linkPendingBeneficiariesForUser($user);

        $this->assertSame(0, $result['linked_count']);
        $this->assertSame(0, CouponUser::query()->count());
    }

    #[Test]
    public function listener_verified_vincula_beneficiario(): void
    {
        $parent = $this->seedMasterCoupon();
        $user = $this->createVerifiedUser('listener@test.local');
        $this->seedPendingBeneficiary($parent, 'listener@test.local');

        app(LinkPendingCouponBeneficiaries::class)->handle(new Verified($user));

        $beneficiary = CouponBeneficiary::query()->first();
        $this->assertSame(CouponBeneficiaryStatus::Assigned, $beneficiary->status);
        $this->assertNotNull($beneficiary->child_coupon_id);
    }

    #[Test]
    public function doble_ejecucion_es_idempotente(): void
    {
        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon();
        $user = $this->createVerifiedUser('idempotente@test.local');
        $this->seedPendingBeneficiary($parent, 'idempotente@test.local');

        $service->linkPendingBeneficiariesForUser($user);
        $service->linkPendingBeneficiariesForUser($user);

        $this->assertSame(1, CouponUser::query()->count());
        $this->assertSame(1, Coupon::query()->whereNotNull('parent_coupon_id')->count());
    }

    #[Test]
    public function no_vincula_beneficiario_cancelled(): void
    {
        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon();
        $user = $this->createVerifiedUser('cancelled@test.local');

        CouponBeneficiary::query()->create([
            'parent_coupon_id' => $parent->id,
            'email' => 'cancelled@test.local',
            'email_normalized' => 'cancelled@test.local',
            'status' => CouponBeneficiaryStatus::Cancelled,
            'source' => 'manual',
            'cancelled_at' => now(),
        ]);

        $result = $service->linkPendingBeneficiariesForUser($user);

        $this->assertSame(0, $result['linked_count']);
        $this->assertSame(0, CouponUser::query()->count());
    }

    #[Test]
    public function no_vincula_si_email_no_coincide_normalizado(): void
    {
        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon();
        $user = $this->createVerifiedUser('uno@test.local');
        $beneficiary = $this->seedPendingBeneficiary($parent, 'dos@test.local');

        $child = $service->linkPendingBeneficiary($beneficiary, $user);

        $this->assertNull($child);
        $this->assertSame(CouponBeneficiaryStatus::PendingUser, $beneficiary->fresh()->status);
    }

    #[Test]
    public function no_vincula_si_cupon_maestro_inactivo(): void
    {
        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon(['is_active' => false]);
        $user = $this->createVerifiedUser('inactivo@test.local');
        $this->seedPendingBeneficiary($parent, 'inactivo@test.local');

        $result = $service->linkPendingBeneficiariesForUser($user);

        $this->assertSame(0, $result['linked_count']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertSame(0, CouponUser::query()->count());
    }

    #[Test]
    public function no_vincula_si_cupon_maestro_no_aprobado(): void
    {
        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon(['approval_status' => CouponApprovalStatus::PendingAuthorization]);
        $user = $this->createVerifiedUser('no-aprobado@test.local');
        $this->seedPendingBeneficiary($parent, 'no-aprobado@test.local');

        $result = $service->linkPendingBeneficiariesForUser($user);

        $this->assertSame(0, $result['linked_count']);
        $this->assertSame(1, $result['skipped_count']);
    }

    #[Test]
    public function vincula_multiples_campanas_mismo_email(): void
    {
        $service = app(CouponBeneficiaryService::class);
        $parentA = $this->seedMasterCoupon(['amount_cents' => 50_000]);
        $parentB = $this->seedMasterCoupon(['amount_cents' => 75_000]);
        $user = $this->createVerifiedUser('multi@test.local');

        $this->seedPendingBeneficiary($parentA, 'multi@test.local');
        $this->seedPendingBeneficiary($parentB, 'multi@test.local');

        $result = $service->linkPendingBeneficiariesForUser($user);

        $this->assertSame(2, $result['linked_count']);
        $this->assertSame(2, CouponUser::query()->count());
        $this->assertSame(2, CouponBeneficiary::query()->where('status', CouponBeneficiaryStatus::Assigned)->count());
    }

    #[Test]
    public function hereda_vigencia_y_compra_minima(): void
    {
        $service = app(CouponBeneficiaryService::class);
        $parent = $this->seedMasterCoupon([
            'valid_from' => now()->subDay(),
            'expires_at' => now()->addMonth(),
            'min_purchase_cents' => 50_000,
        ]);
        $user = $this->createVerifiedUser('hereda@test.local');
        $this->seedPendingBeneficiary($parent, 'hereda@test.local');

        $service->linkPendingBeneficiariesForUser($user);

        $child = Coupon::query()->where('parent_coupon_id', $parent->id)->first();
        $this->assertNotNull($child);
        $this->assertSame(50_000, $child->min_purchase_cents);
        $this->assertNotNull($child->valid_from);
        $this->assertNotNull($child->expires_at);
    }

    #[Test]
    public function comando_dry_run_no_modifica_datos(): void
    {
        $parent = $this->seedMasterCoupon();
        $this->createVerifiedUser('dryrun@test.local');
        $this->seedPendingBeneficiary($parent, 'dryrun@test.local');

        Artisan::call('coupons:link-pending-beneficiaries', ['--dry-run' => true]);

        $this->assertSame(CouponBeneficiaryStatus::PendingUser, CouponBeneficiary::query()->first()->status);
        $this->assertSame(0, CouponUser::query()->count());
        $this->assertSame(0, Coupon::query()->whereNotNull('parent_coupon_id')->count());
    }

    #[Test]
    public function comando_sin_dry_run_vincula_pendientes(): void
    {
        $parent = $this->seedMasterCoupon();
        $this->createVerifiedUser('cmd@test.local');
        $this->seedPendingBeneficiary($parent, 'cmd@test.local');

        Artisan::call('coupons:link-pending-beneficiaries');

        $this->assertSame(CouponBeneficiaryStatus::Assigned, CouponBeneficiary::query()->first()->status);
        $this->assertSame(1, CouponUser::query()->count());
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

    private function createUser(string $email): User
    {
        return User::query()->create([
            'name' => 'Test User',
            'email' => $email,
            'password' => 'secret',
            'email_verified_at' => null,
        ]);
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
