<?php

namespace Tests\Feature\Coupons;

/**
 * TEMPORAL / AISLADO — Reporte admin de beneficiarios agrupados por email.
 *
 * @see docs/modulo-saldo-a-favor-creditos-cupones.md
 */
use App\Enums\CouponApprovalStatus;
use App\Enums\CouponBeneficiarySource;
use App\Enums\CouponBeneficiaryStatus;
use App\Enums\CouponPurchaseType;
use App\Enums\CouponType;
use App\Models\Coupon;
use App\Models\CouponBeneficiary;
use App\Models\CouponTransaction;
use App\Models\CouponUser;
use App\Models\User;
use App\Services\CouponBeneficiaryReportService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

require_once __DIR__.'/couponIsolatedSchema.php';

class CouponBeneficiaryReportIsolatedTest extends TestCase
{
    private CouponBeneficiaryReportService $service;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();
        bootstrapIsolatedCouponModuleSchema();
        bootstrapIsolatedCouponBeneficiaryReportSchema();
        $this->service = app(CouponBeneficiaryReportService::class);
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
    public function usuario_con_dos_creditos_aparece_una_sola_vez(): void
    {
        $user = $this->createUser('doble@test.local');
        $this->assignChildCoupon($user, 10_000);
        $this->assignChildCoupon($user, 20_000);

        $report = $this->service->paginate([]);
        $rows = collect($report['rows']->items());

        $this->assertCount(1, $rows);
        $this->assertSame('doble@test.local', $rows->first()['email']);
    }

    #[Test]
    public function assigned_coupons_count_cuenta_correctamente(): void
    {
        $user = $this->createUser('count@test.local');
        $this->assignChildCoupon($user, 10_000);
        $this->assignChildCoupon($user, 15_000);

        $row = $this->firstRowForEmail('count@test.local');

        $this->assertSame(2, $row['assigned_coupons_count']);
    }

    #[Test]
    public function available_balance_suma_solo_cupones_vigentes_no_usados(): void
    {
        $user = $this->createUser('balance@test.local');
        $active = $this->assignChildCoupon($user, 50_000);
        $expired = $this->assignChildCoupon($user, 30_000, [
            'expires_at' => now()->subDay(),
        ]);
        $used = $this->assignChildCoupon($user, 20_000);
        CouponUser::query()
            ->where('coupon_id', $used->id)
            ->update(['used_at' => now()]);

        $row = $this->firstRowForEmail('balance@test.local');

        $this->assertSame(50_000, $row['available_balance_cents']);
        $this->assertSame(50_000, $active->fresh()->remaining_cents);
        $this->assertNotSame(0, $expired->fresh()->remaining_cents);
    }

