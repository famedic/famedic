<?php

namespace Tests\Feature\Payments;

use App\Actions\Laboratories\OrderAction;
use App\Actions\Payments\HeyBanco\ChargeHeyBancoTokenAction;
use App\Actions\Payments\HeyBanco\InitiateHeyBanco3dsLaboratoryCheckoutAction;
use App\Exceptions\HeyBanco3dsRedirectRequiredException;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\PaymentMethodResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class HeyBanco3dsBetaRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'heybanco.enabled' => true,
            'heybanco.3ds_enabled' => true,
            'heybanco.3ds_secure_api' => true,
            'heybanco.3ds_secret_key' => 'test-secret',
            'heybanco.3ds_url' => 'https://testcolecto.banregio.com/tds/vistas/recepcion3ds.zul',
            'heybanco.3ds_media_id' => 'TESTMEDIA1',
            'heybanco.3ds_affiliation' => '8379507',
            'payments.efevoopay_enabled' => false,
            'payments.default_provider' => 'hey_banco',
        ]);
    }

    public function test_efevoopay_disabled_hides_legacy_tokens_from_customer_payment_methods(): void
    {
        $user = User::factory()->withRegularCustomer()->create();

        PaymentMethod::create([
            'user_id' => $user->id,
            'provider' => 'hey_banco',
            'provider_token' => 'token-1',
            'brand' => 'visa',
            'last4' => '1096',
            'exp_month' => '12',
            'exp_year' => '2028',
            'status' => 'active',
            'alias' => 'visa-1096',
            'card_holder' => 'Titular',
        ]);

        $methods = $user->customer->paymentMethods();

        $this->assertCount(1, $methods);
        $this->assertSame('hey_banco:1', $methods->first()->id);
        $this->assertSame('hey_banco', $methods->first()->provider);
    }

    public function test_efevoopay_disabled_rejects_legacy_numeric_payment_method(): void
    {
        $user = User::factory()->withRegularCustomer()->create();

        $this->expectException(\App\Exceptions\HeyBancoPaymentException::class);

        PaymentMethodResolver::normalizeForCustomer($user->customer, '428');
    }

    public function test_numeric_payment_method_id_normalizes_to_hey_banco_public_id(): void
    {
        $user = User::factory()->withRegularCustomer()->create();

        $method = PaymentMethod::create([
            'user_id' => $user->id,
            'provider' => 'hey_banco',
            'provider_token' => 'token-abc',
            'brand' => 'visa',
            'last4' => '4242',
            'exp_month' => '12',
            'exp_year' => '2028',
            'status' => 'active',
            'alias' => 'visa-4242',
            'card_holder' => 'Titular',
        ]);

        $normalized = PaymentMethodResolver::normalizeForCustomer(
            $user->customer,
            (string) $method->id,
        );

        $this->assertSame('hey_banco:' . $method->id, $normalized);
        $this->assertSame('hey_banco', PaymentMethodResolver::detectProvider($normalized));
    }

    public function test_hey_banco_with_3ds_enabled_triggers_initiate_not_direct_charge(): void
    {
        $chargeMock = Mockery::mock(ChargeHeyBancoTokenAction::class);
        $chargeMock->shouldNotReceive('__invoke');
        $this->app->instance(ChargeHeyBancoTokenAction::class, $chargeMock);

        $initiateMock = Mockery::mock(InitiateHeyBanco3dsLaboratoryCheckoutAction::class);
        $initiateMock->shouldReceive('__invoke')
            ->once()
            ->andThrow(new HeyBanco3dsRedirectRequiredException(
                session: new \App\Models\Payment3dsSession(['id' => 99, 'folio' => 'FMTEST']),
                redirectUrl: 'https://testcolecto.banregio.com/challenge',
            ));
        $this->app->instance(InitiateHeyBanco3dsLaboratoryCheckoutAction::class, $initiateMock);

        $user = User::factory()->withRegularCustomer()->create();

        $method = PaymentMethod::create([
            'user_id' => $user->id,
            'provider' => 'hey_banco',
            'provider_token' => 'token-abc',
            'brand' => 'visa',
            'last4' => '4242',
            'exp_month' => '12',
            'exp_year' => '2028',
            'status' => 'active',
            'alias' => 'visa-4242',
            'card_holder' => 'Titular',
        ]);

        $orderAction = app(OrderAction::class);
        $reflection = new \ReflectionClass($orderAction);
        $methodRef = $reflection->getMethod('chargeAndCreateTransaction');
        $methodRef->setAccessible(true);

        $this->expectException(HeyBanco3dsRedirectRequiredException::class);

        $methodRef->invoke(
            $orderAction,
            15000,
            'hey_banco:' . $method->id,
            $user->customer,
            $user->customer->addresses()->create([
                'street' => 'Test',
                'exterior_number' => '1',
                'neighborhood' => 'Centro',
                'city' => 'Monterrey',
                'state' => 'Nuevo León',
                'zip_code' => '64000',
                'country' => 'MX',
            ]),
            null,
            \App\Enums\LaboratoryBrand::Azteca,
            15000,
            null,
            0,
            null,
        );
    }

    public function test_hey_banco_with_3ds_disabled_uses_direct_charge_path(): void
    {
        config(['heybanco.3ds_enabled' => false]);

        $initiateMock = Mockery::mock(InitiateHeyBanco3dsLaboratoryCheckoutAction::class);
        $initiateMock->shouldNotReceive('__invoke');
        $this->app->instance(InitiateHeyBanco3dsLaboratoryCheckoutAction::class, $initiateMock);

        $user = User::factory()->withRegularCustomer()->create();

        PaymentMethod::create([
            'user_id' => $user->id,
            'provider' => 'hey_banco',
            'provider_token' => 'token-abc',
            'brand' => 'visa',
            'last4' => '4242',
            'exp_month' => '12',
            'exp_year' => '2028',
            'status' => 'active',
            'alias' => 'visa-4242',
            'card_holder' => 'Titular',
        ]);

        $orderAction = app(OrderAction::class);
        $reflection = new \ReflectionClass($orderAction);
        $shouldUse = $reflection->getMethod('shouldUseHeyBanco3ds');
        $shouldUse->setAccessible(true);

        $result = $shouldUse->invoke($orderAction, 'hey_banco:1', 15000, 'hey_banco:1');

        $this->assertFalse($result);
    }
}
