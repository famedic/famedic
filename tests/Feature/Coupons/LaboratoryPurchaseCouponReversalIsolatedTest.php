<?php

namespace Tests\Feature\Coupons;

/**
 * TEMPORAL / AISLADO — Validación rápida del reverso de saldo a favor sin migraciones históricas.
 *
 * Clase PHPUnit (no Pest `test()`): evita RefreshDatabase del directorio Feature.
 * Crea schema mínimo manualmente. No reemplaza tests de integración completos.
 *
 * @see docs/modulo-saldo-a-favor-creditos-cupones.md
 */
use App\Actions\Coupons\ReverseCouponBalanceForLaboratoryPurchaseAction;
use App\Enums\CouponPurchaseType;
use App\Exceptions\CouponReversalException;
use App\Models\Coupon;
use App\Models\CouponAuditLog;
use App\Models\CouponTransaction;
use App\Models\CouponUser;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use App\Services\CouponApplicationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryPurchaseCouponReversalIsolatedTest extends TestCase
{
    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        $this->bootstrapIsolatedCouponReversalSchema();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedCouponReversalSchema();

        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function reverso_exitoso_restaura_saldo_marca_transaccion_y_audita(): void
    {
        $service = app(CouponApplicationService::class);
        ['actor' => $actor, 'purchase' => $purchase, 'coupon' => $coupon, 'assignment' => $assignment, 'couponTransaction' => $couponTransaction] =
            $this->seedConsumedCouponLabPurchaseIsolated(100_000, 40_000);

        $restored = $service->reverseForLaboratoryPurchase($purchase, $actor);

        $this->assertSame(40_000, $restored);

        $coupon->refresh();
        $assignment->refresh();
        $couponTransaction->refresh();

        $this->assertSame(40_000, $coupon->remaining_cents);
        $this->assertNull($assignment->used_at);
        $this->assertNotNull($couponTransaction->reversed_at);
        $this->assertSame('laboratory_purchase_cancelled', $couponTransaction->reversal_reason);
        $this->assertSame($actor->id, $couponTransaction->reversed_by_user_id);

        $log = CouponAuditLog::query()->where('action', 'reverse_coupon_application')->first();

        $this->assertNotNull($log);
        $this->assertSame('application', $log->type);
        $this->assertSame('completed', $log->status);
        $this->assertSame($actor->id, $log->actor_user_id);
        $this->assertSame(40_000, $log->context['amount_restored_cents']);
        $this->assertSame($purchase->id, $log->context['purchase_id']);
    }

    #[Test]
    public function action_orquestadora_delega_y_retorna_monto_restaurado(): void
    {
        ['actor' => $actor, 'purchase' => $purchase] = $this->seedConsumedCouponLabPurchaseIsolated(90_000, 35_000);

        $restored = app(ReverseCouponBalanceForLaboratoryPurchaseAction::class)($purchase, $actor);

        $this->assertSame(35_000, $restored);
    }

    #[Test]
    public function reverso_es_idempotente_y_no_duplica_saldo(): void
    {
        $service = app(CouponApplicationService::class);
        ['purchase' => $purchase, 'coupon' => $coupon] = $this->seedConsumedCouponLabPurchaseIsolated(80_000, 30_000);

        $this->assertSame(30_000, $service->reverseForLaboratoryPurchase($purchase));
        $this->assertSame(0, $service->reverseForLaboratoryPurchase($purchase->fresh()));

        $coupon->refresh();

        $this->assertSame(30_000, $coupon->remaining_cents);
        $this->assertSame(1, CouponTransaction::query()->where('purchase_id', $purchase->id)->count());
        $this->assertSame(1, CouponAuditLog::query()->where('action', 'reverse_coupon_application')->count());
    }

    #[Test]
    public function pedido_sin_cupon_retorna_cero_sin_error(): void
    {
        $service = app(CouponApplicationService::class);

        $user = User::query()->create([
            'name' => 'Sin Cupon',
            'email' => 'sin-cupon-'.uniqid().'@test.local',
            'password' => 'secret',
        ]);

        $customer = Customer::query()->create(['user_id' => $user->id]);

        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $customer->id,
            'total_cents' => 50_000,
            'coupon_discount_cents' => 0,
        ]);

        $this->assertSame(0, $service->reverseForLaboratoryPurchase($purchase));
        $this->assertSame(0, CouponAuditLog::query()->count());
    }

    #[Test]
    public function inconsistencia_coupon_discount_sin_transaccion_lanza_excepcion(): void
    {
        $this->expectException(CouponReversalException::class);

        $service = app(CouponApplicationService::class);

        $user = User::query()->create([
            'name' => 'Inconsistente',
            'email' => 'inconsistente-'.uniqid().'@test.local',
            'password' => 'secret',
        ]);

        $customer = Customer::query()->create(['user_id' => $user->id]);

        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $customer->id,
            'total_cents' => 60_000,
            'coupon_discount_cents' => 15_000,
        ]);

        $service->reverseForLaboratoryPurchase($purchase);
    }

    #[Test]
    public function transaccion_de_otro_usuario_lanza_excepcion(): void
    {
        $this->expectException(CouponReversalException::class);
        $this->expectExceptionMessage('No se encontró la asignación del cupón al usuario.');

        $service = app(CouponApplicationService::class);

        $otherUser = User::query()->create([
            'name' => 'Otro Usuario',
            'email' => 'otro-'.uniqid().'@test.local',
            'password' => 'secret',
        ]);

        ['purchase' => $purchase] = $this->seedConsumedCouponLabPurchaseIsolated(70_000, 25_000, $otherUser->id);

        $service->reverseForLaboratoryPurchase($purchase);
    }

    #[Test]
    public function pedido_de_otro_cliente_lanza_excepcion(): void
    {
        $this->expectException(CouponReversalException::class);
        $this->expectExceptionMessage('La asignación del cupón no corresponde al usuario del pedido.');

        $service = app(CouponApplicationService::class);
        ['purchase' => $purchase, 'coupon' => $coupon, 'couponTransaction' => $couponTransaction] =
            $this->seedConsumedCouponLabPurchaseIsolated(70_000, 25_000);

        $otherCustomerUser = User::query()->create([
            'name' => 'Cliente Ajeno',
            'email' => 'ajeno-'.uniqid().'@test.local',
            'password' => 'secret',
        ]);

        $otherCustomer = Customer::query()->create(['user_id' => $otherCustomerUser->id]);

        $purchase->update(['customer_id' => $otherCustomer->id]);

        $service->reverseForLaboratoryPurchase($purchase->fresh());
    }

    #[Test]
    public function cupon_no_marcado_como_usado_lanza_excepcion(): void
    {
        $this->expectException(CouponReversalException::class);
        $this->expectExceptionMessage('El cupón no está marcado como utilizado para este pedido.');

        $service = app(CouponApplicationService::class);
        ['purchase' => $purchase, 'assignment' => $assignment] = $this->seedConsumedCouponLabPurchaseIsolated();

        $assignment->update(['used_at' => null]);

        $service->reverseForLaboratoryPurchase($purchase);
    }

    private function bootstrapIsolatedCouponReversalSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('coupon_audit_logs');
        Schema::dropIfExists('coupon_transactions');
        Schema::dropIfExists('coupon_user');
        Schema::dropIfExists('laboratory_purchases');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('stripe_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('remaining_cents')->default(0);
            $table->string('type')->default('balance');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('coupon_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('used_at')->nullable();
        });

        Schema::create('coupon_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('purchase_type');
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedInteger('amount_used_cents');
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by_user_id')->nullable()->constrained('users');
            $table->string('reversal_reason')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('coupon_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->string('action')->nullable();
            $table->string('status')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users');
            $table->foreignId('coupon_id')->nullable()->constrained();
            $table->json('context')->nullable();
            $table->timestamps();
        });

        Schema::create('laboratory_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->unsignedInteger('total_cents')->default(0);
            $table->unsignedInteger('coupon_discount_cents')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::enableForeignKeyConstraints();
    }

    private function tearDownIsolatedCouponReversalSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('coupon_audit_logs');
        Schema::dropIfExists('coupon_transactions');
        Schema::dropIfExists('coupon_user');
        Schema::dropIfExists('laboratory_purchases');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('users');

        Schema::enableForeignKeyConstraints();
    }

    /**
     * @return array{actor: User, customer: Customer, purchase: LaboratoryPurchase, coupon: Coupon, assignment: CouponUser, couponTransaction: CouponTransaction}
     */
    private function seedConsumedCouponLabPurchaseIsolated(
        int $totalCents = 100_000,
        int $discountCents = 40_000,
        ?int $transactionUserId = null,
    ): array {
        $actor = User::query()->create([
            'name' => 'Admin Actor',
            'email' => 'actor-'.uniqid().'@test.local',
            'password' => 'secret',
        ]);

        $customerUser = User::query()->create([
            'name' => 'Cliente Test',
            'email' => 'customer-'.uniqid().'@test.local',
            'password' => 'secret',
        ]);

        $customer = Customer::query()->create([
            'user_id' => $customerUser->id,
        ]);

        $coupon = Coupon::query()->create([
            'amount_cents' => $discountCents,
            'remaining_cents' => 0,
            'type' => 'balance',
            'is_active' => true,
        ]);

        $assignment = CouponUser::query()->create([
            'coupon_id' => $coupon->id,
            'user_id' => $customerUser->id,
            'assigned_at' => now(),
            'used_at' => now(),
        ]);

        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $customer->id,
            'total_cents' => $totalCents,
            'coupon_discount_cents' => $discountCents,
        ]);

        $txUserId = $transactionUserId ?? $customerUser->id;

        $couponTransaction = CouponTransaction::query()->create([
            'coupon_id' => $coupon->id,
            'user_id' => $txUserId,
            'purchase_type' => CouponPurchaseType::Lab,
            'purchase_id' => $purchase->id,
            'amount_used_cents' => $discountCents,
        ]);

        return compact('actor', 'customer', 'purchase', 'coupon', 'assignment', 'couponTransaction');
    }
}
