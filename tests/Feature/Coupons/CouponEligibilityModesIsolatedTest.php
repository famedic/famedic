<?php

namespace Tests\Feature\Coupons;

/**
 * TEMPORAL / AISLADO — Modos explícitos de vigencia y compra mínima en admin.
 *
 * @see docs/modulo-saldo-a-favor-creditos-cupones.md
 */
use App\Services\CouponEligibilityFormService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

require_once __DIR__.'/couponIsolatedSchema.php';

class CouponEligibilityModesIsolatedTest extends TestCase
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
    public function validity_mode_open_guarda_fechas_null(): void
    {
        $service = app(CouponEligibilityFormService::class);

        $attrs = $service->resolveAttributes([
            'validity_mode' => 'open',
            'minimum_purchase_mode' => 'none',
            'valid_from' => '2026-07-01T10:00',
            'expires_at' => '2026-12-31T23:59',
            'min_purchase_cents' => null,
        ]);

        $this->assertNull($attrs['valid_from']);
        $this->assertNull($attrs['expires_at']);
        $this->assertNull($attrs['min_purchase_cents']);
    }

    #[Test]
    public function validity_mode_configured_sin_fechas_falla(): void
    {
        $service = app(CouponEligibilityFormService::class);

        $this->expectException(ValidationException::class);

        $service->resolveAttributes([
            'validity_mode' => 'configured',
            'minimum_purchase_mode' => 'none',
            'valid_from' => null,
            'expires_at' => null,
            'min_purchase_cents' => null,
        ]);
    }

    #[Test]
    public function validity_mode_configured_solo_expires_at_guarda_correctamente(): void
    {
        $service = app(CouponEligibilityFormService::class);

        $attrs = $service->resolveAttributes([
            'validity_mode' => 'configured',
            'minimum_purchase_mode' => 'none',
            'valid_from' => null,
            'expires_at' => '2026-12-31 23:59:00',
            'min_purchase_cents' => null,
        ]);

        $this->assertNull($attrs['valid_from']);
        $this->assertNotNull($attrs['expires_at']);
    }

    #[Test]
    public function validity_mode_configured_con_expires_menor_a_valid_from_falla(): void
    {
        $service = app(CouponEligibilityFormService::class);

        $this->expectException(ValidationException::class);

        $service->resolveAttributes([
            'validity_mode' => 'configured',
            'minimum_purchase_mode' => 'none',
            'valid_from' => '2026-12-31 23:59:00',
            'expires_at' => '2026-01-01 00:00:00',
            'min_purchase_cents' => null,
        ]);
    }

    #[Test]
    public function minimum_purchase_mode_none_guarda_null(): void
    {
        $service = app(CouponEligibilityFormService::class);

        $attrs = $service->resolveAttributes([
            'validity_mode' => 'open',
            'minimum_purchase_mode' => 'none',
            'min_purchase_cents' => 50_000,
        ]);

        $this->assertNull($attrs['min_purchase_cents']);
    }

    #[Test]
    public function minimum_purchase_mode_required_sin_monto_falla(): void
    {
        $service = app(CouponEligibilityFormService::class);

        $this->expectException(ValidationException::class);

        $service->resolveAttributes([
            'validity_mode' => 'open',
            'minimum_purchase_mode' => 'required',
            'min_purchase_cents' => null,
        ]);
    }

    #[Test]
    public function minimum_purchase_mode_required_con_monto_guarda_centavos(): void
    {
        $service = app(CouponEligibilityFormService::class);

        $attrs = $service->resolveAttributes([
            'validity_mode' => 'open',
            'minimum_purchase_mode' => 'required',
            'min_purchase_cents' => 75_000,
        ]);

        $this->assertSame(75_000, $attrs['min_purchase_cents']);
    }
}
