<?php

namespace Tests\Feature\Laboratory;

use App\Actions\Laboratories\ResolveGdaResultsPdfAction;
use App\Actions\Laboratories\SyncGdaResultPdfToStorageAction;
use App\Actions\Laboratory\HandleResultsNotificationAction;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Jobs\Laboratory\SyncGdaResultPdfToStorageJob;
use App\Models\Customer;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GdaResultsStoragePhase2IsolatedTest extends TestCase
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
    public function despacha_job_al_procesar_webhook_sin_pdf_embebido(): void
    {
        Bus::fake();

        $purchase = $this->seedPurchase(['gda_order_id' => 'HD0L001392']);
        $payload = $this->resultsWebhookPayload('HD0L001392');
        unset($payload['infogda_resultado_b64']);

        $notification = $this->seedResultsNotificationRecord($purchase);

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

        Bus::assertDispatched(SyncGdaResultPdfToStorageJob::class, function (SyncGdaResultPdfToStorageJob $job) use ($purchase, $notification) {
            return $job->purchaseId === $purchase->id
                && $job->notificationId === $notification->id;
        });
    }

    #[Test]
    public function no_despacha_job_si_pdf_manual(): void
    {
        Bus::fake();

        $purchase = $this->seedPurchase([
            'gda_order_id' => 'HD0L001392',
            'results' => 'results/manual-existing.pdf',
        ]);
        Storage::put('results/manual-existing.pdf', $this->samplePdfBinary());

        $notification = $this->seedResultsNotificationRecord($purchase);
        $payload = $this->resultsWebhookPayload('HD0L001392');
        unset($payload['infogda_resultado_b64']);

        app(HandleResultsNotificationAction::class)->execute(
            $notification,
            $payload,
            ['purchase_id' => $purchase->id, 'gda' => ['gda_order_id' => 'HD0L001392', 'gda_consecutivo' => 1392]]
        );

        Bus::assertNotDispatched(SyncGdaResultPdfToStorageJob::class);
    }

    #[Test]
    public function no_despacha_job_si_pdf_gda_current(): void
    {
        Bus::fake();

        $this->travelTo(\Carbon\Carbon::parse('2026-08-21 17:00:00'));

        $purchase = $this->seedPurchase(['gda_order_id' => 'HD0L001392']);
        $path = sprintf(\App\Support\Laboratory\GdaResultsPdfStatus::GDA_STORED_PATH_PATTERN, $purchase->id, 'currentpdf12');
        Storage::put($path, $this->samplePdfBinary());
        $purchase->update(['results' => $path]);

        $notification = $this->seedResultsNotificationRecord($purchase);
        $payload = $this->resultsWebhookPayload('HD0L001392');
        unset($payload['infogda_resultado_b64']);

        // Handle pisa results_received_at con now(); el PDF debe ser posterior a ese instante.
        touch(Storage::path($path), now()->addMinute()->timestamp);

        app(HandleResultsNotificationAction::class)->execute(
            $notification,
            $payload,
            ['purchase_id' => $purchase->id, 'gda' => ['gda_order_id' => 'HD0L001392', 'gda_consecutivo' => 1392]]
        );

        Bus::assertNotDispatched(SyncGdaResultPdfToStorageJob::class);
    }

    #[Test]
    public function despacha_job_si_pdf_gda_stale(): void
    {
        Bus::fake();

        $this->travelTo(\Carbon\Carbon::parse('2026-08-21 16:38:00'));
        $purchase = $this->seedPurchase(['gda_order_id' => 'HD0L001392']);
        $path = sprintf(\App\Support\Laboratory\GdaResultsPdfStatus::GDA_STORED_PATH_PATTERN, $purchase->id, 'oldpdfstored');
        Storage::put($path, $this->samplePdfBinary());
        touch(Storage::path($path), now()->timestamp);
        $purchase->update(['results' => $path]);
        $this->seedResultsNotificationRecord($purchase);

        $this->travelTo(\Carbon\Carbon::parse('2026-08-24 12:17:00'));
        $notification = $this->seedResultsNotificationRecord($purchase);
        $payload = $this->resultsWebhookPayload('HD0L001392');
        unset($payload['infogda_resultado_b64']);

        app(HandleResultsNotificationAction::class)->execute(
            $notification,
            $payload,
            ['purchase_id' => $purchase->id, 'gda' => ['gda_order_id' => 'HD0L001392', 'gda_consecutivo' => 1392]]
        );

        Bus::assertDispatched(SyncGdaResultPdfToStorageJob::class, function (SyncGdaResultPdfToStorageJob $job) use ($purchase, $notification) {
            return $job->purchaseId === $purchase->id
                && $job->notificationId === $notification->id;
        });
    }

    #[Test]
    public function no_despacha_job_si_webhook_ya_guardo_pdf_en_storage(): void
    {
        Bus::fake();

        $purchase = $this->seedPurchase(['gda_order_id' => 'HD0L001392']);
        $payload = $this->resultsWebhookPayload('HD0L001392', $this->samplePdfBase64());
        $notification = $this->seedResultsNotificationRecord($purchase);

        app(HandleResultsNotificationAction::class)->execute(
            $notification,
            $payload,
            ['purchase_id' => $purchase->id, 'gda' => ['gda_order_id' => 'HD0L001392', 'gda_consecutivo' => 1392]]
        );

        Bus::assertNotDispatched(SyncGdaResultPdfToStorageJob::class);

        $purchase->refresh();
        $this->assertNotEmpty($purchase->results);
        $this->assertTrue(Storage::exists($purchase->results));
    }

    #[Test]
    public function job_consulta_gda_y_guarda_pdf_en_purchase_results(): void
    {
        $purchase = $this->seedPurchase();
        $notification = $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn(['infogda_resultado_b64' => $this->samplePdfBase64()]);
        });

        $path = app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $notification->id);

        $purchase->refresh();
        $notification->refresh();

        $this->assertNotEmpty($path);
        $this->assertSame($path, $purchase->results);
        $this->assertTrue(Storage::exists($purchase->results));
        $this->assertNull($notification->results_pdf_base64);
    }

    #[Test]
    public function job_sale_temprano_si_pdf_manual(): void
    {
        $purchase = $this->seedPurchase(['results' => 'results/existing.pdf']);
        Storage::put('results/existing.pdf', $this->samplePdfBinary());
        $notification = $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->never();
        });

        $path = app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $notification->id);

        $this->assertSame('results/existing.pdf', $path);
    }

    #[Test]
    public function job_sale_temprano_si_pdf_gda_current(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-08-21 17:00:00'));
        $purchase = $this->seedPurchase();
        $path = sprintf(\App\Support\Laboratory\GdaResultsPdfStatus::GDA_STORED_PATH_PATTERN, $purchase->id, 'currentpdf12');
        Storage::put($path, $this->samplePdfBinary());
        touch(Storage::path($path), now()->timestamp);
        $purchase->update(['results' => $path]);

        $this->travelTo(\Carbon\Carbon::parse('2026-08-21 16:00:00'));
        $notification = $this->seedResultsNotificationRecord($purchase);
        $notification->update(['results_received_at' => now()]);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->never();
        });

        $result = app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $notification->id);

        $this->assertSame($path, $result);
    }

    #[Test]
    public function job_falla_controlado_con_base64_invalido(): void
    {
        $purchase = $this->seedPurchase();
        $notification = $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn(['infogda_resultado_b64' => '%%%invalid%%%']);
        });

        $this->expectException(DomainException::class);

        app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $notification->id);
    }

    #[Test]
    public function job_falla_si_gda_no_devuelve_base64(): void
    {
        $purchase = $this->seedPurchase();
        $notification = $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn([]);
        });

        $this->expectException(\RuntimeException::class);

        app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $notification->id);
    }

    #[Test]
    public function fallback_lazy_sigue_funcionando_si_job_no_corrio(): void
    {
        $purchase = $this->seedPurchase();
        $notification = $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn(['infogda_resultado_b64' => $this->samplePdfBase64()]);
        });

        $result = app(ResolveGdaResultsPdfAction::class)($notification);

        $purchase->refresh();

        $this->assertTrue($result['refreshed']);
        $this->assertNotEmpty($purchase->results);
        $this->assertTrue(Storage::exists($purchase->results));
    }

    #[Test]
    public function segunda_consulta_usa_storage_sin_llamar_gda(): void
    {
        $purchase = $this->seedPurchase();
        $notification = $this->seedResultsNotificationRecord($purchase);

        $gdaMock = $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class);
        $gdaMock->shouldReceive('__invoke')
            ->once()
            ->andReturn(['infogda_resultado_b64' => $this->samplePdfBase64()]);

        app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $notification->id);

        $result = app(ResolveGdaResultsPdfAction::class)($notification->fresh());

        $this->assertTrue($result['cached']);
        $this->assertFalse($result['refreshed']);
    }

    private function seedResultsNotificationRecord(LaboratoryPurchase $purchase): LaboratoryNotification
    {
        return LaboratoryNotification::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'notification_type' => LaboratoryNotification::TYPE_RESULTS,
            'lineanegocio' => LaboratoryNotification::LINEA_NEGOCIO_RESULTS,
            'gda_order_id' => $purchase->gda_order_id,
            'gda_consecutivo' => $purchase->gda_consecutivo,
            'status' => LaboratoryNotification::STATUS_RECEIVED,
            'gda_status' => LaboratoryNotification::GDA_STATUS_COMPLETED,
            'resource_type' => 'ServiceRequest',
            'results_received_at' => now(),
            'payload' => [
                'header' => ['marca' => 5],
                'requisition' => ['convenio' => 99999, 'value' => 'REQ-1'],
                'id' => $purchase->gda_order_id,
            ],
        ]);
    }

    private function resultsWebhookPayload(string $orderId, ?string $pdfBase64 = null): array
    {
        $payload = [
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
                    'infogda_orden' => '1392',
                ]],
            ],
            'GDA_menssage' => [
                'acuse' => (string) \Illuminate\Support\Str::uuid(),
                'codeHttp' => 200,
                'mensaje' => 'OK',
                'descripcion' => 'Resultados disponibles',
            ],
        ];

        if ($pdfBase64 !== null) {
            $payload['infogda_resultado_b64'] = $pdfBase64;
        }

        return $payload;
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
}