    #[Test]
    public function used_balance_suma_transacciones_no_revertidas(): void
    {
        $user = $this->createUser('used@test.local');
        $coupon = $this->assignChildCoupon($user, 40_000);

        CouponTransaction::query()->create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'purchase_type' => CouponPurchaseType::Lab,
            'purchase_id' => 1,
            'amount_used_cents' => 15_000,
            'created_at' => now(),
        ]);
        CouponTransaction::query()->create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'purchase_type' => CouponPurchaseType::Lab,
            'purchase_id' => 2,
            'amount_used_cents' => 5_000,
            'reversed_at' => now(),
            'created_at' => now(),
        ]);

        $row = $this->firstRowForEmail('used@test.local');

        $this->assertSame(15_000, $row['used_balance_cents']);
    }

    #[Test]
    public function reversed_balance_suma_transacciones_revertidas(): void
    {
        $user = $this->createUser('reversed@test.local');
        $coupon = $this->assignChildCoupon($user, 40_000);

        CouponTransaction::query()->create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'purchase_type' => CouponPurchaseType::Lab,
            'purchase_id' => 3,
            'amount_used_cents' => 8_000,
            'reversed_at' => now(),
            'created_at' => now(),
        ]);

        $row = $this->firstRowForEmail('reversed@test.local');

        $this->assertSame(8_000, $row['reversed_balance_cents']);
    }

    #[Test]
    public function beneficiario_pendiente_sin_usuario_aparece_como_pendiente(): void
    {
        $parent = $this->seedMasterCoupon();
        CouponBeneficiary::query()->create([
            'parent_coupon_id' => $parent->id,
            'email' => 'pendiente@test.local',
            'email_normalized' => 'pendiente@test.local',
            'first_name' => 'Pendiente',
            'status' => CouponBeneficiaryStatus::PendingUser,
            'source' => CouponBeneficiarySource::Manual,
            'assigned_at' => now(),
        ]);

        $row = $this->firstRowForEmail('pendiente@test.local');

        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['user_id']);
        $this->assertSame(1, $row['pending_beneficiaries_count']);
        $this->assertSame(0, $row['available_balance_cents']);
    }

    #[Test]
    public function mismo_email_pendiente_en_dos_campanas_se_agrupa(): void
    {
        $parentA = $this->seedMasterCoupon();
        $parentB = $this->seedMasterCoupon(['code' => 'MASTER-B']);

        foreach ([$parentA, $parentB] as $parent) {
            CouponBeneficiary::query()->create([
                'parent_coupon_id' => $parent->id,
                'email' => 'agrupado@test.local',
                'email_normalized' => 'agrupado@test.local',
                'status' => CouponBeneficiaryStatus::PendingUser,
                'source' => CouponBeneficiarySource::Manual,
                'assigned_at' => now(),
            ]);
        }

        $report = $this->service->paginate([]);
        $matches = collect($report['rows']->items())
            ->filter(fn ($row) => $row['email'] === 'agrupado@test.local');

        $this->assertCount(1, $matches);
        $this->assertSame(2, $matches->first()['pending_beneficiaries_count']);
    }

    #[Test]
    public function pendiente_vinculado_no_aparece_como_fila_pendiente_duplicada(): void
    {
        $user = $this->createUser('vinculado@test.local');
        $parent = $this->seedMasterCoupon();
        $child = $this->assignChildCoupon($user, 25_000, ['parent_coupon_id' => $parent->id]);

        CouponBeneficiary::query()->create([
            'parent_coupon_id' => $parent->id,
            'child_coupon_id' => $child->id,
            'user_id' => $user->id,
            'email' => $user->email,
            'email_normalized' => strtolower($user->email),
            'status' => CouponBeneficiaryStatus::Assigned,
            'source' => CouponBeneficiarySource::Manual,
            'assigned_at' => now(),
        ]);

        $report = $this->service->paginate([]);
        $pendingRows = collect($report['rows']->items())
            ->filter(fn ($row) => $row['status'] === 'pending' && $row['email'] === $user->email);

        $this->assertCount(0, $pendingRows);

        $registered = $this->firstRowForEmail($user->email);
        $this->assertSame('registered', $registered['status']);
        $this->assertSame(1, $registered['assigned_coupons_count']);
    }

    #[Test]
    public function filtro_busqueda_por_email_funciona(): void
    {
        $this->createUser('alfa@test.local');
        $this->assignChildCoupon(User::query()->where('email', 'alfa@test.local')->first(), 10_000);
        $userB = $this->createUser('beta@test.local');
        $this->assignChildCoupon($userB, 10_000);

        $report = $this->service->paginate(['search' => 'alfa']);
        $emails = collect($report['rows']->items())->pluck('email');

        $this->assertTrue($emails->contains('alfa@test.local'));
        $this->assertFalse($emails->contains('beta@test.local'));
    }

    #[Test]
    public function filtro_estado_registrado_y_pendiente_funciona(): void
    {
        $user = $this->createUser('reg@test.local');
        $this->assignChildCoupon($user, 10_000);

        $parent = $this->seedMasterCoupon();
        CouponBeneficiary::query()->create([
            'parent_coupon_id' => $parent->id,
            'email' => 'solo-pendiente@test.local',
            'email_normalized' => 'solo-pendiente@test.local',
            'status' => CouponBeneficiaryStatus::PendingUser,
            'source' => CouponBeneficiarySource::Manual,
        ]);

        $registered = collect($this->service->paginate(['status' => 'registered'])['rows']->items())
            ->pluck('email');
        $pending = collect($this->service->paginate(['status' => 'pending'])['rows']->items())
            ->pluck('email');

        $this->assertTrue($registered->contains('reg@test.local'));
        $this->assertFalse($registered->contains('solo-pendiente@test.local'));
        $this->assertTrue($pending->contains('solo-pendiente@test.local'));
        $this->assertFalse($pending->contains('reg@test.local'));
    }

    #[Test]
    public function filtro_con_saldo_disponible_funciona(): void
    {
        $withBalance = $this->createUser('con-saldo@test.local');
        $this->assignChildCoupon($withBalance, 12_000);

        $withoutBalance = $this->createUser('sin-saldo@test.local');
        $usedCoupon = $this->assignChildCoupon($withoutBalance, 12_000);
        CouponUser::query()
            ->where('coupon_id', $usedCoupon->id)
            ->update(['used_at' => now()]);

        $emails = collect($this->service->paginate(['balance' => 'has_available'])['rows']->items())
            ->pluck('email');

        $this->assertTrue($emails->contains('con-saldo@test.local'));
        $this->assertFalse($emails->contains('sin-saldo@test.local'));
    }

    #[Test]
    public function consulta_requiere_permiso_cupones_view(): void
    {
        $user = $this->createUser('sin-permiso@test.local');

        $this->assertFalse($user->can('viewAny', Coupon::class));
    }

    private function firstRowForEmail(string $email): array
    {
        $report = $this->service->paginate([]);
        $row = collect($report['rows']->items())->firstWhere('email', $email);

        $this->assertNotNull($row, "No se encontró fila para {$email}");

        return $row;
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'name' => 'Test',
            'email' => $email,
            'password' => bcrypt('secret'),
        ]);
    }

    private function seedMasterCoupon(array $overrides = []): Coupon
    {
        return Coupon::query()->create(array_merge([
            'code' => 'MASTER-'.uniqid(),
            'amount_cents' => 50_000,
            'remaining_cents' => 0,
            'type' => CouponType::Balance,
            'approval_status' => CouponApprovalStatus::Active,
            'is_active' => true,
            'max_beneficiaries' => 10,
        ], $overrides));
    }

    private function assignChildCoupon(User $user, int $amountCents, array $overrides = []): Coupon
    {
        $coupon = Coupon::query()->create(array_merge([
            'parent_coupon_id' => $overrides['parent_coupon_id'] ?? $this->seedMasterCoupon()->id,
            'code' => 'CHILD-'.uniqid(),
            'amount_cents' => $amountCents,
            'remaining_cents' => $amountCents,
            'type' => CouponType::Balance,
            'approval_status' => CouponApprovalStatus::Active,
            'is_active' => true,
        ], $overrides));

        CouponUser::query()->create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'assigned_at' => now(),
        ]);

        return $coupon;
    }
}
