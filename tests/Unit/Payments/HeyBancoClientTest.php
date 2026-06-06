<?php

namespace Tests\Unit\Payments;

use App\Models\PaymentTransaction;
use App\Services\Payments\HeyBanco\HeyBancoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HeyBancoClientTest extends TestCase
{
    use RefreshDatabase;

    private HeyBancoClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'heybanco.enabled' => true,
            'heybanco.adq_url' => 'https://testcolecto.banregio.com/adq/',
            'heybanco.token_affiliation' => '8379502',
            'heybanco.token_media_id' => 'JCZSH8TV',
            'heybanco.mode' => 'AUT',
            'heybanco.provider_key' => 'hey_banco',
        ]);

        $this->client = app(HeyBancoClient::class);
    }

    public function test_generate_folio_is_max_12_characters(): void
    {
        $folio = $this->client->generateFolio();

        $this->assertLessThanOrEqual(12, strlen($folio));
        $this->assertNotEmpty($folio);
    }

    public function test_local_date_format_is_ddmmaaaa(): void
    {
        $this->assertMatchesRegularExpression('/^\d{8}$/', $this->client->localDate());
    }

    public function test_local_time_format_is_hhmmss(): void
    {
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->client->localTime());
    }

    public function test_normalize_headers_parses_bnrg_headers_and_decodes_values(): void
    {
        $headers = [
            'BNRG_CODIGO_PROC' => ['A'],
            'BNRG_TEXTO' => ['Transacci%C3%B3n%20aprobada'],
            'BNRG_TOKEN' => ['abc%3Dxyz'],
            'Content-Type' => ['text/html'],
        ];

        $normalized = $this->client->normalizeHeaders($headers);

        $this->assertSame('A', $normalized['BNRG_CODIGO_PROC']);
        $this->assertSame('Transacción aprobada', $normalized['BNRG_TEXTO']);
        $this->assertSame('abc=xyz', $normalized['BNRG_TOKEN']);
        $this->assertArrayNotHasKey('CONTENT-TYPE', $normalized);
    }

    public function test_create_token_approved(): void
    {
        Http::fake([
            'testcolecto.banregio.com/*' => Http::response('', 200, [
                'BNRG_CODIGO_PROC' => 'A',
                'BNRG_TOKEN' => 'token%3Dapproved',
                'BNRG_FOLIO' => 'FM1234567890',
            ]),
        ]);

        $response = $this->client->createToken([
            'card_number' => '4456530000001096',
            'exp_month' => '12',
            'exp_year' => '26',
            'cvv' => '123',
        ]);

        $this->assertTrue($response->isApproved());
        $this->assertSame('token=approved', $response->token());
    }

    public function test_charge_token_approved(): void
    {
        Http::fake([
            'testcolecto.banregio.com/*' => Http::response('', 200, [
                'BNRG_CODIGO_PROC' => 'A',
                'BNRG_REFERENCIA' => 'REF123456',
                'BNRG_CODIGO_AUT' => 'AUTH01',
            ]),
        ]);

        $response = $this->client->chargeToken('saved-token', 10.00, 'order-99');

        $this->assertTrue($response->isApproved());
        $this->assertSame('REF123456', $response->referencia());
        $this->assertSame('AUTH01', $response->codigoAut());
    }

    public function test_charge_token_rejected(): void
    {
        Http::fake([
            'testcolecto.banregio.com/*' => Http::response('', 200, [
                'BNRG_CODIGO_PROC' => 'R',
                'BNRG_CODIGO_RECHAZO' => '05',
                'BNRG_TEXTO' => 'Rechazada%20por%20emisor',
            ]),
        ]);

        $response = $this->client->chargeToken('saved-token', 10.00, 'order-99');

        $this->assertTrue($response->isRejected());
        $this->assertSame('05', $response->codigoRechazo());
        $this->assertSame('Rechazada por emisor', $response->texto());
    }

    public function test_charge_token_declined(): void
    {
        Http::fake([
            'testcolecto.banregio.com/*' => Http::response('', 200, [
                'BNRG_CODIGO_PROC' => 'D',
                'BNRG_TEXTO' => 'Declinada',
            ]),
        ]);

        $response = $this->client->chargeToken('saved-token', 10.00, 'order-99');

        $this->assertTrue($response->isDeclined());
        $this->assertSame('declined', $response->statusLabel());
    }

    public function test_charge_token_timeout(): void
    {
        Http::fake([
            'testcolecto.banregio.com/*' => Http::response('', 200, [
                'BNRG_CODIGO_PROC' => 'T',
                'BNRG_REFERENCIA' => 'REF-TIMEOUT',
            ]),
        ]);

        $response = $this->client->chargeToken('saved-token', 10.00, 'order-99');

        $this->assertTrue($response->isTimeout());
        $this->assertSame('REF-TIMEOUT', $response->referencia());
    }

    public function test_verify_by_reference_approved(): void
    {
        Http::fake([
            'testcolecto.banregio.com/*' => Http::response('', 200, [
                'BNRG_CODIGO_PROC' => 'A',
                'BNRG_CODIGO_PROC_TRANS' => 'A',
                'BNRG_ESTADO_TRANS' => 'C',
                'BNRG_TIPO_TRANS' => 'VE',
            ]),
        ]);

        $response = $this->client->verifyByReference('REF123456');

        $this->assertTrue($response->isVerificationApproved());
    }

    public function test_cancel_by_reference_approved(): void
    {
        Http::fake([
            'testcolecto.banregio.com/*' => Http::response('', 200, [
                'BNRG_CODIGO_PROC' => 'A',
                'BNRG_REFERENCIA' => 'REF-CANCEL-001',
                'BNRG_FOLIO' => 'FM-CANCEL01',
                'BNRG_TEXTO' => 'Cancelacion%20aprobada',
            ]),
        ]);

        $response = $this->client->cancelByReference('REF-ORIGINAL-999', null, 131.00);

        $this->assertTrue($response->isApproved());
        $this->assertSame('REF-CANCEL-001', $response->referencia());

        Http::assertSent(function ($request) {
            $data = $request->data();

            return ($data['BNRG_CMD_TRANS'] ?? null) === 'CANCELACION'
                && ! empty($data['BNRG_FOLIO'])
                && ($data['BNRG_ID_AFILIACION'] ?? null) === '8379502'
                && ($data['BNRG_REF_TRANS_PREVIA'] ?? null) === 'REF-ORIGINAL-999'
                && ($data['BNRG_MONTO_TRANS'] ?? null) === '131.00';
        });
    }

    public function test_folio_uniqueness_considers_existing_transactions(): void
    {
        PaymentTransaction::create([
            'provider' => 'hey_banco',
            'flow' => 'token_charge',
            'folio' => 'FMEXISTING01',
            'amount' => 1,
            'currency' => 'MXN',
            'status' => 'approved',
        ]);

        $folio = $this->client->generateFolio();

        $this->assertNotSame('FMEXISTING01', $folio);
    }
}
