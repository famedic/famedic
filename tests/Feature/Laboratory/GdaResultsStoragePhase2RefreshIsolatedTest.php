<?php

namespace Tests\Feature\Laboratory;

use App\Actions\Laboratories\ResolveGdaResultsPdfAction;
use App\Actions\Laboratories\SyncGdaResultPdfToStorageAction;
use App\Actions\Laboratory\HandleResultsNotificationAction;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Exceptions\GdaResultsNotAvailableException;
use App\Jobs\Laboratory\SyncGdaResultPdfToStorageJob;
use App\Models\Customer;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use App\Support\Laboratory\GdaResultsPdfStatus;
use Carbon\Carbon;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GdaResultsStoragePhase2RefreshIsolatedTest extends TestCase
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
    public function primera_notificacion_sin_pdf_consulta_gda_y_guarda(): void
    {
        $purchase = $this->seedPurchase(['gda_order_id' => 'HD0L001533']);
        $notification = $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn(['infogda_resultado_b64' => $this->samplePdfBase64('A')]);
        });

        $payload = $this->resultsWebhookPayload('HD0L001533');
        unset($payload['infogda_resultado_b64']);

        app(HandleResultsNotificationAction::class)->execute(
            $notification,
            $payload,
            [
                'purchase_id' => $purchase->id,
                'gda' => ['gda_order_id' => 'HD0L001533', 'gda_consecutivo' => $purchase->gda_consecutivo],
            ]
        );

        $purchase->refresh();
        $this->assertNotEmpty($purchase->results);
        $this->assertTrue(Storage::exists($purchase->results));
        $this->assertTrue(GdaResultsPdfStatus::isGdaManagedPath($purchase->results));
        $this->assertSame('gda_current', GdaResultsPdfStatus::assessPurchase($purchase->fresh())->freshnessStatus);
    }

    #[Test]
    public function segunda_notificacion_con_pdf_stale_sobrescribe_con_pdf_nuevo(): void
    {
        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        $purchase = $this->seedPurchase(['gda_order_id' => 'HD0L001533']);
        $pathA = $this->storeGdaPdf($purchase, 'A');
        $this->seedResultsNotificationRecord($purchase);

        $this->travelTo(Carbon::parse('2026-08-21 17:43:00'));
        $notification = $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn(['infogda_resultado_b64' => $this->samplePdfBase64('B')]);
        });

        $pathB = app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $notification->id);

        $purchase->refresh();
        $this->assertNotSame($pathA, $pathB);
        $this->assertSame($pathB, $purchase->results);
        $this->assertTrue(Storage::exists($pathB));
        $this->assertTrue(GdaResultsPdfStatus::isGdaManagedPath($pathB));
        $this->assertSame('gda_current', GdaResultsPdfStatus::assessPurchase($purchase->fresh())->freshnessStatus);
    }

    #[Test]
    public function pdf_gda_current_no_consulta_gda(): void
    {
        $this->travelTo(Carbon::parse('2026-08-21 17:00:00'));
        $purchase = $this->seedPurchase();
        $path = $this->storeGdaPdf($purchase, 'A');

        $this->travelTo(Carbon::parse('2026-08-21 16:00:00'));
        $notification = $this->seedResultsNotificationRecord($purchase);
        $notification->update(['results_received_at' => now()]);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->never();
        });

        $result = app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $notification->id);

        $this->assertSame($path, $result);
        $this->assertSame($path, $purchase->fresh()->results);
    }

    #[Test]
    public function pdf_manual_no_fetch_ni_overwrite_en_webhook_ni_sync(): void
    {
        Bus::fake();

        $purchase = $this->seedPurchase([
            'gda_order_id' => 'HD0L001533',
            'results' => 'results/manual-admin.pdf',
        ]);
        Storage::put('results/manual-admin.pdf', $this->samplePdfBinary('manual'));
        $notification = $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')->never();
        });

        $payload = $this->resultsWebhookPayload('HD0L001533');
        unset($payload['infogda_resultado_b64']);

        app(HandleResultsNotificationAction::class)->execute(
            $notification,
            $payload,
            [
                'purchase_id' => $purchase->id,
                'gda' => ['gda_order_id' => 'HD0L001533', 'gda_consecutivo' => $purchase->gda_consecutivo],
            ]
        );

        Bus::assertNotDispatched(SyncGdaResultPdfToStorageJob::class);

        $path = app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $notification->id);

        $this->assertSame('results/manual-admin.pdf', $path);
        $this->assertSame('results/manual-admin.pdf', $purchase->fresh()->results);
        $this->assertTrue(Storage::exists('results/manual-admin.pdf'));
    }

    #[Test]
    public function gda_timeout_durante_refresh_conserva_pdf_anterior(): void
    {
        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        $purchase = $this->seedPurchase();
        $pathA = $this->storeGdaPdf($purchase, 'A');
        $this->seedResultsNotificationRecord($purchase);

        $this->travelTo(Carbon::parse('2026-08-21 17:43:00'));
        $notification = $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andThrow(new \RuntimeException('cURL error 28: Connection timed out'));
        });

        try {
            app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $notification->id);
            $this->fail('Se esperaba un error de timeout de GDA');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        }

        $purchase->refresh();
        $this->assertSame($pathA, $purchase->results);
        $this->assertTrue(Storage::exists($pathA));
        $this->assertSame($this->samplePdfBinary('A'), Storage::get($pathA));
    }

    #[Test]
    public function gda_sin_resultados_durante_refresh_conserva_pdf_anterior(): void
    {
        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        $purchase = $this->seedPurchase();
        $pathA = $this->storeGdaPdf($purchase, 'A');
        $this->seedResultsNotificationRecord($purchase);

        $this->travelTo(Carbon::parse('2026-08-21 17:43:00'));
        $notification = $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) use ($purchase) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andThrow(new GdaResultsNotAvailableException(
                    orderId: (string) $purchase->gda_order_id,
                    gdaMessage: 'No contiene resultados.',
                ));
        });

        try {
            app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $notification->id);
            $this->fail('Se esperaba GdaResultsNotAvailableException');
        } catch (GdaResultsNotAvailableException) {
            // expected
        }

        $purchase->refresh();
        $this->assertSame($pathA, $purchase->results);
        $this->assertTrue(Storage::exists($pathA));
        $this->assertSame($this->samplePdfBinary('A'), Storage::get($pathA));
    }

    #[Test]
    public function pdf_identico_reutiliza_path_sin_romper_resultado(): void
    {
        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        $purchase = $this->seedPurchase();
        $binary = $this->samplePdfBinary('SAME');
        $path = sprintf(
            GdaResultsPdfStatus::GDA_STORED_PATH_PATTERN,
            $purchase->id,
            substr(hash('sha256', $binary), 0, 12)
        );
        Storage::put($path, $binary);
        touch(Storage::path($path), now()->timestamp);
        $purchase->update(['results' => $path]);
        $this->seedResultsNotificationRecord($purchase);

        $this->travelTo(Carbon::parse('2026-08-21 17:43:00'));
        $notification = $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) use ($binary) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn(['infogda_resultado_b64' => base64_encode($binary)]);
        });

        $result = app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $notification->id);

        $purchase->refresh();
        $this->assertSame($path, $result);
        $this->assertSame($path, $purchase->results);
        $this->assertTrue(Storage::exists($path));
        $this->assertSame($binary, Storage::get($path));
    }

    #[Test]
    public function multiples_notificaciones_terminan_con_el_pdf_mas_reciente(): void
    {
        $purchase = $this->seedPurchase();
        $gdaMock = $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class);
        $gdaMock->shouldReceive('__invoke')
            ->times(3)
            ->andReturn(
                ['infogda_resultado_b64' => $this->samplePdfBase64('A')],
                ['infogda_resultado_b64' => $this->samplePdfBase64('B')],
                ['infogda_resultado_b64' => $this->samplePdfBase64('C')],
            );

        $this->travelTo(Carbon::parse('2026-08-21 16:00:00'));
        $n1 = $this->seedResultsNotificationRecord($purchase);
        $pathA = app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $n1->id);
        touch(Storage::path($pathA), now()->timestamp);

        $this->travelTo(Carbon::parse('2026-08-21 17:00:00'));
        $n2 = $this->seedResultsNotificationRecord($purchase);
        $pathB = app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $n2->id);
        touch(Storage::path($pathB), now()->timestamp);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $n3 = $this->seedResultsNotificationRecord($purchase);
        $pathC = app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $n3->id);

        $purchase->refresh();
        $this->assertNotSame($pathA, $pathB);
        $this->assertNotSame($pathB, $pathC);
        $this->assertSame($pathC, $purchase->results);
        $this->assertTrue(Storage::exists($pathC));
        $this->assertSame($this->samplePdfBinary('C'), Storage::get($pathC));
    }

    #[Test]
    public function uniqueness_impide_dos_locks_simultaneos_por_compra(): void
    {
        $jobA = new SyncGdaResultPdfToStorageJob(2309, 1);
        $jobB = new SyncGdaResultPdfToStorageJob(2309, 2);
        $jobOther = new SyncGdaResultPdfToStorageJob(2310, 3);

        $this->assertSame($jobA->uniqueId(), $jobB->uniqueId());
        $this->assertNotSame($jobA->uniqueId(), $jobOther->uniqueId());
        $this->assertSame(3600, $jobA->uniqueFor);

        $lock = new UniqueLock(Cache::store());

        $this->assertTrue($lock->acquire($jobA));
        $this->assertFalse($lock->acquire($jobB));
        $this->assertTrue($lock->acquire($jobOther));

        $lock->release($jobA);
        $this->assertTrue($lock->acquire($jobB));
        $lock->release($jobB);
        $lock->release($jobOther);
    }

    #[Test]
    public function replay_pedido_2309_ultima_notif_actualiza_pdf(): void
    {
        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        $purchase = $this->seedPurchase([
            'gda_order_id' => 'HD0L001533',
            'gda_consecutivo' => 25043174,
        ]);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')
                ->twice()
                ->andReturn(
                    ['infogda_resultado_b64' => $this->samplePdfBase64('parcial')],
                    ['infogda_resultado_b64' => $this->samplePdfBase64('completo')],
                );
        });

        $first = $this->seedResultsNotificationRecord($purchase, ['gda_order_id' => 'HD0L001533']);
        $pathParcial = app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $first->id);
        touch(Storage::path($pathParcial), now()->timestamp);

        foreach (['biometria', 'vsg', 'pcr', 'perfil'] as $study) {
            $this->travelTo(now()->addHour());
            $this->seedResultsNotificationRecord($purchase, [
                'gda_order_id' => 'HD0L001533',
                'gda_external_id' => $study,
            ]);
        }

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $last = $this->seedResultsNotificationRecord($purchase, [
            'gda_order_id' => 'HD0L001533',
            'gda_external_id' => 'urocultivo',
        ]);

        $stale = GdaResultsPdfStatus::assessPurchase($purchase->fresh());
        $this->assertTrue($stale->isStale);
        $this->assertTrue($stale->isAutomaticOverwriteCandidate);
        $this->assertSame('gda_stale', $stale->freshnessStatus);

        Bus::fake();
        $payload = $this->resultsWebhookPayload('HD0L001533');
        unset($payload['infogda_resultado_b64']);

        app(HandleResultsNotificationAction::class)->execute(
            $last,
            $payload,
            [
                'purchase_id' => $purchase->id,
                'gda' => ['gda_order_id' => 'HD0L001533', 'gda_consecutivo' => 25043174],
            ]
        );

        Bus::assertDispatched(SyncGdaResultPdfToStorageJob::class);

        $pathCompleto = app(SyncGdaResultPdfToStorageAction::class)->execute($purchase->id, $last->id);

        $purchase->refresh();
        $this->assertNotSame($pathParcial, $pathCompleto);
        $this->assertSame($pathCompleto, $purchase->results);
        $this->assertTrue(Storage::exists($pathCompleto));
        $this->assertSame($this->samplePdfBinary('completo'), Storage::get($pathCompleto));
        $this->assertSame('gda_current', GdaResultsPdfStatus::assessPurchase($purchase->fresh())->freshnessStatus);
    }

    #[Test]
    public function force_refresh_admin_reutiliza_sync_y_sobrescribe_pdf_gda(): void
    {
        $this->travelTo(Carbon::parse('2026-08-21 16:38:00'));
        $purchase = $this->seedPurchase();
        $pathA = $this->storeGdaPdf($purchase, 'A');
        $notification = $this->seedResultsNotificationRecord($purchase);

        $this->travelTo(Carbon::parse('2026-08-24 12:17:00'));
        $this->seedResultsNotificationRecord($purchase);

        $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
            $mock->shouldReceive('__invoke')
                ->once()
                ->andReturn(['infogda_resultado_b64' => $this->samplePdfBase64('B')]);
        });

        $result = app(ResolveGdaResultsPdfAction::class)->forceRefresh($notification->fresh());

        $purchase->refresh();
        $this->assertTrue($result['forced']);
        $this->assertTrue($result['refreshed']);
        $this->assertNotSame($pathA, $purchase->results);
        $this->assertTrue(Storage::exists($purchase->results));
        $this->assertSame($this->samplePdfBinary('B'), Storage::get($purchase->results));
    }

    private function storeGdaPdf(LaboratoryPurchase $purchase, string $marker): string
    {
        $binary = $this->samplePdfBinary($marker);
        $path = sprintf(
            GdaResultsPdfStatus::GDA_STORED_PATH_PATTERN,
            $purchase->id,
            substr(hash('sha256', $binary), 0, 12)
        );
        Storage::put($path, $binary);
        touch(Storage::path($path), now()->timestamp);
        $purchase->update(['results' => $path]);

        return $path;
    }

    private function seedResultsNotificationRecord(LaboratoryPurchase $purchase, array $overrides = []): LaboratoryNotification
    {
        return LaboratoryNotification::query()->create(array_merge([
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
        ], $overrides));
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

    private function samplePdfBinary(string $marker = 'A'): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Marker ({$marker}) >>\nendobj\ntrailer\n<<>>\n%%EOF";
    }

    private function samplePdfBase64(string $marker = 'A'): string
    {
        return base64_encode($this->samplePdfBinary($marker));
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
