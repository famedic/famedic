<?php

namespace Tests\Feature\Coupons;

/**
 * TEMPORAL / AISLADO — Conceptos personalizados al crear créditos.
 *
 * @see docs/modulo-saldo-a-favor-creditos-cupones.md
 */
use App\Enums\CouponApprovalStatus;
use App\Enums\CouponType;
use App\Models\Coupon;
use App\Models\CouponConcept;
use App\Services\CouponConceptService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

require_once __DIR__.'/couponIsolatedSchema.php';

class CouponConceptIsolatedTest extends TestCase
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
    public function concepto_existente_mantiene_coupon_concept_id(): void
    {
        $service = app(CouponConceptService::class);
        $concept = CouponConcept::query()->create([
            'title' => 'Bono anual',
            'description' => null,
        ]);

        $payload = $service->resolveConceptPayload([
            'coupon_concept_id' => $concept->id,
            'concept_is_other' => false,
        ]);

        $this->assertSame($concept->id, $payload['coupon_concept_id']);
        $this->assertNull($payload['concept_other']);
    }

    #[Test]
    public function otro_crea_nuevo_coupon_concept(): void
    {
        $service = app(CouponConceptService::class);

        $payload = $service->resolveConceptPayload([
            'concept_is_other' => true,
            'concept_other' => 'Compensación por cancelación',
        ]);

        $this->assertNotNull($payload['coupon_concept_id']);
        $this->assertSame('Compensación por cancelación', $payload['concept_other']);

        $concept = CouponConcept::query()->find($payload['coupon_concept_id']);
        $this->assertNotNull($concept);
        $this->assertSame('Compensación por cancelación', $concept->title);
    }

    #[Test]
    public function otro_reutiliza_concepto_existente_case_insensitive(): void
    {
        $service = app(CouponConceptService::class);
        $existing = CouponConcept::query()->create([
            'title' => 'Compensación por cancelación',
            'description' => null,
        ]);

        $payload = $service->resolveConceptPayload([
            'concept_is_other' => true,
            'concept_other' => 'compensación por cancelación',
        ]);

        $this->assertSame($existing->id, $payload['coupon_concept_id']);
        $this->assertSame(1, CouponConcept::query()->count());
    }

    #[Test]
    public function otro_vacio_falla_validacion(): void
    {
        $service = app(CouponConceptService::class);

        $this->expectException(ValidationException::class);

        $service->resolveConceptPayload([
            'concept_is_other' => true,
            'concept_other' => '   ',
        ]);
    }

    #[Test]
    public function concepto_creado_queda_disponible_en_lista(): void
    {
        $service = app(CouponConceptService::class);

        $payload = $service->resolveConceptPayload([
            'concept_is_other' => true,
            'concept_other' => 'Incentivo temporal',
        ]);

        $titles = CouponConcept::query()->orderBy('title')->pluck('title')->all();
        $this->assertContains('Incentivo temporal', $titles);

        $coupon = Coupon::query()->create([
            'amount_cents' => 100_000,
            'remaining_cents' => 100_000,
            'type' => CouponType::Balance,
            'is_active' => true,
            'approval_status' => CouponApprovalStatus::Active,
            'coupon_concept_id' => $payload['coupon_concept_id'],
            'concept_other' => $payload['concept_other'],
        ]);

        $this->assertSame($payload['coupon_concept_id'], $coupon->coupon_concept_id);
    }
}
