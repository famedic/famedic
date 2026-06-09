<?php

namespace Tests\Feature\Payments;

use App\Actions\Payments\HeyBanco\ChargeHeyBancoTokenAction;
use App\Actions\Payments\HeyBanco\CreateHeyBanco3dsTokenChargeSessionAction;
use App\Actions\Payments\HeyBanco\FulfillLaboratoryHeyBanco3dsPaymentAction;
use App\Actions\Payments\HeyBanco\HandleHeyBanco3dsCallbackAction;
use App\Actions\Payments\HeyBanco\StartHeyBanco3dsTokenChargeAction;
use App\Models\Payment3dsSession;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Payments\HeyBanco\HeyBanco3dsSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class HeyBanco3dsTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment3ds_session_model_uses_correct_table_name(): void
    {
        $this->assertSame('payment_3ds_sessions', (new Payment3dsSession())->getTable());
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'heybanco.enabled' => true,
            'heybanco.3ds_enabled' => true,
            'heybanco.3ds_secure_api' => true,
            'heybanco.3ds_secret_key' => 'test-secret-key-3ds',
            'heybanco.3ds_url' => 'https://testcolecto.banregio.com/tds/vistas/recepcion3ds.zul',
            'heybanco.3ds_media_id' => 'TESTMEDIA1',
            'heybanco.3ds_affiliation' => '8379507',
            'heybanco.mode' => 'AUT',
        ]);
    }

    public function test_sign_request_generates_hmac_sha256_base64(): void
    {
        $service = new HeyBanco3dsSignatureService();

        $payload = [
            'BNRG_ID_MEDIO' => 'TESTMEDIA1',
            'BNRG_ID_AFILIACION' => '8379507',
            'BNRG_MONTO_TRANS' => '150.00',
            'BNRG_TOKEN' => 'token-abc-123',
            'BNRG_FOLIO' => 'FM1234567890',
            'BNRG_REF_CLIENTE1' => 'REF-001',
            'BNRG_MODO_TRANS' => 'AUT',
            'BNRG_FECHA_LOCAL' => '08062026',
            'BNRG_HORA_LOCAL' => '103000',
        ];

        $hash = $service->signRequest($payload);

        $this->assertNotEmpty($hash);
        $this->assertSame(
            base64_encode(hash_hmac('sha256', $service->canonicalRequestString($payload), 'test-secret-key-3ds', true)),
            $hash
        );
    }

    public function test_validate_response_accepts_matching_hash(): void
    {
        $service = new HeyBanco3dsSignatureService();

        $payload = [
            'BNRG_CODIGO_PROC' => 'A',
            'BNRG_CODIGO_AUT' => '123456',
            'BNRG_REFERENCIA' => 'REF-3DS-001',
            'BNRG_FOLIO' => 'FM1234567890',
            'BNRG_MONTO_TRANS' => '150.00',
        ];

        $payload['BNRG_HASH'] = $service->signResponse($payload);

        $this->assertTrue($service->validateResponse($payload));
    }

    public function test_start_3ds_does_not_send_pan_exp_or_cvv(): void
    {
        Http::fake([
            '*' => Http::response('BNRG_URL_REDIRECCION=https://challenge.banregio.test/3ds', 200, [
                'BNRG_URL_REDIRECCION' => 'https://challenge.banregio.test/3ds',
            ]),
        ]);

        [$session, $paymentMethod] = $this->createSessionFixtures();

        app(StartHeyBanco3dsTokenChargeAction::class)($session);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return isset($data['BNRG_TOKEN'])
                && ! isset($data['BNRG_NUMERO_TARJETA'])
                && ! isset($data['BNRG_FECHA_EXP'])
                && ! isset($data['BNRG_CODIGO_SEGURIDAD']);
        });
    }

    public function test_create_session_persists_attempt_transaction_and_session(): void
    {
        [$user, $paymentMethod] = $this->createPaymentMethod();

        $session = app(CreateHeyBanco3dsTokenChargeSessionAction::class)(
            customer: $user->customer,
            paymentMethodId: 'hey_banco:' . $paymentMethod->id,
            amountCents: 15000,
            checkoutContext: ['type' => 'laboratory_checkout'],
        );

        $this->assertDatabaseHas('payment_attempts', [
            'id' => $session->payment_attempt_id,
            'status' => 'pending_3ds',
        ]);

        $this->assertDatabaseHas('payment_transactions', [
            'id' => $session->payment_transaction_id,
            'flow' => 'token_3ds_charge',
            'status' => 'pending_3ds',
        ]);

        $this->assertDatabaseHas('payment_3ds_sessions', [
            'id' => $session->id,
            'payment_method_id' => $paymentMethod->id,
        ]);
    }

    public function test_start_3ds_returns_redirect_url(): void
    {
        Http::fake([
            '*' => Http::response('', 200, [
                'BNRG_URL_REDIRECCION' => 'https://challenge.banregio.test/3ds',
            ]),
        ]);

        [$session] = $this->createSessionFixtures();

        $result = app(StartHeyBanco3dsTokenChargeAction::class)($session);

        $this->assertTrue($result->success);
        $this->assertSame('https://challenge.banregio.test/3ds', $result->redirectUrl);
        $this->assertSame('redirect_required', $session->fresh()->status);
    }

    public function test_approved_callback_updates_records_without_charge_token(): void
    {
        $this->mockFulfillment();
        $chargeTokenMock = Mockery::mock(ChargeHeyBancoTokenAction::class);
        $chargeTokenMock->shouldNotReceive('__invoke');
        $this->app->instance(ChargeHeyBancoTokenAction::class, $chargeTokenMock);

        [$session] = $this->createSessionFixtures(status: 'redirect_required');

        $payload = [
            'BNRG_CODIGO_PROC' => 'A',
            'BNRG_CODIGO_AUT' => 'AUTH01',
            'BNRG_REFERENCIA' => 'REF-APPROVED',
            'BNRG_FOLIO' => $session->folio,
            'BNRG_MONTO_TRANS' => number_format((float) $session->amount, 2, '.', ''),
            'BNRG_3DS_ECI' => '05',
            'BNRG_3DS_UCAF' => 'ucaf-value',
            'BNRG_3DS_XID' => 'xid-value',
            'BNRG_TEXTO' => 'Aprobada',
        ];

        $signature = app(HeyBanco3dsSignatureService::class);
        $payload['BNRG_HASH'] = $signature->signResponse($payload);

        $result = app(HandleHeyBanco3dsCallbackAction::class)($payload);

        $this->assertFalse($result['already_processed']);
        $this->assertSame('approved', $result['session']->status);
        $this->assertNotNull($result['transaction']);
        $this->assertSame('approved', $session->paymentAttempt->fresh()->status);
        $this->assertSame('approved', $session->paymentTransaction->fresh()->status);
    }

    public function test_duplicate_approved_callback_is_idempotent(): void
    {
        $this->mockFulfillment();
        [$session] = $this->createSessionFixtures(status: 'redirect_required');

        $payload = $this->approvedPayloadFor($session);
        $handler = app(HandleHeyBanco3dsCallbackAction::class);

        $first = $handler($payload);
        $second = $handler($payload);

        $this->assertFalse($first['already_processed']);
        $this->assertTrue($second['already_processed']);
        $this->assertSame(1, \App\Models\Transaction::query()->count());
    }

    public function test_invalid_hash_callback_fails(): void
    {
        [$session] = $this->createSessionFixtures(status: 'redirect_required');

        $payload = $this->approvedPayloadFor($session, sign: false);
        $payload['BNRG_HASH'] = 'invalid-hash';

        $this->expectException(\App\Exceptions\HeyBancoPaymentException::class);

        app(HandleHeyBanco3dsCallbackAction::class)($payload);
    }

    public function test_declined_callback_does_not_create_paid_transaction(): void
    {
        [$session] = $this->createSessionFixtures(status: 'redirect_required');

        $payload = [
            'BNRG_CODIGO_PROC' => 'D',
            'BNRG_FOLIO' => $session->folio,
            'BNRG_TEXTO' => 'Declinada',
            'BNRG_MONTO_TRANS' => number_format((float) $session->amount, 2, '.', ''),
        ];
        $payload['BNRG_HASH'] = app(HeyBanco3dsSignatureService::class)->signResponse($payload);

        $result = app(HandleHeyBanco3dsCallbackAction::class)($payload);

        $this->assertNull($result['transaction']);
        $this->assertSame('declined', $result['session']->status);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_other_user_cannot_start_3ds_with_foreign_token(): void
    {
        [$owner, $paymentMethod] = $this->createPaymentMethod();
        $intruder = User::factory()->withRegularCustomer()->create();

        $this->expectException(\App\Exceptions\HeyBancoPaymentException::class);

        app(CreateHeyBanco3dsTokenChargeSessionAction::class)(
            customer: $intruder->customer,
            paymentMethodId: 'hey_banco:' . $paymentMethod->id,
            amountCents: 10000,
        );
    }

    /**
     * @return array{0: Payment3dsSession, 1: PaymentMethod}
     */
    private function createSessionFixtures(string $status = 'pending'): array
    {
        [$user, $paymentMethod] = $this->createPaymentMethod();

        $session = app(CreateHeyBanco3dsTokenChargeSessionAction::class)(
            customer: $user->customer,
            paymentMethodId: 'hey_banco:' . $paymentMethod->id,
            amountCents: 15000,
            checkoutContext: ['type' => 'laboratory_checkout', 'customer_id' => $user->customer->id],
        );

        if ($status !== 'pending') {
            $session->update(['status' => $status]);
        }

        return [$session->fresh(), $paymentMethod];
    }

    /**
     * @return array{0: User, 1: PaymentMethod}
     */
    private function createPaymentMethod(): array
    {
        $user = User::factory()->withRegularCustomer()->create();

        $paymentMethod = PaymentMethod::create([
            'user_id' => $user->id,
            'provider' => 'hey_banco',
            'provider_token' => 'token-test-3ds',
            'brand' => 'visa',
            'last4' => '1096',
            'exp_month' => '12',
            'exp_year' => '2028',
            'affiliation_id' => 'TEST-AFF',
            'media_id' => 'TEST-MEDIA',
            'status' => 'active',
            'alias' => 'visa-1096',
            'card_holder' => 'Titular Test',
        ]);

        return [$user, $paymentMethod];
    }

    /**
     * @return array<string, string>
     */
    private function mockFulfillment(): void
    {
        $this->mock(FulfillLaboratoryHeyBanco3dsPaymentAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->andReturn(null);
        });
    }

    /**
     * @return array<string, string>
     */
    private function approvedPayloadFor(Payment3dsSession $session, bool $sign = true): array
    {
        $payload = [
            'BNRG_CODIGO_PROC' => 'A',
            'BNRG_CODIGO_AUT' => 'AUTH01',
            'BNRG_REFERENCIA' => 'REF-APPROVED',
            'BNRG_FOLIO' => $session->folio,
            'BNRG_MONTO_TRANS' => number_format((float) $session->amount, 2, '.', ''),
            'BNRG_3DS_ECI' => '05',
            'BNRG_TEXTO' => 'Aprobada',
        ];

        if ($sign) {
            $payload['BNRG_HASH'] = app(HeyBanco3dsSignatureService::class)->signResponse($payload);
        }

        return $payload;
    }
}
