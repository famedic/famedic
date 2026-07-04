<?php

namespace Tests\Feature\Laboratory;

use App\Actions\Laboratories\ResolveGdaResultsPdfAction;
use App\Actions\Laboratories\StoreGdaResultsPdfToStorageAction;
use App\Actions\Laboratory\CreateNotificationAction;
use App\Actions\Laboratory\HandleResultsNotificationAction;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\Customer;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use App\Support\GDA\GdaPayloadSanitizer;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GdaResultsStoragePhase1IsolatedTest extends TestCase
{
    use GdaResultsStorageIsolatedSchema;
    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        Storage::fake();
        NotificationFacade::fake();

        $this->bootstrapIsolatedSchema();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedSchema();

        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function guarda_pdf_gda_valido_en_storage_y_llena_purchase_results(): void
    {
        $purchase = $this->seedPurchase();
        $pdfBase64 = $this->samplePdfBase64();

        $path = app(StoreGdaResultsPdfToStorageAction::class)->execute(
            $purchase,
            $pdfBase64
        );

        $purchase->refresh();

        $this->assertNotEmpty($purchase->results);
        $this->assertSame($path, $purchase->results);
        $this->assertTrue(Storage::exists($path));
        $this->assertStringStartsWith('%PDF', Storage::get($path));
    }

    #[Test]
    public function rechaza_base64_invalido(): void
    {
        $purchase = $this->seedPurchase();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('GDA results PDF base64 is invalid.');

        app(StoreGdaResultsPdfToStorageAction::class)->execute($purchase, '%%%not-base64%%%');
    }

    #[Test]
    public function rechaza_base64_valido_que_no_es_pdf(): void
    {
        $purchase = $this->seedPurchase();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('GDA results payload is not a valid PDF.');

        app(StoreGdaResultsPdfToStorageAction::class)->execute(
            $purchase,
            base64_encode('not-a-pdf-file')
        );
    }

    #[Test]
    public function no_sobrescribe_resultado_manual_existente(): void
    {
        $purchase = $this->seedPurchase(['results' => 'results/manual-existing.pdf']);
        Storage::put('results/manual-existing.pdf', $this->samplePdfBinary());

        $path = app(StoreGdaResultsPdfToStorageAction::class)->execute(
            $purchase,
            $this->samplePdfBase64()
        );

        $purchase->refresh();

        $this->assertSame('results/manual-existing.pdf', $path);
        $this->assertSame('results/manual-existing.pdf', $purchase->results);
        $this->assertFalse(Storage::exists('results/gda-'.$purchase->id.'-'.substr(hash('sha256', $this->samplePdfBinary()), 0, 12).'.pdf'));
    }

    #[Test]
    public function resolve_gda_results_guarda_en_storage_sin_base64_en_db(): void
    {
        $purchase = $this->seedPurchase();
        $notification = $this->seedResultsNotification($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn(['infogda_resultado_b64' => $this->samplePdfBase64()]);
        });

        $result = app(ResolveGdaResultsPdfAction::class)($notification);

        $purchase->refresh();
        $notification->refresh();

        $this->assertTrue($result['refreshed']);
        $this->assertNotEmpty($purchase->results);
        $this->assertTrue(Storage::exists($purchase->results));
        $this->assertNull($notification->results_pdf_base64);
        $this->assertSame('storage', data_get($notification->gda_message, 'results_source'));
        $this->assertSame($this->samplePdfBase64(), $result['pdf_base64']);
    }

    #[Test]
    public function segunda_consulta_no_vuelve_a_llamar_a_gda_si_results_existe(): void
    {
        $purchase = $this->seedPurchase();
        $notification = $this->seedResultsNotification($purchase);

        $gdaMock = $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class);
        $gdaMock->shouldReceive('__invoke')
            ->once()
            ->andReturn(['infogda_resultado_b64' => $this->samplePdfBase64()]);

        app(ResolveGdaResultsPdfAction::class)($notification);

        $result = app(ResolveGdaResultsPdfAction::class)($notification->fresh());

        $this->assertTrue($result['cached']);
        $this->assertFalse($result['refreshed']);
    }

    #[Test]
    public function webhook_con_base64_sanitiza_payload_y_guarda_en_storage(): void
    {
        $purchase = $this->seedPurchase(['gda_order_id' => 'HD0L001392']);
        $pdfBase64 = $this->samplePdfBase64();
        $payload = $this->resultsWebhookPayload('HD0L001392', $pdfBase64);

        $notification = LaboratoryNotification::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'notification_type' => LaboratoryNotification::TYPE_RESULTS,
            'lineanegocio' => LaboratoryNotification::LINEA_NEGOCIO_RESULTS,
            'gda_order_id' => 'HD0L001392',
            'gda_consecutivo' => 1392,
            'status' => LaboratoryNotification::STATUS_RECEIVED,
            'gda_status' => 'completed',
            'resource_type' => 'ServiceRequest',
            'payload' => GdaPayloadSanitizer::sanitize($payload),
        ]);

        app(HandleResultsNotificationAction::class)->execute(
            $notification,
            $payload,
            [
                'purchase_id' => $purchase->id,
                'gda' => [
                    'gda_order_id' => 'HD0L001392',
                    'gda_consecutivo' => 1392,
                ],
            ]
        );

        $purchase->refresh();
        $notification->refresh();

        $this->assertNotEmpty($purchase->results);
        $this->assertTrue(Storage::exists($purchase->results));
        $this->assertNull($notification->results_pdf_base64);
        $this->assertNull($purchase->pdf_base64 ?? null);
        $gdaResponse = is_array($purchase->gda_response)
            ? $purchase->gda_response
            : json_decode($purchase->gda_response ?? '[]', true);

        $this->assertArrayNotHasKey('infogda_resultado_b64', $gdaResponse ?? []);
        $this->assertArrayNotHasKey('infogda_resultado_b64', $notification->payload ?? []);
    }

    #[Test]
    public function create_notification_sanitiza_payload_con_base64(): void
    {
        $purchase = $this->seedPurchase(['gda_order_id' => 'GZ0L000414']);
        $payload = $this->resultsWebhookPayload('GZ0L000414', $this->samplePdfBase64(), '414');

        $request = Request::create('/api/laboratory/webhook/notifications', 'POST', $payload);

        $notification = app(CreateNotificationAction::class)->execute($payload, $request, [
            'purchase_id' => $purchase->id,
            'gda' => [
                'gda_order_id' => 'GZ0L000414',
                'gda_consecutivo' => 414,
                'gda_external_id' => (string) $purchase->id,
                'acuse' => $payload['GDA_menssage']['acuse'],
            ],
        ]);

        $this->assertArrayNotHasKey('infogda_resultado_b64', $notification->payload);
        $this->assertSame('GZ0L000414', $notification->payload['id']);
    }

    private function samplePdfBinary(): string
    {
        return "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF";
    }

    private function samplePdfBase64(): string
    {
        return base64_encode($this->samplePdfBinary());
    }

    private function seedPurchase(array $overrides = []): LaboratoryPurchase
    {
        $user = User::query()->create([
            'name' => 'Paciente Test',
            'email' => 'patient-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);

        $customer = Customer::query()->create([
            'user_id' => $user->id,
        ]);

        return LaboratoryPurchase::query()->create(array_merge([
            'customer_id' => $customer->id,
            'brand' => LaboratoryBrand::OLAB->value,
            'gda_order_id' => 'GDA-ORDER-'.uniqid(),
            'gda_consecutivo' => 100,
            'name' => 'Juan',
            'paternal_lastname' => 'Perez',
            'maternal_lastname' => 'Lopez',
            'phone' => '5555555555',
            'phone_country' => 'MX',
            'birth_date' => '1990-01-01',
            'gender' => Gender::MALE->value,
            'street' => 'Calle',
            'number' => '1',
            'neighborhood' => 'Centro',
            'state' => 'CDMX',
            'city' => 'CDMX',
            'zipcode' => '01000',
            'total_cents' => 10000,
            'status' => 'pending',
        ], $overrides));
    }

    private function seedResultsNotification(LaboratoryPurchase $purchase): LaboratoryNotification
    {
        return LaboratoryNotification::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'notification_type' => LaboratoryNotification::TYPE_RESULTS,
            'lineanegocio' => LaboratoryNotification::LINEA_NEGOCIO_RESULTS,
            'gda_order_id' => $purchase->gda_order_id,
            'gda_consecutivo' => $purchase->gda_consecutivo,
            'status' => LaboratoryNotification::STATUS_PROCESSED,
            'gda_status' => LaboratoryNotification::GDA_STATUS_COMPLETED,
            'results_received_at' => now(),
            'payload' => [
                'header' => ['marca' => 5],
                'requisition' => ['convenio' => 99999, 'value' => 'REQ-1'],
                'id' => $purchase->gda_order_id,
            ],
        ]);
    }

    private function resultsWebhookPayload(string $orderId, string $pdfBase64, string $consecutivo = '1392'): array
    {
        return [
            'resourceType' => 'ServiceRequest',
            'id' => $orderId,
            'status' => 'completed',
            'header' => [
                'lineanegocio' => LaboratoryNotification::LINEA_NEGOCIO_RESULTS,
                'marca' => 5,
            ],
            'requisition' => [
                'value' => (string) random_int(100000, 999999),
                'convenio' => 99999,
            ],
            'code' => [
                'coding' => [[
                    'code' => 'TTOG',
                    'display' => 'Estudio',
                    'infogda_orden' => $consecutivo,
                ]],
            ],
            'GDA_menssage' => [
                'acuse' => (string) \Illuminate\Support\Str::uuid(),
                'codeHttp' => 200,
                'mensaje' => 'OK',
                'descripcion' => 'Resultados disponibles',
            ],
            'infogda_resultado_b64' => $pdfBase64,
        ];
    }
}
