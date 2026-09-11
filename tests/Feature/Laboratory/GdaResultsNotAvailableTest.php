<?php

namespace Tests\Feature\Laboratory;

use App\Actions\Laboratories\GetGDAResultsAction;
use App\Actions\Laboratories\ResolveGdaResultsPdfAction;
use App\Actions\Laboratories\SyncGdaResultPdfToStorageAction;
use App\Exceptions\GdaResultsNotAvailableException;
use App\Jobs\Laboratory\SyncGdaResultPdfToStorageJob;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GdaResultsNotAvailableTest extends TestCase
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
    public function get_gda_results_action_throws_not_available_exception_on_400_no_contiene_resultados(): void
    {
        $gdaUrl = app(GetGDAResultsAction::class)->resultsConsultUrl();

        Http::fake([
            $gdaUrl => Http::response([
                'GDA_menssage' => [
                    'descripcion' => 'No contiene resultados.',
                ],
            ], 400),
        ]);

        config(['services.gda.brands.olab' => [
            'brand_id' => 1,
            'brand_agreement_id' => 100,
            'token' => 'test-token',
        ]]);

        $payload = [
            'header' => ['marca' => 1, 'token' => ''],
            'requisition' => ['convenio' => 100, 'value' => '1888'],
            'id' => 'GZ0L000423',
        ];

        $this->expectException(GdaResultsNotAvailableException::class);

        app(GetGDAResultsAction::class)('GZ0L000423', $payload);
    }

    #[Test]
    public function get_gda_results_action_throws_generic_exception_on_other_400_messages(): void
    {
        $gdaUrl = app(GetGDAResultsAction::class)->resultsConsultUrl();

        Http::fake([
            $gdaUrl => Http::response([
                'GDA_menssage' => [
                    'descripcion' => 'Error de credenciales inválidas.',
                ],
            ], 400),
        ]);

        config(['services.gda.brands.olab' => [
            'brand_id' => 1,
            'brand_agreement_id' => 100,
            'token' => 'test-token',
        ]]);

        $payload = [
            'header' => ['marca' => 1, 'token' => ''],
            'requisition' => ['convenio' => 100, 'value' => '1888'],
            'id' => 'GZ0L000423',
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Error GDA/');

        app(GetGDAResultsAction::class)('GZ0L000423', $payload);
    }

    #[Test]
    public function resolve_gda_results_pdf_action_propagates_not_available_exception(): void
    {
        $purchase = $this->seedPurchase(['gda_order_id' => 'GZ0L000423']);
        $notification = $this->seedResultsNotificationRecord($purchase);

        $gdaUrl = app(GetGDAResultsAction::class)->resultsConsultUrl();

        Http::fake([
            $gdaUrl => Http::response([
                'GDA_menssage' => [
                    'descripcion' => 'No contiene resultados.',
                ],
            ], 400),
        ]);

        config(['services.gda.brands.olab' => [
            'brand_id' => 1,
            'brand_agreement_id' => 100,
            'token' => 'test-token',
        ]]);

        $this->expectException(GdaResultsNotAvailableException::class);

        app(ResolveGdaResultsPdfAction::class)($notification);
    }

    #[Test]
    public function resolve_gda_results_pdf_action_records_last_not_available_in_gda_message(): void
    {
        $purchase = $this->seedPurchase(['gda_order_id' => 'GZ0L000423']);
        $notification = $this->seedResultsNotificationRecord($purchase);

        $gdaUrl = app(GetGDAResultsAction::class)->resultsConsultUrl();

        Http::fake([
            $gdaUrl => Http::response([
                'GDA_menssage' => [
                    'descripcion' => 'No contiene resultados.',
                ],
            ], 400),
        ]);

        config(['services.gda.brands.olab' => [
            'brand_id' => 1,
            'brand_agreement_id' => 100,
            'token' => 'test-token',
        ]]);

        try {
            app(ResolveGdaResultsPdfAction::class)($notification);
        } catch (GdaResultsNotAvailableException) {
            // expected
        }

        $notification->refresh();
        $this->assertNotNull(data_get($notification->gda_message, 'last_gda_not_available_at'));
        $this->assertEquals('No contiene resultados.', data_get($notification->gda_message, 'last_gda_not_available_message'));
    }

    #[Test]
    public function sync_job_retries_on_gda_results_not_available(): void
    {
        $purchase = $this->seedPurchase(['gda_order_id' => 'GZ0L000423']);
        $notification = $this->seedResultsNotificationRecord($purchase);

        $gdaUrl = app(GetGDAResultsAction::class)->resultsConsultUrl();

        Http::fake([
            $gdaUrl => Http::response([
                'GDA_menssage' => [
                    'descripcion' => 'No contiene resultados.',
                ],
            ], 400),
        ]);

        config(['services.gda.brands.olab' => [
            'brand_id' => 1,
            'brand_agreement_id' => 100,
            'token' => 'test-token',
        ]]);

        $job = new SyncGdaResultPdfToStorageJob($purchase->id, $notification->id);

        $this->expectException(GdaResultsNotAvailableException::class);

        $job->handle(app(SyncGdaResultPdfToStorageAction::class));
    }

    #[Test]
    public function get_gda_results_action_sends_order_id_as_payload_id_for_gabinete(): void
    {
        $gdaUrl = app(GetGDAResultsAction::class)->resultsConsultUrl();

        Http::fake([
            $gdaUrl => Http::response([
                'infogda_resultado_b64' => base64_encode('%PDF-1.4 test content'),
            ], 200),
        ]);

        config(['services.gda.brands.olab' => [
            'brand_id' => 1,
            'brand_agreement_id' => 100,
            'token' => 'test-token',
        ]]);

        $payload = [
            'header' => ['marca' => 1, 'token' => ''],
            'requisition' => ['convenio' => 100, 'value' => '1888'],
            'id' => 'OLD_VALUE_SHOULD_BE_OVERRIDDEN',
        ];

        $result = app(GetGDAResultsAction::class)('GZ0L000423', $payload);

        $this->assertNotEmpty($result['infogda_resultado_b64']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['id'] ?? null) === 'GZ0L000423';
        });
    }

    #[Test]
    public function gda_results_stored_in_storage_when_pdf_valid(): void
    {
        $purchase = $this->seedPurchase(['gda_order_id' => 'GZ0L000423']);
        $notification = $this->seedResultsNotificationRecord($purchase);

        $pdfContent = '%PDF-1.4 valid test content for storage';
        $gdaUrl = app(GetGDAResultsAction::class)->resultsConsultUrl();

        Http::fake([
            $gdaUrl => Http::response([
                'infogda_resultado_b64' => base64_encode($pdfContent),
            ], 200),
        ]);

        config(['services.gda.brands.olab' => [
            'brand_id' => 1,
            'brand_agreement_id' => 100,
            'token' => 'test-token',
        ]]);

        $result = app(ResolveGdaResultsPdfAction::class)($notification);

        $this->assertNotEmpty($result['pdf_base64']);
        $this->assertTrue($result['refreshed']);

        $purchase->refresh();
        $this->assertNotEmpty($purchase->results);
        Storage::assertExists($purchase->results);
    }

    #[Test]
    public function no_base64_stored_in_database_notification(): void
    {
        $purchase = $this->seedPurchase(['gda_order_id' => 'GZ0L000423']);
        $notification = $this->seedResultsNotificationRecord($purchase);

        $pdfContent = '%PDF-1.4 valid test content';
        $gdaUrl = app(GetGDAResultsAction::class)->resultsConsultUrl();

        Http::fake([
            $gdaUrl => Http::response([
                'infogda_resultado_b64' => base64_encode($pdfContent),
            ], 200),
        ]);

        config(['services.gda.brands.olab' => [
            'brand_id' => 1,
            'brand_agreement_id' => 100,
            'token' => 'test-token',
        ]]);

        app(ResolveGdaResultsPdfAction::class)($notification);

        $notification->refresh();
        $this->assertNull($notification->results_pdf_base64);
    }

    private function seedPurchase(array $overrides = []): LaboratoryPurchase
    {
        $user = \App\Models\User::forceCreate([
            'name' => 'Test',
            'email' => 'test-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);

        $customer = \App\Models\Customer::forceCreate([
            'user_id' => $user->id,
        ]);

        return LaboratoryPurchase::forceCreate(array_merge([
            'customer_id' => $customer->id,
            'brand' => 'olab',
            'name' => 'Paciente',
            'paternal_lastname' => 'Test',
            'maternal_lastname' => 'QA',
            'phone' => '8181234567',
            'phone_country' => 'MX',
            'birth_date' => '1990-01-01',
            'gender' => \App\Enums\Gender::MALE->value,
            'street' => 'Calle',
            'number' => '123',
            'neighborhood' => 'Centro',
            'state' => 'Nuevo León',
            'city' => 'Monterrey',
            'zipcode' => '64000',
            'total_cents' => 50000,
        ], $overrides));
    }

    private function seedResultsNotificationRecord(LaboratoryPurchase $purchase): LaboratoryNotification
    {
        return LaboratoryNotification::forceCreate([
            'laboratory_purchase_id' => $purchase->id,
            'gda_order_id' => $purchase->gda_order_id,
            'gda_consecutivo' => $purchase->gda_consecutivo,
            'notification_type' => LaboratoryNotification::TYPE_RESULTS,
            'status' => LaboratoryNotification::STATUS_PROCESSED,
            'lineanegocio' => LaboratoryNotification::LINEA_NEGOCIO_RESULTS,
            'results_received_at' => now(),
            'payload' => json_encode([
                'header' => ['marca' => 1, 'token' => ''],
                'requisition' => ['convenio' => 100, 'value' => '1888'],
                'id' => $purchase->gda_order_id,
            ]),
            'gda_message' => [],
        ]);
    }
}
